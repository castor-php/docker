<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\docker_compose;
use function Castor\context;

class MySQLService implements DatabaseServiceInterface
{
    public function __construct(
        private string $version = '8',
        private string $rootPassword = 'root',
        private string $database = 'app',
    ) {}

    public function getName(): string
    {
        return 'mysql';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        return $builder
            ->volume('mysql-data')
            ->service('mysql')
                ->image('mysql:' . $this->version)
                ->environment('MYSQL_ROOT_PASSWORD', $this->rootPassword)
                ->environment('MYSQL_DATABASE', $this->database)
                ->volume('mysql-data', '/var/lib/mysql')
                ->healthcheck('mysqladmin ping -h localhost')
            ->end()
        ;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('mysql', 'db', 'Connect to the MySQL database'),
            'function' => function (): void {
                docker_compose(['exec', 'mysql', 'mysql', '-u', 'root'], c: context()->toInteractive());
            },
        ];
    }

    public function getDatabaseURL(): string
    {
        return 'mysql://root:' . $this->rootPassword . '@mysql:3306/' . $this->database;
    }

    public function hasHealthCheck(): bool
    {
        return true;
    }
}
