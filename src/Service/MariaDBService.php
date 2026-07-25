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

class MariaDBService implements DatabaseServiceInterface
{
    use HasVersion;

    private string $rootPassword = 'root';
    private string $database = 'app';

    protected function getDefaultVersion(): string
    {
        return '12.1';
    }

    public function withRootPassword(string $password): static
    {
        $this->rootPassword = $password;

        return $this;
    }

    public function withDatabase(string $database): static
    {
        $this->database = $database;

        return $this;
    }

    public function getName(): string
    {
        return 'mariadb';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        return $builder
            ->volume('mariadb-data')
            ->service('mariadb')
                ->image('mariadb:' . $this->getVersion())
                ->environment('MARIADB_ROOT_PASSWORD', $this->rootPassword)
                ->environment('MARIADB_DATABASE', $this->database)
                ->volume('mariadb-data', '/var/lib/mysql')
                ->healthcheck('mariadb-admin ping -h localhost')
                ->profile('default')
            ->end()
        ;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('mariadb', 'db', 'Connect to the MariaDB database'),
            'function' => function (): void {
                docker_compose(['exec', 'mariadb', 'mariadb', '-u', 'root', '-p' . $this->rootPassword, $this->database], c: context()->toInteractive());
            },
        ];

        yield [
            'task' => new AsTask('expose', $this->getName(), description: 'Expose the mariadb service over TCP on the host (--stop to stop)'),
            'function' => function (
                #[AsArgument(description: 'Host port to expose on (defaults to the service port)')]
                ?int $port = null,
                bool $stop = false,
            ): void {
                expose_service_port($this->getName(), 3306, $port, $stop);
            },
        ];
    }

    public function getDatabaseURL(): string
    {
        return 'mysql://root:' . $this->rootPassword . '@mariadb:3306/' . $this->database . '?serverVersion=mariadb-' . $this->getVersion() . '&charset=utf8mb4';
    }

    public function hasHealthCheck(): bool
    {
        return true;
    }
}
