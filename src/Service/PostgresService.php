<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\docker_compose;
use function Castor\context;

class PostgresService implements DatabaseServiceInterface
{
    public function getName(): string
    {
        return 'postgres';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        return $builder
            ->volume('postgres_data')
            ->service('postgres')
                ->image('postgres:16')
                ->environment('POSTGRES_USER', 'app')
                ->environment('POSTGRES_PASSWORD', 'app')
                ->volume('postgres_data', '/var/lib/postgresql/data')
                ->healthcheck(['CMD-SHELL', 'pg_isready -U app'])
                ->profile('default')
            ->end()
        ;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('psql', 'db', 'Connect to the PostgreSQL database'),
            'function' => function (): void {
                docker_compose(['exec', 'postgres', 'psql', '-U', 'app', 'app'], c: context()->toInteractive());
            },
        ];
    }

    public function getDatabaseURL(): string
    {
        return 'postgresql://app:app@postgres:5432/app?serverVersion=16&charset=utf8';
    }

    public function hasHealthCheck(): bool
    {
        return true;
    }
}
