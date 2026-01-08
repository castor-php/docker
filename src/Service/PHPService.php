<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\docker_compose_run;
use function Castor\io;
use function Castor\PHPQa\php_cs_fixer;
use function Castor\PHPQa\phpstan;
use function Castor\PHPQa\rector;
use function Castor\with;
use function Castor\context;

class PHPService implements ServiceInterface
{
    private ?DatabaseServiceInterface $databaseService = null;

    /** @var array<string, string> */
    private array $phpStanExtraDependencies = [];

    private ?string $redirectionIoKey = null;

    /**
     * @var array<string, string>
     */
    private array $workers = [];

    public function __construct(
        protected string $name = 'app',
        protected string $directory = '.',
        protected string $version = '8.5',
        protected string $sharedHomeDirectory = '.home',
        protected string $phpStanVersion = '*',
        protected string $phpCsFixerVersion = '*',
        protected string $rectorVersion = '*',
        /** @var string[] */
        protected array $domains = [],
        protected bool $allowHttpAccess = false,
        protected string $dockerFile = __DIR__ . '/../Resources/php/Dockerfile',
    ) {}

    public function addWorker(string $name, string $command): self
    {
        $this->workers[$name] = $command;

        return $this;
    }

    public function addDomain(string $domain): self
    {
        $this->domains[] = $domain;
        return $this;
    }

    public function allowHttpAccess(bool $allow = true): self
    {
        $this->allowHttpAccess = $allow;
        return $this;
    }

    public function withDockerfile(string $path): self
    {
        $this->dockerFile = $path;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function withDatabaseService(DatabaseServiceInterface $databaseService): self
    {
        $this->databaseService = $databaseService;
        return $this;
    }

    public function addPhpStanExtraDependency(string $package, string $version): self
    {
        $this->phpStanExtraDependencies[$package] = $version;
        return $this;
    }

    public function withRedirectionIoKey(string $key): self
    {
        $this->redirectionIoKey = $key;
        return $this;
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $userId = $context->data['user_id'] ?? 1000;
        $projectName = $context->data['project_name'] ?? 'app';

        $appService = $builder
            ->service($this->name)
                ->build(__DIR__ . '/../Resources/php')
                    ->dockerfile($this->dockerFile)
                    ->target('frontend')
                    ->withRegistryCache($this->name)
                    ->arg('php_version', $this->version)
                ->end()
                ->user("{$userId}:{$userId}")
                ->volume($this->directory, '/var/www', 'cached')
                ->volume($this->sharedHomeDirectory, '/home/app', 'cached')
                ->profile('default')
        ;

        if ($this->redirectionIoKey !== null) {
            $appService->build()->arg('redirection_io_key', $this->redirectionIoKey);
        }

        $buildBuilder = $builder->service($this->name)->build();

        $builderService = $builder
            ->service($this->name . '-builder')
                ->build($buildBuilder)
                    ->target('builder')
                    ->withRegistryCache($this->name . '-builder')
                ->end()
                ->user("{$userId}:{$userId}")
                ->init(true)
                ->volume($this->directory, '/var/www', 'cached')
                ->volume($this->sharedHomeDirectory, '/home/app', 'cached')
                ->profile('builder')
        ;

        if ($this->domains) {
            $appService
                ->withTraefikRouting("{$projectName}-{$this->name}", $this->domains, 80, $this->allowHttpAccess);
        }

        if ($this->databaseService) {
            $appService
                ->dependsOn($this->databaseService->getName(), [
                    'condition' => 'service_healthy',
                ])
                ->environment('DATABASE_URL', $this->databaseService->getDatabaseURL())
            ;

            $builderService
                ->dependsOn($this->databaseService->getName(), [
                    'condition' => 'service_healthy',
                ])
                ->environment('DATABASE_URL', $this->databaseService->getDatabaseURL())
            ;
        }

        foreach ($this->workers as $workerName => $command) {
            $workerService = $builder
                ->service($this->name . '-worker-' . $workerName)
                    ->build($buildBuilder)
                        ->target('worker')
                        ->withRegistryCache($this->name . '-worker')
                    ->end()
                    ->user("{$userId}:{$userId}")
                    ->volume($this->directory, '/var/www', 'cached')
                    ->volume($this->sharedHomeDirectory, '/home/app', 'cached')
                    ->command($command)
                    ->profile('default')
            ;

            if ($this->databaseService) {
                $workerService
                    ->dependsOn($this->databaseService->getName(), [
                        'condition' => 'service_healthy',
                    ])
                    ->environment('DATABASE_URL', $this->databaseService->getDatabaseURL())
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
                docker_compose_run('bash', $this->name . '-builder', c: context()->toInteractive());
            },
        ];

        yield [
            'task' => new AsTask('install', $this->name, 'Install PHP dependencies using Composer'),
            'function' => function (): void {
                docker_compose_run('composer install', $this->name . '-builder');
            },
        ];

        yield [
            'task' => new AsTask('composer', $this->name, 'Run composer for this service'),
            'function' => function (#[AsRawTokens] array $args): void {
                docker_compose_run('composer ' . implode(' ', $args), $this->name . '-builder');
            },
        ];

        yield [
            'task' => new AsTask('phpstan', $this->name . ':qa', 'Runs PHPStan'),
            'function' => function (#[AsRawTokens] array $args) {
                io()->section('Running PHPStan...');

                /** @var list<string> $args */
                return with(fn() => phpstan($args, $this->phpStanVersion, $this->phpStanExtraDependencies ?? []), workingDirectory: $this->directory);
            },
        ];

        yield [
            'task' => new AsTask('cs', $this->name . ':qa', 'Fixes Coding Style'),
            'function' => function (#[AsRawTokens] array $args) {
                io()->section('Running PHP CS Fixer...');

                /** @var list<string> $args */
                return with(fn() => php_cs_fixer($args, $this->phpCsFixerVersion), workingDirectory: $this->directory);
            },
        ];

        yield [
            'task' => new AsTask('rector', $this->name . ':qa', 'Updates and refactors code using Rector'),
            'function' => function (#[AsRawTokens] array $args) {
                io()->section('Running Rector...');

                /** @var list<string> $args */
                return with(fn() => rector($args, $this->rectorVersion), workingDirectory: $this->directory);
            },
        ];
    }
}
