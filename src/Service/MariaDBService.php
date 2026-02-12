<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\docker_compose;
use function Castor\context;

class MariaDBService implements DatabaseServiceInterface
{
    public function __construct(
        private string $version = '12.1',
        private string $rootPassword = 'root',
        private string $database = 'app',
    ) {}

    public function getName(): string
    {
        return 'mariadb';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        return $builder
            ->volume('mariadb-data')
            ->service('mariadb')
                ->image('mariadb:' . $this->version)
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
    }

    public function getDatabaseURL(): string
    {
        return 'mysql://root:' . $this->rootPassword . '@mariadb:3306/' . $this->database . '?serverVersion=mariadb-' . $this->version . '&charset=utf8mb4';
    }

    public function hasHealthCheck(): bool
    {
        return true;
    }
}
