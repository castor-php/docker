<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasDirectory;
use Castor\Docker\Service\Behaviour\HasDockerfile;
use Castor\Docker\Service\Behaviour\HasHttpRouting;
use Castor\Docker\Service\Behaviour\HasSharedHomeDirectory;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\docker_compose;
use function Castor\Docker\docker_compose_run;
use function Castor\io;
use function Castor\Docker\docker_exit_code;
use function Castor\Docker\interactive_context;
use function Castor\context;
use function Castor\fingerprint;
use function Castor\hasher;

class PHPService implements ServiceInterface
{
    use HasDirectory;
    use HasDockerfile;
    use HasHttpRouting;
    use HasSharedHomeDirectory;
    use HasVersion;

    private ?DatabaseServiceInterface $databaseService = null;

    private ?MailpitService $mailerService = null;

    /** @var array<string, string> */
    private array $phpStanExtraDependencies = [];

    /**
     * @var array<string, array{command: string, restart: ?string}>
     */
    private array $workers = [];

    /** @var string[] */
    private array $extensions = ['apcu', 'bcmath', 'curl', 'iconv', 'intl', 'mbstring', 'pgsql', 'uuid', 'xml', 'zip'];

    private ?string $frankenPhpWorkerScript = null;
    private ?int $frankenPhpWorkerNum = null;
    private bool $frankenPhpWorkerWatch = true;

    protected PhpMode $mode = PhpMode::FrankenPhp;

    /**
     * NodeSource publishes one repository per major version, so a major is all
     * this can hold. It is the version the Dockerfile defaults to.
     */
    protected string $nodeVersion = '20.x';

    protected PackageManager $packageManager = PackageManager::Npm;

    protected string $phpStanVersion = '*';
    protected string $phpCsFixerVersion = '*';
    protected string $rectorVersion = '*';

    protected const MOUNT_POINT = '/var/www';

    /**
     * Where the QA tools are installed, and mounted in the
     * container that runs them.
     */
    protected const QA_TOOLS_MOUNT_POINT = '/castor-tools';

    /**
     * The configuration file names each QA tool discovers on its own, in the
     * directory it runs in.
     */
    protected const QA_TOOLS_CONFIGURATION_FILES = [
        'phpstan' => ['.phpstan.neon', 'phpstan.neon', '.phpstan.neon.dist', 'phpstan.neon.dist', '.phpstan.dist.neon', 'phpstan.dist.neon'],
        'php-cs-fixer' => ['.php-cs-fixer.php', '.php-cs-fixer.dist.php'],
        'rector' => ['rector.php'],
    ];

    /**
     * The application whose builder container this one uses, or false when no
     * builder container is generated at all. Null means "generate my own".
     */
    protected PHPService|false|null $sharedBuilder = null;

    public function __construct(
        protected string $name = 'app',
    ) {}

    protected function getDefaultVersion(): string
    {
        return '8.5';
    }

    protected function getDefaultDockerfile(): string
    {
        return match ($this->mode) {
            PhpMode::FrankenPhp => __DIR__ . '/../Resources/php/Dockerfile.frankenphp',
            PhpMode::Fpm => __DIR__ . '/../Resources/php/Dockerfile',
        };
    }

