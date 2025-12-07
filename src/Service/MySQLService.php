<?php

namespace Castor\Docker\Service;

use Castor\Attribute\AsTask;
use Castor\Context;

use function Castor\Docker\docker_compose;
use function Castor\context;

class MySQLService implements DatabaseServiceInterface
{
    public function getName(): string
    {
        return 'mysql';
    }

    public function updateCompose(Context $context, array $compose): array
    {
        $compose['volumes']['mysql-data'] = [];
        $compose['services']['mysql'] = [
            'image' => 'mysql:8',
            'environment' => [
                'MYSQL_ALLOW_EMPTY_PASSWORD' => '1',
            ],
            'volumes' => [
                'mysql-data:/var/lib/mysql',
            ],
            'healthcheck' => [
                'test' => 'mysqladmin ping -h localhost',
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
            'task' => new AsTask('mysql', 'db', 'Connect to the MySQL database'),
            'function' => function () {
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
