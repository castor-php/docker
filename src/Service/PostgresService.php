<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\docker_compose;
use function Castor\Docker\expose_service_port;
use function Castor\context;

class PostgresService implements DatabaseServiceInterface
{
    use HasVersion;

    protected function getDefaultVersion(): string
    {
        return '16';
    }

    public function getName(): string
    {
        return 'postgres';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        return $builder
            ->volume('postgres_data')
            ->service('postgres')
                ->image('postgres:' . $this->getVersion())
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

        yield [
            'task' => new AsTask('expose', $this->getName(), description: 'Expose the postgres service over TCP on the host (--stop to stop)'),
            'function' => function (
                #[AsArgument(description: 'Host port to expose on (defaults to the service port)')]
                ?int $port = null,
                bool $stop = false,
            ): void {
                expose_service_port($this->getName(), 5432, $port, $stop);
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
