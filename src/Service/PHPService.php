<?php

namespace Castor\Docker\Service;

use Castor\Attribute\AsOption;
use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;
use Castor\Context;

use function Castor\Docker\docker_compose_run;
use function Castor\io;
use function Castor\PHPQa\php_cs_fixer;
use function Castor\PHPQa\phpstan;
use function Castor\with;

class PHPService implements ServiceInterface
{
    private DatabaseServiceInterface|null $databaseService = null;

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
    ) {
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

    public function getName(): string
    {
        return $this->name;
    }

    public function withDatabaseService(DatabaseServiceInterface $databaseService): self
    {
        $this->databaseService = $databaseService;
        return $this;
    }

    public function updateCompose(Context $context, array $compose): array
    {
        $userId = $context->data['user_id'] ?? 1000;
        $projectName = $context->data['project_name'] ?? 'app';

        $compose['services'][$this->name] = [
            'build' => [
                'context' => __DIR__ . '/../Resources/php',
                'target' => 'frontend',
                'cache_from' => [
                    'type=registry,ref=${REGISTRY:-}/'. $this->name . ':cache',
                ],
                'args' => [
                    'PHP_VERSION' => $this->version,
                ],
            ],
            'user' => "{$userId}:{$userId}",
            'volumes' => [
                $this->directory . ":/var/www:cached",
                $this->sharedHomeDirectory . ":/home/app:cached",
            ],
            'profiles' => ['default'],
        ];

        $compose['services'][$this->name . '-builder'] = [
            'build' => [
                'context' => __DIR__ . '/../Resources/php',
                'target' => 'builder',
                'cache_from' => [
                    'type=registry,ref=${REGISTRY:-}/'. $this->name . '-builder:cache',
                ],
                'args' => [
                    'PHP_VERSION' => $this->version,
                ],
            ],
            'user' => "{$userId}:{$userId}",
            'init' => true,
            'volumes' => [
                $this->directory . ":/var/www:cached",
                $this->sharedHomeDirectory . ":/home/app:cached",
            ],
            'profiles' => ['builder'],
        ];

        if ($this->domains) {
            $projectDomains = '`' . implode('`) || Host(`', $this->domains) . '`';

            $compose['services'][$this->name]['labels'] = [
                'traefik.enable=true',
                "traefik.http.routers.{$projectName}-{$this->name}.rule=Host({$projectDomains})",
                "traefik.http.routers.{$projectName}-{$this->name}.entrypoints=https",
                "traefik.http.routers.{$projectName}-{$this->name}.tls=true",
                "traefik.http.routers.{$projectName}-{$this->name}-unsecure.rule=Host({$projectDomains})",
                "traefik.http.services.{$projectName}-{$this->name}.loadbalancer.server.port=80",
            ];

            if (!$this->allowHttpAccess) {
                $compose['services'][$this->name]['labels'][] = "traefik.http.routers.{$projectName}-{$this->name}.middlewares=redirect-to-https@file";
            }
        }

        if ($this->databaseService) {
            $compose['services'][$this->name]['depends_on'][$this->databaseService->getName()] = [
                'condition' => 'service_healthy',
            ];
            $compose['services'][$this->name]['environment'][] = "DATABASE_URL=" . $this->databaseService->getDatabaseURL();
            $compose['services'][$this->name . '-builder']['depends_on'][$this->databaseService->getName()] = [
                'condition' => 'service_healthy',
            ];
            $compose['services'][$this->name . '-builder']['environment'][] = "DATABASE_URL=" . $this->databaseService->getDatabaseURL();
        }

        return $compose;
    }

    // This method return a list of tasks associated to this services
    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('install', $this->name, 'Install PHP dependencies using Composer'),
            'function' => function () {
                docker_compose_run('composer install', $this->name . '-builder');
            },
        ];

        yield [
            'task' => new AsTask('composer', $this->name, 'Run composer for this service'),
            'function' => function (#[AsRawTokens] array $args) {
                docker_compose_run('composer ' . implode(' ', $args), $this->name . '-builder');
            },
        ];

        yield [
            'task' => new AsTask('phpstan', $this->name . ':qa', 'Runs PHPStan'),
            'function' => function (#[AsOption(description: 'Generate baseline file', shortcut: 'b')] bool $baseline = false) {

                io()->section('Running PHPStan...');

                return with(function () use ($baseline) {
                    return phpstan(array_values(array_filter([
                        'analyse',
                        $this->directory,
                        '--memory-limit=-1',
                        $baseline ? '--generate-baseline' : null,
                        $baseline ? '--allow-empty-baseline' : null,
                        '-v',
                    ], fn ($val) => null !== $val)), $this->phpStanVersion);
                }, workingDirectory: $this->directory);
            },
        ];

        yield [
            'task' => new AsTask('cs', $this->name . ':qa', 'Fixes Coding Style'),
            'function' => function (bool $dryRun = false) {
                io()->section('Running PHP CS Fixer...');

                return with(function () use ($dryRun) {
                    return php_cs_fixer(array_values(array_filter([
                        'fix',
                        $dryRun ? '--dry-run' : null,
                        'src',
                    ], fn ($val) => null !== $val)), $this->phpCsFixerVersion);
                }, workingDirectory: $this->directory);
            },
        ];
    }
}