    public function withMode(PhpMode $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    /**
     * The Node.js the builder container installs.
     *
     * NodeSource publishes one repository per major version, named "node_22.x",
     * so only the major is used: "22", "22.x" and "v22.11.0" all name the same
     * one. An application sharing the builder of another one gets the version of
     * that one, since it is that image which carries node.
     */
    public function withNodeVersion(string $version): static
    {
        if (!preg_match('/^v?(\d+)/', $version, $matches)) {
            throw new \InvalidArgumentException(\sprintf(
                'The Node.js version of the "%s" application must start with a major version, got "%s".',
                $this->name,
                $version,
            ));
        }

        $this->nodeVersion = $matches[1] . '.x';

        return $this;
    }

    public function getNodeVersion(): string
    {
        return $this->nodeVersion;
    }

    /**
     * The package manager the builder image prepares.
     *
     * Corepack is enabled whichever one this is, so a project declaring a
     * "packageManager" field in its package.json gets that one regardless. This
     * decides what a project declaring nothing finds ready to run: npm comes
     * with node and needs nothing prepared, yarn is pinned to its current
     * stable, pnpm is activated through corepack.
     */
    public function withPackageManager(PackageManager $packageManager): static
    {
        $this->packageManager = $packageManager;

        return $this;
    }

    public function getPackageManager(): PackageManager
    {
        return $this->packageManager;
    }

    public function withPhpStanVersion(string $version): static
    {
        $this->phpStanVersion = $version;

        return $this;
    }

    public function withPhpCsFixerVersion(string $version): static
    {
        $this->phpCsFixerVersion = $version;

        return $this;
    }

    public function withRectorVersion(string $version): static
    {
        $this->rectorVersion = $version;

        return $this;
    }

    /**
     * Run $command in a container of its own, next to the application.
     *
     * $restart is the compose restart policy of that container — "on-failure",
     * "unless-stopped", "always", "no". There is none by default, which means a
     * worker that exits stays down until the next "docker:up".
     *
     * A consumer given "--time-limit" or "--memory-limit" exits *successfully*
     * when it reaches one, so bringing it back needs "unless-stopped" rather
     * than "on-failure" — the latter only reacts to a non-zero exit. Prefer
     * "unless-stopped" over "always": it honours "castor {app}:worker:stop"
     * instead of fighting it.
     */
    public function addWorker(string $name, string $command, ?string $restart = null): static
    {
        $this->workers[$name] = ['command' => $command, 'restart' => $restart];

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Run the builder tasks of this application in the builder container of
     * another one, instead of generating an identical one.
     *
     * Three applications of the same monorepo otherwise produce three "-builder"
     * containers built from the same sources. The shared builder has to mount a
     * directory containing this application — the repository root — and each
     * application names its own sub-directory with withWorkingDirectory().
     */
    public function withSharedBuilder(self $service): static
    {
        $this->sharedBuilder = $service;

        return $this;
    }

    /**
     * Generate no builder container: the builder tasks run in the application
     * container itself, which is only enough if it carries the tooling.
     */
    public function withoutBuilder(): static
    {
        $this->sharedBuilder = false;

        return $this;
    }

    /**
     * The compose service the builder tasks — composer, the console, the cache
     * commands — run in.
     */
    public function getBuilderServiceName(): string
    {
        if ($this->sharedBuilder instanceof self) {
            return $this->sharedBuilder->getBuilderServiceName();
        }

        if (false === $this->sharedBuilder) {
            return $this->name;
        }

        return $this->name . '-builder';
    }

    public function withDatabaseService(DatabaseServiceInterface $databaseService): static
    {
        $this->databaseService = $databaseService;
        return $this;
    }

    public function withMailerService(MailpitService $mailerService): static
    {
        $this->mailerService = $mailerService;
        return $this;
    }

    public function addPhpStanExtraDependency(string $package, string $version): static
    {
        $this->phpStanExtraDependencies[$package] = $version;
        return $this;
    }

    public function addExtension(string $extension): static
    {
        $this->extensions[] = $extension;
        return $this;
    }

    /**
     * Enables FrankenPHP worker mode (PhpMode::FrankenPhp only): the given
     * script is booted once and kept in memory to handle every request,
     * instead of being re-interpreted on each request. Your application
     * needs a compatible runtime (e.g. runtime/frankenphp-symfony) to loop
     * over incoming requests from that script.
     */
    public function withFrankenPhpWorkerMode(string $script = 'public/index.php', ?int $num = null, bool $watch = true): static
    {
        $this->frankenPhpWorkerScript = $script;
        $this->frankenPhpWorkerNum = $num;
        $this->frankenPhpWorkerWatch = $watch;
        return $this;
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $userId = $context->data['user_id'] ?? 1000;

        $appService = $builder
            ->service($this->name)
                ->build(__DIR__ . '/../Resources/php')
                    ->useTwigFrontend($context)
                    ->dockerfile($this->getDockerfile())
                    ->target('frontend')
                    ->withRegistryCache($this->name)
                    ->arg('php_version', $this->getVersion())
                    ->arg('php_extensions', json_encode(array_values($this->extensions), \JSON_THROW_ON_ERROR))
                ->end()
                ->user("{$userId}:{$userId}")
                ->volume($this->getDirectory(), static::MOUNT_POINT, 'cached')
                ->volume($this->getSharedHomeDirectory(), '/home/app', 'cached')
                ->profile('default')
        ;

        $appRoot = $this->getContainerWorkingDirectory(static::MOUNT_POINT);

        if ('.' !== $this->workingDirectory) {
            // The document root of the frontend is baked into the image
            // configuration, so it has to follow: mounting the repository root
            // and pointing the application at a sub-directory would otherwise
            // serve /var/www/public, which does not exist.
            $appService->workingDir($appRoot);
            $appService->build()->arg('app_root', $appRoot);
        }

        if ($this->mode === PhpMode::FrankenPhp && $this->frankenPhpWorkerScript !== null) {
            $appService->build()
                ->arg('frankenphp_worker_file', $appRoot . '/' . ltrim($this->frankenPhpWorkerScript, '/'))
                ->arg('frankenphp_worker_watch', $this->frankenPhpWorkerWatch ? 'true' : 'false')
            ;

            if ($this->frankenPhpWorkerNum !== null) {
                $appService->build()->arg('frankenphp_worker_num', (string) $this->frankenPhpWorkerNum);
            }
        }

        $buildBuilder = $builder->service($this->name)->build();

        // Skipped when the builder is shared with another application, or when
        // the project opted out of it: getBuilderServiceName() then points the
        // tasks somewhere else.
        $builderService = null;

        if (null === $this->sharedBuilder) {
            $builderService = $builder
                ->service($this->name . '-builder')
                    ->build($buildBuilder)
                        ->target('builder')
                        ->withRegistryCache($this->name . '-builder')
                        ->arg('node_version', $this->nodeVersion)
                        ->arg('package_manager', $this->packageManager->value)
                    ->end()
                    ->user("{$userId}:{$userId}")
                    ->init(true)
                    ->volume($this->getDirectory(), static::MOUNT_POINT, 'cached')
                    ->volume($this->getSharedHomeDirectory(), '/home/app', 'cached')
                    ->volume($this->getQaToolsDirectory($context), static::QA_TOOLS_MOUNT_POINT, 'cached')
                    ->profile('builder')
            ;
        } elseif (false === $this->sharedBuilder) {
            // No builder container at all: the QA tasks fall back to the
            // application one, which then needs the tools too.
            $appService->volume($this->getQaToolsDirectory($context), static::QA_TOOLS_MOUNT_POINT, 'cached');
        }

        $this->applyHttpRouting($appService);

        if ($this->databaseService) {
            $appService
                ->dependsOn($this->databaseService->getName(), [
                    'condition' => 'service_healthy',
                ])
                ->environment('DATABASE_URL', $this->databaseService->getDatabaseURL())
            ;

            $builderService
                ?->dependsOn($this->databaseService->getName(), [
                    'condition' => 'service_healthy',
                ])
                ->environment('DATABASE_URL', $this->databaseService->getDatabaseURL())
            ;
        }

        if ($this->mailerService) {
            $appService
                ->dependsOn($this->mailerService->getName(), [
                    'condition' => 'service_started',
                ])
                ->environment('MAILER_DSN', $this->mailerService->getMailerDSN())
            ;

            $builderService
                ?->dependsOn($this->mailerService->getName(), [
                    'condition' => 'service_started',
                ])
                ->environment('MAILER_DSN', $this->mailerService->getMailerDSN())
            ;
        }

        foreach ($this->workers as $workerName => $worker) {
            $workerService = $builder
                ->service($this->getWorkerServiceName($workerName))
                    ->build($buildBuilder)
                        ->target('worker')
                        ->withRegistryCache($this->name . '-worker')
                    ->end()
                    ->user("{$userId}:{$userId}")
                    ->volume($this->getDirectory(), static::MOUNT_POINT, 'cached')
                    ->volume($this->getSharedHomeDirectory(), '/home/app', 'cached')
                    ->command($worker['command'])
                    ->profile('default')
            ;

            if (null !== $worker['restart']) {
                $workerService->restart($worker['restart']);
            }

            if ('.' !== $this->workingDirectory) {
                $workerService->workingDir($this->getContainerWorkingDirectory(static::MOUNT_POINT));
            }

            if ($this->databaseService) {
                $workerService
                    ->dependsOn($this->databaseService->getName(), [
                        'condition' => 'service_healthy',
                    ])
                    ->environment('DATABASE_URL', $this->databaseService->getDatabaseURL())
                ;
            }

            if ($this->mailerService) {
                $workerService
                    ->dependsOn($this->mailerService->getName(), [
                        'condition' => 'service_started',
                    ])
                    ->environment('MAILER_DSN', $this->mailerService->getMailerDSN())
                ;
            }
        }

        return $builder;
    }

    // This method return a list of tasks associated to this services
    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('bash', $this->name, 'Run a bash shell inside the PHP container'),
            'function' => function (#[AsRawTokens] array $args): void {
                if (!$args) {
                    $this->runInBuilder('bash', c: interactive_context());
                } else {
                    $this->runInBuilder(implode(' ', $args), c: interactive_context());
                }
            },
        ];

        yield [
            'task' => new AsTask('install', $this->name, 'Install PHP dependencies using Composer'),
            'function' => function (): void {
                $this->runInBuilder('composer install');
            },
        ];

        yield [
            'task' => new AsTask('composer', $this->name, 'Run composer for this service'),
            'function' => function (#[AsRawTokens] array $args): void {
                $this->runInBuilder('composer ' . implode(' ', $args));
            },
        ];

        yield [
            'task' => new AsTask('phpstan', $this->name . ':qa', 'Runs PHPStan'),
            'function' => function (#[AsRawTokens] array $args): int {
                io()->section('Running PHPStan...');

                /** @var list<string> $args */
                return $this->runQaTool(
                    'phpstan',
                    ['phpstan/phpstan' => $this->phpStanVersion, ...$this->phpStanExtraDependencies],
                    $args ?: $this->getDefaultQaToolArguments('phpstan', context(), ['analyze']),
                );
            },
        ];

        yield [
            'task' => new AsTask('cs', $this->name . ':qa', 'Fixes Coding Style'),
            'function' => function (#[AsRawTokens] array $args): int {
                io()->section('Running PHP CS Fixer...');

                /** @var list<string> $args */
                return $this->runQaTool(
                    'php-cs-fixer',
                    ['friendsofphp/php-cs-fixer' => $this->phpCsFixerVersion],
                    $args ?: $this->getDefaultQaToolArguments('php-cs-fixer', context(), ['fix'], '/src'),
                );
            },
        ];

        yield [
            'task' => new AsTask('rector', $this->name . ':qa', 'Updates and refactors code using Rector'),
            'function' => function (#[AsRawTokens] array $args): int {
                io()->section('Running Rector...');

                /** @var list<string> $args */
                return $this->runQaTool(
                    'rector',
                    ['rector/rector' => $this->rectorVersion],
                    $args ?: $this->getDefaultQaToolArguments('rector', context(), [], '/src'),
                );
            },
        ];

        yield from $this->getWorkerTasks();
    }

