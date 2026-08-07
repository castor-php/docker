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
use function Castor\PHPQa\php_cs_fixer;
use function Castor\PHPQa\phpstan;
use function Castor\PHPQa\rector;
use function Castor\with;
use function Castor\context;

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
     * @var array<string, string>
     */
    private array $workers = [];

    /** @var string[] */
    private array $extensions = ['apcu', 'bcmath', 'curl', 'iconv', 'intl', 'mbstring', 'pgsql', 'uuid', 'xml', 'zip'];

    private ?string $frankenPhpWorkerScript = null;
    private ?int $frankenPhpWorkerNum = null;
    private bool $frankenPhpWorkerWatch = true;

    protected PhpMode $mode = PhpMode::FrankenPhp;

    protected string $phpStanVersion = '*';
    protected string $phpCsFixerVersion = '*';
    protected string $rectorVersion = '*';

    protected const MOUNT_POINT = '/var/www';

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

    public function addWorker(string $name, string $command): static
    {
        $this->workers[$name] = $command;

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
                    ->end()
                    ->user("{$userId}:{$userId}")
                    ->init(true)
                    ->volume($this->getDirectory(), static::MOUNT_POINT, 'cached')
                    ->volume($this->getSharedHomeDirectory(), '/home/app', 'cached')
                    ->profile('builder')
            ;
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
                ->dependsOn($this->mailerService->getName())
                ->environment('MAILER_DSN', $this->mailerService->getMailerDSN())
            ;

            $builderService
                ?->dependsOn($this->mailerService->getName())
                ->environment('MAILER_DSN', $this->mailerService->getMailerDSN())
            ;
        }

        foreach ($this->workers as $workerName => $command) {
            $workerService = $builder
                ->service($this->getWorkerServiceName($workerName))
                    ->build($buildBuilder)
                        ->target('worker')
                        ->withRegistryCache($this->name . '-worker')
                    ->end()
                    ->user("{$userId}:{$userId}")
                    ->volume($this->getDirectory(), static::MOUNT_POINT, 'cached')
                    ->volume($this->getSharedHomeDirectory(), '/home/app', 'cached')
                    ->command($command)
                    ->profile('default')
            ;

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
                    ->dependsOn($this->mailerService->getName())
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
            'function' => function (): void {
                $this->runInBuilder('bash', c: context()->toInteractive());
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
            'function' => function (#[AsRawTokens] array $args) {
                io()->section('Running PHPStan...');

                /** @var list<string> $args */
                return with(fn() => phpstan($args, $this->phpStanVersion, $this->phpStanExtraDependencies ?? []), workingDirectory: $this->getHostWorkingDirectory());
            },
        ];

        yield [
            'task' => new AsTask('cs', $this->name . ':qa', 'Fixes Coding Style'),
            'function' => function (#[AsRawTokens] array $args) {
                io()->section('Running PHP CS Fixer...');

                /** @var list<string> $args */
                return with(fn() => php_cs_fixer($args, $this->phpCsFixerVersion), workingDirectory: $this->getHostWorkingDirectory());
            },
        ];

        yield [
            'task' => new AsTask('rector', $this->name . ':qa', 'Updates and refactors code using Rector'),
            'function' => function (#[AsRawTokens] array $args) {
                io()->section('Running Rector...');

                /** @var list<string> $args */
                return with(fn() => rector($args, $this->rectorVersion), workingDirectory: $this->getHostWorkingDirectory());
            },
        ];

        yield from $this->getWorkerTasks();
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
