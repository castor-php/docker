<?php

namespace Castor\Docker\Service;

use Castor\Attribute\AsTask;
use Castor\Context;

use function Castor\Docker\docker_compose;
use function Castor\context;

class PostgresService implements DatabaseServiceInterface
{
    public function getName(): string
    {
        return 'postgres';
    }

    public function updateCompose(Context $context, array $compose): array
    {
        $compose['volumes']['postgres_data'] = [];
        $compose['services']['postgres'] = [
            'image' => 'postgres:16',
            'environment' => [
                'POSTGRES_USER' => 'app',
                'POSTGRES_PASSWORD' => 'app',
            ],
            'volumes' => [
                'postgres_data:/var/lib/postgresql/data',
            ],
            'healthcheck' => [
                'test' => ['CMD-SHELL', 'pg_isready -U app'],
                'interval' => '5s',
                'timeout' => '5s',
                'retries' => 5,
            ],
        ];

        return $compose;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('client', 'db', 'Connect to the PostgreSQL database'),
            'function' => function () {
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
