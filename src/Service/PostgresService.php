<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasName;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\docker_compose;
use function Castor\Docker\expose_service_port;
use function Castor\Docker\interactive_context;
use function Castor\context;

class PostgresService implements DatabaseServiceInterface
{
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
        return 'postgresql://app:app@' . $this->getName() . ':5432/app?serverVersion=' . $this->getServerVersion() . '&charset=utf8';
    }

    public function hasHealthCheck(): bool
    {
        return true;
    }

    /**
     * Where the image keeps its data, which moved in PostgreSQL 18: PGDATA is
     * now version specific ("/var/lib/postgresql/18/docker") and the image
     * declares "/var/lib/postgresql" as its volume, while up to 17 the data
     * lives in "/var/lib/postgresql/data" and mounting the parent persists
     * nothing. Mounting the wrong one of the two loses the database the next
     * time the container is recreated.
     */
    private function getDataDirectory(): string
    {
        $major = $this->getMajorVersion();

        return null !== $major && $major < 18 ? '/var/lib/postgresql/data' : '/var/lib/postgresql';
    }

    /**
     * The version Doctrine is told the server runs, which must be a number: a
     * tag naming no version falls back to the one this service defaults to.
     */
    private function getServerVersion(): int
    {
        return $this->getMajorVersion() ?? (int) $this->getDefaultVersion();
    }

    /**
     * The major the image tag names, when it names one: "18", "18.4" and
     * "18-alpine" are 18, while "latest" and "alpine" name whatever release is
     * current and get no number.
     */
    private function getMajorVersion(): ?int
    {
        if (!preg_match('/^(\d+)/', $this->getVersion(), $matches)) {
            return null;
        }

        return (int) $matches[1];
    }
}
