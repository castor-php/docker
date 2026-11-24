<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasDirectory;
use Castor\Docker\Service\Behaviour\HasDockerfile;
use Castor\Docker\Service\Behaviour\HasEnvironment;
use Castor\Docker\Service\Behaviour\HasHttpRouting;
use Castor\Docker\Service\Behaviour\HasLinks;
use Castor\Docker\Service\Behaviour\HasSharedHomeDirectory;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\Builder\ServiceBuilder;

use function Castor\Docker\docker_compose;
use function Castor\Docker\docker_compose_run;
use function Castor\io;
use function Castor\Docker\interactive_context;
use function Castor\context;

class PHPService implements HttpRoutedServiceInterface
{
    use HasDirectory;
    use HasDockerfile;
    use HasEnvironment;
    use HasHttpRouting;
    use HasLinks;
    use HasSharedHomeDirectory;
    use HasVersion;

    /** @var array<string, string> */
    private array $phpStanExtraDependencies = [];

    /**
     * @var array<string, array{command: string, restart: ?string}>
     */
    private array $workers = [];

    /**
     * Keyed by name, so asking twice installs once.
     *
     * @var array<string, array{installer: ExtensionInstaller, dependencies: list<string>}>
     */
    private array $extensions = [];

    private ?string $frankenPhpWorkerScript = null;
    private ?int $frankenPhpWorkerNum = null;
    private bool $frankenPhpWorkerWatch = true;

    protected PhpMode $mode = PhpMode::FrankenPhp;

    /**
     * NodeSource publishes one repository per major version, so a major is all
     * this can hold. It is the version the Dockerfile defaults to.
     */
    protected string $nodeVersion = '24.x';

    protected PackageManager $packageManager = PackageManager::Npm;

    /** The PIE release the images install, both to build extensions and to run in the builder. */
    protected string $pieVersion = '1.0.0';

    protected bool $sudo = false;

    /** @var array<string, string> */
    protected array $cliPhpIni = [];

    /** @var array<string, string> */
    protected array $webPhpIni = [];

    protected string $phpStanVersion = '*';
    protected string $phpCsFixerVersion = '*';
    protected string $rectorVersion = '*';

    protected const MOUNT_POINT = '/var/www';

    /**
     * The application whose builder container this one uses, false when none is
     * generated at all, null to generate its own.
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

    public function getMode(): PhpMode
    {
        return $this->mode;
    }

    /**
     * NodeSource publishes one repository per major version, named "node_22.x",
     * so only the major is used: "22", "22.x" and "v22.11.0" name the same one.
     * An application sharing a builder gets the version of that image.
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
     * Corepack is enabled whichever one this is, so a "packageManager" field in
     * package.json still wins. This only decides what a project declaring
     * nothing finds ready to run.
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

    /**
     * Install a passwordless sudo in the builder container.
     *
     * A two line script around gosu, so anyone reaching the container becomes
     * root in it: convenient while developing, a hole anywhere else. Hence off
     * by default.
     */
    public function withSudo(bool $sudo = true): static
    {
        $this->sudo = $sudo;

        return $this;
    }

    public function hasSudo(): bool
    {
        return $this->sudo;
    }

    /**
     * Mounted into the containers rather than built into the image, so changing
     * one is a "docker:up" away instead of a rebuild.
     *
     * The scope says which PHP they reach. Calling this again merges, a
     * directive given twice keeps the value given last.
     *
     * @param array<string, scalar> $directives
     */
    public function withPhpIni(array $directives, PhpIniScope $scope = PhpIniScope::All): static
    {
        $normalized = array_map(self::normalizeIniValue(...), $directives);

        if (PhpIniScope::Web !== $scope) {
            $this->cliPhpIni = [...$this->cliPhpIni, ...$normalized];
        }

        if (PhpIniScope::Cli !== $scope) {
            $this->webPhpIni = [...$this->webPhpIni, ...$normalized];
        }

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getPhpIni(PhpIniScope $scope): array
    {
        return match ($scope) {
            PhpIniScope::Cli => $this->cliPhpIni,
            PhpIniScope::Web => $this->webPhpIni,
            PhpIniScope::All => [...$this->cliPhpIni, ...$this->webPhpIni],
        };
    }

    /**
     * php.ini reads "1" and "0" for booleans, and would take the string "true"
     * as an unknown value.
     */
    private static function normalizeIniValue(string|int|float|bool $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'On' : 'Off';
        }

        return (string) $value;
    }

