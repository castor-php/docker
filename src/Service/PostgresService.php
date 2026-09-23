<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasDatabaseLink;
use Castor\Docker\Service\Behaviour\HasName;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\docker_compose;
use function Castor\Docker\expose_service_port;
use function Castor\Docker\interactive_context;
use function Castor\context;

class PostgresService implements DatabaseServiceInterface
{
    use HasDatabaseLink;
    use HasName;
    use HasVersion;

    protected function getDefaultVersion(): string
    {
        return '18.4';
    }

    protected function getDefaultName(): string
    {
        return 'postgres';
    }

    /**
     * Postgres 18 moved PGDATA to a versioned subdirectory and declares its
     * volume one level up; before that the data directory was the mount point
     * itself. A tag naming no version ("latest", "bookworm") is a recent one.
     */
    protected function getDataDirectory(): string
    {
        $isBefore18 = preg_match('/^(\d+)/', $this->getVersion(), $matches) && (int) $matches[1] < 18;

        return $isBefore18 ? '/var/lib/postgresql/data' : '/var/lib/postgresql';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $name = $this->getName();

        return $builder
            ->volume($name . '_data')
            ->service($name)
                ->image('postgres:' . $this->getVersion())
                ->environment('POSTGRES_USER', 'app')
                ->environment('POSTGRES_PASSWORD', 'app')
                ->volume($name . '_data', $this->getDataDirectory())
                ->healthcheck(['CMD-SHELL', 'pg_isready -U app'])
                ->profile('default')
            ->end()
        ;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('client', $this->getName(), 'Open a psql session on the database'),
            'function' => function (): void {
                docker_compose(['exec', $this->getName(), 'psql', '-U', 'app', 'app'], c: interactive_context());
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
        return 'postgresql://app:app@' . $this->getName() . ':5432/app?serverVersion=16&charset=utf8';
    }

    public function hasHealthCheck(): bool
    {
        return true;
    }
}
