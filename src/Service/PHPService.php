<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsOption;
use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\docker_compose_run;
use function Castor\io;
use function Castor\PHPQa\php_cs_fixer;
use function Castor\PHPQa\phpstan;
use function Castor\with;
use function Castor\context;

class PHPService implements ServiceInterface
{
    private ?DatabaseServiceInterface $databaseService = null;

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

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $userId = $context->data['user_id'] ?? 1000;
        $projectName = $context->data['project_name'] ?? 'app';

        $appService = $builder
            ->service($this->name)
                ->build(__DIR__ . '/../Resources/php')
                    ->dockerfile($this->dockerFile)
                    ->target('frontend')
                    ->additionalContext('original', __DIR__ . '/../Resources/php')
                    ->cacheFrom('type=registry,ref=${REGISTRY:-}/' . $this->name . ':cache')
                    ->arg('php_version', $this->version)
                ->end()
                ->user("{$userId}:{$userId}")
                ->volume($this->directory, '/var/www', 'cached')
                ->volume($this->sharedHomeDirectory, '/home/app', 'cached')
                ->profile('default')
        ;

        $buildBuilder = $builder->service($this->name)->build();

        $builderService = $builder
            ->service($this->name . '-builder')
                ->build($buildBuilder)
                    ->target('builder')
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
            'function' => function (#[AsOption(description: 'Generate baseline file', shortcut: 'b')] bool $baseline = false) {

                io()->section('Running PHPStan...');

                return with(fn() => phpstan(array_values(array_filter([
                    'analyse',
                    $this->directory,
                    '--memory-limit=-1',
                    $baseline ? '--generate-baseline' : null,
                    $baseline ? '--allow-empty-baseline' : null,
                    '-v',
                ], fn($val) => null !== $val)), $this->phpStanVersion), workingDirectory: $this->directory);
            },
        ];

        yield [
            'task' => new AsTask('cs', $this->name . ':qa', 'Fixes Coding Style'),
            'function' => function (bool $dryRun = false) {
                io()->section('Running PHP CS Fixer...');

                return with(fn() => php_cs_fixer(array_values(array_filter([
                    'fix',
                    $dryRun ? '--dry-run' : null,
                    'src',
                ], fn($val) => null !== $val)), $this->phpCsFixerVersion), workingDirectory: $this->directory);
            },
        ];
    }
}
