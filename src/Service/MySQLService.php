<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasMysqlConfiguration;
use Castor\Docker\Service\Behaviour\HasDatabaseLink;
use Castor\Docker\Service\Behaviour\HasMysqlDump;
use Castor\Docker\Service\Behaviour\HasName;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\docker_compose;
use function Castor\Docker\expose_service_port;
use function Castor\Docker\get_dump_tasks;
use function Castor\Docker\interactive_context;
use function Castor\context;

class MySQLService implements DatabaseServiceInterface, DumpableServiceInterface
{
    use HasDatabaseLink;
    use HasMysqlConfiguration;
    use HasMysqlDump;
    use HasName;
    use HasVersion;

    private string $rootPassword = 'root';
    private string $database = 'app';

    protected function getDefaultVersion(): string
    {
        return '9.7.2';
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

    protected function getDefaultName(): string
    {
        return 'mysql';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $name = $this->getName();

        $service = $builder
            ->volume($name . '-data')
            ->service($name)
                ->image('mysql:' . $this->getVersion())
                ->environment('MYSQL_ROOT_PASSWORD', $this->rootPassword)
                ->environment('MYSQL_DATABASE', $this->database)
                ->volume($name . '-data', '/var/lib/mysql')
                // TCP only: ignores the socket-only init server.
                ->healthcheck('mysqladmin ping -h 127.0.0.1 --silent', startPeriod: '2m')
                ->profile('default')
        ;

        $this->applyConfiguration($builder, $service);

        return $builder;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('client', $this->getName(), 'Open a mysql session on the database'),
            'function' => function (): void {
                docker_compose(['exec', $this->getName(), 'mysql', '-u', 'root', '-p' . $this->rootPassword, $this->database], c: interactive_context());
            },
        ];

        yield [
            'task' => new AsTask('expose', $this->getName(), description: 'Expose the mysql service over TCP on the host (--stop to stop)'),
            'function' => function (
                #[AsArgument(description: 'Host port to expose on (defaults to the service port)')]
                ?int $port = null,
                bool $stop = false,
            ): void {
                expose_service_port($this->getName(), 3306, $port, $stop);
            },
        ];

        yield from get_dump_tasks($this);
    }

    protected function isMariaDB(): bool
    {
        return false;
    }

    public function getDatabaseURL(): string
    {
        return 'mysql://root:' . $this->rootPassword . '@' . $this->getName() . ':3306/' . $this->database . '?' . http_build_query(array_filter([
            'serverVersion' => $this->getVersionNumber(),
            'charset' => 'utf8mb4',
        ]));
    }

    public function hasHealthCheck(): bool
    {
        return true;
    }
}
