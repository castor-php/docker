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
    public function getName(): string
    {
        return 'mysql';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        return $builder
            ->volume('mysql-data')
            ->service('mysql')
                ->image('mysql:8')
                ->environment('MYSQL_ALLOW_EMPTY_PASSWORD', '1')
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
        return 'mysql://app:app@mysql:3306/app';
    }

    public function hasHealthCheck(): bool
    {
        return true;
    }
}