    /**
     * Install a QA tool, then run it — both inside the container, so the
     * analysis sees the PHP version, the extensions and the vendor/ the
     * application actually runs on rather than whichever PHP happens to run
     * castor.
     *
     * @param array<string, string> $dependencies the composer requirements of the tool
     * @param list<string>          $arguments
     */
    protected function runQaTool(string $tool, array $dependencies, array $arguments): int
    {
        $directory = $this->getQaToolInstallation($tool);

        $this->installQaTool($directory, $dependencies);

        $binary = static::QA_TOOLS_MOUNT_POINT . '/' . $directory . '/vendor/bin/' . $tool;

        return docker_exit_code(
            trim($binary . ' ' . implode(' ', $arguments)),
            $this->getBuilderServiceName(),
            workDir: $this->getBuilderWorkingDirectory(),
        );
    }

    /**
     * Resolving the tool with the composer of the container rather than the one
     * castor embeds is what makes the installation match the PHP the tool runs
     * on: composer picks versions against the platform it runs on, so a host on
     * PHP 8.5 installing for a container on 8.1 gets a tool the container
     * cannot run — and the reverse silently analyses with a tool older than the
     * application deserves. The container is also where the extensions the
     * application declares are, which some tool dependencies require.
     *
     * The directory is on the host, mounted in the container: an installation
     * survives the containers, and the composer cache of the shared home is
     * reused across the tools.
     *
     * @param array<string, string> $dependencies
     */
    protected function installQaTool(string $directory, array $dependencies): void
    {
        $path = $this->getQaToolsDirectory(context()) . '/' . $directory;
        $manifest = $this->getQaToolManifest($directory, $dependencies);

        // The PHP version takes part in the fingerprint because it takes part
        // in the resolution: bumping the application to a version the installed
        // tool does not support has to reinstall it.
        fingerprint(
            callback: function () use ($path, $manifest, $directory): void {
                if (!is_dir($path)) {
                    mkdir($path, 0o777, true);
                }

                file_put_contents($path . '/composer.json', $manifest);

                io()->comment('Installing/Updating ' . $directory . '...');

                $this->runInBuilder($this->getQaToolInstallCommand($directory));
            },
            id: 'docker-tools-' . $directory,
            fingerprint: hasher()->write($manifest)->write($this->getVersion())->finish(),
            force: !is_file($path . '/composer.json'),
        );
    }