    /**
     * @param array<string, string> $directives
     */
    protected function renderPhpIni(array $directives): string
    {
        $lines = ['; This file is generated by Castor. Do not edit it manually.'];

        foreach ($directives as $directive => $value) {
            $lines[] = $directive . ' = ' . $value;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The one thing the two ways of serving disagree on: the official FrankenPHP
     * image reads one /usr/local/etc/php/conf.d whatever the SAPI, the Debian
     * packages of PhpMode::Fpm read a directory per SAPI. 99 puts the file after
     * everything the image ships, so a project has the last word.
     *
     * Both scopes landing on the same FrankenPHP path is no conflict: they are
     * mounted in different containers.
     */
    protected function getPhpIniPath(PhpIniScope $scope): string
    {
        if (PhpMode::FrankenPhp === $this->mode) {
            return '/usr/local/etc/php/conf.d/99-castor.ini';
        }

        $sapi = PhpIniScope::Web === $scope ? 'fpm' : 'cli';

        return \sprintf('/etc/php/%s/%s/conf.d/99-castor.ini', $this->getVersion(), $sapi);
    }

    protected function getPhpIniConfigName(PhpIniScope $scope): string
    {
        return $this->name . '-php-ini-' . $scope->value;
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
     * $restart is the compose restart policy of that container, none by
     * default: a worker that exits stays down until the next "docker:up". A
     * consumer given "--time-limit" exits *successfully*, so bringing it back
     * needs "unless-stopped" rather than "on-failure" — and "unless-stopped"
     * over "always", which fights "castor {app}:worker:stop".
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
     * The shared builder has to mount a directory containing this application —
     * the repository root — and each application names its own sub-directory
     * with withWorkingDirectory().
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

    public function addPhpStanExtraDependency(string $package, string $version): static
    {
        $this->phpStanExtraDependencies[$package] = $version;
        return $this;
    }

    /**
     * Adds an extension to every container of the application: it is installed
     * once, in the stage they are all built on.
     *
     * The name reaches the installer as written, so a constraint it understands
     * pins a version: "xdebug/xdebug:^3.5" with ExtensionInstaller::Pie, or
     * "redis-6.0.2" in PhpMode::FrankenPhp.
     *
     * $dependencies are the Debian packages the extension needs on top of what
     * the installer pulls by itself, usually the "-dev" ones PIE compiles
     * against. They stay in the image.
     *
     * @param string             $extension    named after the installer, see getExtensions()
     * @param list<string>       $dependencies Debian packages to install alongside
     * @param ExtensionInstaller $installer    ExtensionInstaller::Pie to build it from its sources instead
     */
    public function addExtension(string $extension, array $dependencies = [], ExtensionInstaller $installer = ExtensionInstaller::Mode): static
    {
        if (ExtensionInstaller::Pie === $installer && !preg_match('{^[^/\s:]+/[^/\s:]+(:\S+)?$}', $extension)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a PIE package: PIE installs a Composer package, named "vendor/name", optionally followed by ":" and a version constraint.', $extension));
        }

        $this->extensions[$extension] = [
            'installer' => $installer,
            'dependencies' => $dependencies,
        ];

        return $this;
    }

    /**
     * The extensions the installer of the mode puts in the image, named the way
     * it names them: the Debian packages of sury for PhpMode::Fpm, the
     * install-php-extensions catalogue for PhpMode::FrankenPhp. The
     * ExtensionInstaller::Pie ones are in getPieExtensions() instead.
     *
     * @return list<string>
     */
    public function getExtensions(): array
    {
        $extensions = $this->getDefaultExtensions();

        foreach ($this->extensions as $extension => $options) {
            if (ExtensionInstaller::Mode === $options['installer']) {
                $extensions[] = $extension;
            }
        }

        return array_values(array_unique($extensions));
    }

    /**
     * One layer of the image per entry, so adding an extension does not rebuild
     * the others.
     *
     * @return list<array{name: string, dependencies: list<string>}>
     */
    public function getPieExtensions(): array
    {
        $extensions = [];

        foreach ($this->extensions as $extension => $options) {
            if (ExtensionInstaller::Pie === $options['installer']) {
                $extensions[] = ['name' => $extension, 'dependencies' => $options['dependencies']];
            }
        }

        return $extensions;
    }

    /**
     * The PIE ones keep theirs in getPieExtensions(), next to the extension
     * that needs them.
     *
     * @return list<string>
     */
    public function getExtensionDependencies(): array
    {
        $dependencies = [];

        foreach ($this->extensions as $options) {
            if (ExtensionInstaller::Mode === $options['installer']) {
                $dependencies = [...$dependencies, ...$options['dependencies']];
            }
        }

        return array_values(array_unique($dependencies));
    }

    /**
     * What an application asking for nothing gets. The two catalogues do not
     * name things the same — a sury "php-pgsql" ships pdo_pgsql too, where
     * install-php-extensions names one module at a time — so the lists differ.
     *
     * @return list<string>
     */
    protected function getDefaultExtensions(): array
    {
        return match ($this->mode) {
            PhpMode::FrankenPhp => ['apcu', 'bcmath', 'curl', 'iconv', 'intl', 'mbstring', 'pdo_pgsql', 'pgsql', 'uuid', 'xml', 'zip'],
            PhpMode::Fpm => ['apcu', 'bcmath', 'curl', 'iconv', 'intl', 'mbstring', 'pgsql', 'uuid', 'xml', 'zip'],
        };
    }

    /**
     * Builds the extensions of getPieExtensions(), and is the "pie" of the
     * builder container.
     */
    public function withPieVersion(string $version): static
    {
        $this->pieVersion = $version;

        return $this;
    }

    public function getPieVersion(): string
    {
        return $this->pieVersion;
    }

    /**
     * Enables FrankenPHP worker mode (PhpMode::FrankenPhp only): the script is
     * booted once and kept in memory to handle every request. It needs a
     * compatible runtime, e.g. runtime/frankenphp-symfony, to loop over them.
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
        $appService = $builder
            ->service($this->name)
                ->build(__DIR__ . '/../Resources/php')
                    ->useTwigFrontend($context)
                    ->dockerfile($this->getDockerfile())
                    ->target('frontend')
                    ->withRegistryCache($this->name)
                    ->arg('php_version', $this->getVersion())
                    ->arg('php_extensions', json_encode($this->getExtensions(), \JSON_THROW_ON_ERROR))
                    ->arg('pie_version', $this->getPieVersion())
                ->end()
                ->profile('default')
        ;

        $this->configureApplicationContainer($appService, $context, PhpIniScope::Web);

        foreach ([PhpIniScope::Cli, PhpIniScope::Web] as $scope) {
            if ($this->getPhpIni($scope)) {
                $builder->config($this->getPhpIniConfigName($scope), $this->renderPhpIni($this->getPhpIni($scope)));
            }
        }

        // The templates default both to an empty list, and everything a PIE
        // build needs hangs off that test.
        if ($this->getPieExtensions()) {
            $appService->build()->arg('pie_extensions', json_encode($this->getPieExtensions(), \JSON_THROW_ON_ERROR));
        }

        if ($this->getExtensionDependencies()) {
            $appService->build()->arg('php_extension_dependencies', json_encode($this->getExtensionDependencies(), \JSON_THROW_ON_ERROR));
        }

        $appRoot = $this->getContainerWorkingDirectory(static::MOUNT_POINT);

        if ('.' !== $this->workingDirectory) {
            // The document root is baked into the image configuration, so it
            // has to follow: an application in a sub-directory of the mounted
            // repository would otherwise serve a /var/www/public that is not
            // there.
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

        foreach ($this->links as $service) {
            // FrankenPHP is Caddy with the Mercure module, so a hub whose only
            // subscriber is this application is a Caddyfile directive rather
            // than a container.
            if ($service instanceof MercureService && $service->getHost() === $this) {
                $appService->build()->arg('mercure', 'true');
                // Read by the Caddyfile at startup, not baked in: a new domain
                // is a "docker:up" away.
                $appService->environment('MERCURE_CORS_ORIGINS', implode(' ', $service->getCorsOrigins($context)));
            }
        }

        $buildBuilder = $builder->service($this->name)->build();

        // A shared or disabled builder makes getBuilderServiceName() point the
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
            ;

            if ($this->sudo) {
                // The template tests it with "is defined", which the string
                // "false" — true to Twig — would fool.
                $builderService->build()->arg('sudo', 'true');
            }

            $this->configureApplicationContainer($builderService, $context, PhpIniScope::Cli)
                ->init(true)
                ->volume(QaToolRunner::getToolsDirectory($context), QaToolRunner::MOUNT_POINT, 'cached')
                ->profile('builder')
            ;
        } elseif (false === $this->sharedBuilder) {
            // Without a builder container the QA tasks fall back to the
            // application one, which then needs the tools too.
            $appService->volume(QaToolRunner::getToolsDirectory($context), QaToolRunner::MOUNT_POINT, 'cached');
        }

        $this->applyHttpRouting($appService);
        $this->applyLinks($context, $appService, $builderService);
        $this->applyEnvironment($appService, $builderService);

        foreach ($this->workers as $workerName => $worker) {
            $workerService = $builder
                ->service($this->getWorkerServiceName($workerName))
                    ->build($buildBuilder)
                        ->target('worker')
                        ->withRegistryCache($this->name . '-worker')
                    ->end()
                    ->command($worker['command'])
                    ->profile('default')
            ;

            $this->configureApplicationContainer($workerService, $context, PhpIniScope::Cli);

            if (null !== $worker['restart']) {
                $workerService->restart($worker['restart']);
            }

            if ('.' !== $this->workingDirectory) {
                $workerService->workingDir($this->getContainerWorkingDirectory(static::MOUNT_POINT));
            }

            $this->applyLinks($context, $workerService);
            $this->applyEnvironment($workerService);
        }

        return $builder;
    }

    /**
     * What every container running the application code shares: the host user,
     * so the files it writes stay the user's own, the sources, the home holding
     * the shared caches, and the php.ini of the PHP it runs.
     */
    protected function configureApplicationContainer(ServiceBuilder $service, Context $context, PhpIniScope $phpIniScope): ServiceBuilder
    {
        $userId = $context->data['user_id'] ?? 1000;

        $service
            ->user("{$userId}:{$userId}")
            ->volume($this->getDirectory(), static::MOUNT_POINT, 'cached')
            ->volume($this->getSharedHomeDirectory($context), '/home/app', 'cached')
        ;

        if ($this->getPhpIni($phpIniScope)) {
            $service->config(
                $this->getPhpIniConfigName($phpIniScope),
                $this->getPhpIniPath($phpIniScope),
                recreateOnChange: true,
            );
        }

        return $service;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('bash', $this->name, 'Run a bash shell inside the PHP container'),
            'function' => function (#[AsRawTokens] array $args): void {
                if (!$args) {
                    $this->runInBuilder(['bash'], c: interactive_context());
                } else {
                    $this->runInBuilder($args, c: interactive_context());
                }
            },
        ];

        yield [
            'task' => new AsTask('install', $this->name, 'Install PHP dependencies using Composer'),
            'function' => function (): void {
                $this->runInBuilder(['composer', 'install']);
            },
        ];

        yield [
            'task' => new AsTask('composer', $this->name, 'Run composer for this service'),
            'function' => function (#[AsRawTokens] array $args): void {
                $this->runInBuilder(['composer', ...$args]);
            },
        ];

        yield [
            'task' => new AsTask('phpstan', $this->name . ':qa', 'Run PHPStan'),
            'function' => function (#[AsRawTokens] array $args): int {
                io()->section('Running PHPStan...');
                $runner = $this->getQaToolRunner(context());

                /** @var list<string> $args */
                return $runner->run(
                    'phpstan',
                    ['phpstan/phpstan' => $this->phpStanVersion, ...$this->phpStanExtraDependencies],
                    $args ?: $runner->getDefaultArguments('phpstan', ['analyze']),
                );
            },
        ];

        yield [
            'task' => new AsTask('cs', $this->name . ':qa', 'Fix the coding style'),
            'function' => function (#[AsRawTokens] array $args): int {
                io()->section('Running PHP CS Fixer...');
                $runner = $this->getQaToolRunner(context());

                /** @var list<string> $args */
                return $runner->run(
                    'php-cs-fixer',
                    ['friendsofphp/php-cs-fixer' => $this->phpCsFixerVersion],
                    $args ?: $runner->getDefaultArguments('php-cs-fixer', ['fix'], '/src'),
                );
            },
        ];

        yield [
            'task' => new AsTask('rector', $this->name . ':qa', 'Update and refactor the code with Rector'),
            'function' => function (#[AsRawTokens] array $args): int {
                io()->section('Running Rector...');
                $runner = $this->getQaToolRunner(context());

                /** @var list<string> $args */
                return $runner->run(
                    'rector',
                    ['rector/rector' => $this->rectorVersion],
                    $args ?: $runner->getDefaultArguments('rector', [], '/src'),
                );
            },
        ];

        yield from $this->getWorkerTasks();
    }

    /**
     * Built per run, against the context of that run: the configuration files
     * and the tools directory are found through it.
     */
    protected function getQaToolRunner(Context $context): QaToolRunner
    {
        return new QaToolRunner(
            application: $this->name,
            phpVersion: $this->getVersion(),
            service: $this->getBuilderServiceName(),
            workDir: $this->getBuilderWorkingDirectory(),
            hostWorkingDirectory: $this->getAbsoluteHostWorkingDirectory($context),
            containerWorkingDirectory: $this->getContainerWorkingDirectory(static::MOUNT_POINT),
            toolsDirectory: QaToolRunner::getToolsDirectory($context),
        );
    }

    /**
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
                // "restart" also starts a stopped worker, so there is no
                // separate start task to remember.
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
     * The builder container may be the one of another application
     * (withSharedBuilder()), hence the sub-directory.
     *
     * @param string|array<int, string> $command
     */
    protected function runInBuilder(string|array $command, ?Context $c = null): void
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