    /**
     * The composer.json of the installation. It pins nothing beyond what the
     * task asks for: the resolution is the container's to make.
     *
     * @param array<string, string> $dependencies
     */
    protected function getQaToolManifest(string $directory, array $dependencies): string
    {
        return json_encode([
            'name' => 'tools/' . $directory,
            'require' => $dependencies,
            'config' => [
                // A tool is a leaf: its own plugins are the only ones that can
                // run here, and none of them is worth an interactive prompt.
                'allow-plugins' => array_fill_keys(array_keys($dependencies), true),
            ],
        ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * Run in the builder container, so --working-dir names the mount point and
     * not the host directory behind it.
     */
    protected function getQaToolInstallCommand(string $directory): string
    {
        return \sprintf(
            'composer update --working-dir=%s --no-interaction',
            static::QA_TOOLS_MOUNT_POINT . '/' . $directory,
        );
    }

    /**
     * What a QA tool analyses when the task was given no arguments of its own.
     *
     * None of these tools treats a path on the command line as a restriction of
     * the paths its configuration file declares: it *replaces* them. PHPStan
     * only falls back to `parameters.paths` when the command line names no path
     * at all, PHP CS Fixer ignores the finder of its config unless asked for
     * `--path-mode=intersection`, and Rector does the same with `withPaths()`.
     *
     * Naming a path by default is therefore wrong wherever the application
     * configures the tool: it would analyse `vendor/` and `var/` along with the
     * sources of an application whose phpstan.neon says `paths: [src]`, and skip
     * the `tests/` and `config/` a php-cs-fixer finder covers — while still
     * reporting the configuration file as used, because everything else in it
     * does apply.
     *
     * So the default names no path when the application ships a configuration
     * the tool discovers on its own, and only falls back to the application
     * directory — `$suffix` below it, for the tools whose out-of-the-box
     * behaviour is to fix rather than to report — for one that ships none.
     *
     * @param list<string> $command the sub-command the tool needs, if any
     *
     * @return list<string>
     */
    protected function getDefaultQaToolArguments(string $tool, Context $context, array $command, string $suffix = ''): array
    {
        if ($this->hasQaToolConfiguration($tool, $context)) {
            return $command;
        }

        return [...$command, $this->getContainerWorkingDirectory(static::MOUNT_POINT) . $suffix];
    }

    /**
     * Whether the application ships a configuration file the tool discovers on
     * its own, in the directory it runs in.
     */
    protected function hasQaToolConfiguration(string $tool, Context $context): bool
    {
        $directory = $this->getAbsoluteHostWorkingDirectory($context);

        foreach (static::QA_TOOLS_CONFIGURATION_FILES[$tool] ?? [] as $name) {
            if (is_file($directory . '/' . $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The directory a tool is installed in: one per application, per tool.
     *
     * A single directory per tool would be wrong as soon as a repository holds
     * two applications — pinning PHPStan 1 on one and 2 on the other would make
     * every run reinstall over the previous one, and leave whichever ran last
     * in place. Naming it after the application keeps them apart, and keeps the
     * name stable: bumping a version reinstalls in place instead of leaving the
     * previous installation behind forever.
     */
    protected function getQaToolInstallation(string $tool): string
    {
        return $this->name . '-' . $tool;
    }

    /**
     * The host directory holding the QA tools castor installs, mounted in the
     * container that runs them.
     */
    protected function getQaToolsDirectory(Context $context): string
    {
        return $context->workingDirectory . '/.castor/vendor/.tools';
    }

    /**
     * The tasks driving the background workers, only when there are any.
     *
     * @return iterable<array{task: AsTask, function: \Closure}>
     */
    protected function getWorkerTasks(): iterable
    {
        if (!$this->workers) {
            return;
        }

        $names = implode(', ', array_keys($this->workers));

        yield [
            'task' => new AsTask('restart', $this->name . ':worker', 'Restart the background workers, or the one named (' . $names . ')'),
            'function' => function (
                #[AsArgument(description: 'The worker to restart, all of them when omitted', autocomplete: 'Castor\Docker\autocomplete_worker_name')]
                ?string $worker = null,
            ): void {
                // "restart" also starts a worker that was stopped, so there is
                // no separate start task to remember.
                docker_compose(['restart', ...$this->resolveWorkerServices($worker)]);
            },
        ];

        yield [
            'task' => new AsTask('stop', $this->name . ':worker', 'Stop the background workers, or the one named (' . $names . ')'),
            'function' => function (
                #[AsArgument(description: 'The worker to stop, all of them when omitted', autocomplete: 'Castor\Docker\autocomplete_worker_name')]
                ?string $worker = null,
            ): void {
                docker_compose(['stop', ...$this->resolveWorkerServices($worker)]);
            },
        ];
    }

    /**
     * The compose services behind a worker name, or behind all of them.
     *
     * @return list<string>
     */
    protected function resolveWorkerServices(?string $worker = null): array
    {
        if (null === $worker) {
            return array_map($this->getWorkerServiceName(...), array_keys($this->workers));
        }

        if (!isset($this->workers[$worker])) {
            throw new \InvalidArgumentException(\sprintf(
                'The "%s" application has no worker named "%s". Declared: %s.',
                $this->name,
                $worker,
                implode(', ', array_keys($this->workers)) ?: '(none)',
            ));
        }

        return [$this->getWorkerServiceName($worker)];
    }

    /**
     * The worker names declared with addWorker(), as the tasks take them.
     *
     * @return list<string>
     */
    public function getWorkerNames(): array
    {
        return array_keys($this->workers);
    }

    public function getWorkerServiceName(string $worker): string
    {
        return $this->name . '-worker-' . $worker;
    }

    /**
     * Run a command in the builder container of this application — which may be
     * the one of another application (withSharedBuilder()), in the
     * sub-directory this application lives in.
     */
    protected function runInBuilder(string $command, ?Context $c = null): void
    {
        docker_compose_run(
            $command,
            service: $this->getBuilderServiceName(),
            c: $c,
            workDir: $this->getBuilderWorkingDirectory(),
        );
    }

    /**
     * @see RustService::getTaskWorkingDirectory()
     */
    protected function getBuilderWorkingDirectory(): ?string
    {
        if ('.' === $this->workingDirectory) {
            return null;
        }

        return $this->getContainerWorkingDirectory(static::MOUNT_POINT);
    }
}
