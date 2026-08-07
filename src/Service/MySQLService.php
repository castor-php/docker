<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasMysqlConfiguration;
use Castor\Docker\Service\Behaviour\HasName;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\docker_compose;
use function Castor\Docker\expose_service_port;
use function Castor\context;

class MySQLService implements DatabaseServiceInterface
{
    use HasMysqlConfiguration;
    use HasName;
    use HasVersion;

    private string $rootPassword = 'root';
    private string $database = 'app';

    protected function getDefaultVersion(): string
    {
        return '8.0.46';
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
                ->healthcheck('mysqladmin ping -h localhost')
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
                docker_compose(['exec', $this->getName(), 'mysql', '-u', 'root', '-p' . $this->rootPassword, $this->database], c: context()->toInteractive());
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
    }

    public function getDatabaseURL(): string
    {
        return 'mysql://root:' . $this->rootPassword . '@' . $this->getName() . ':3306/' . $this->database;
    }

    public function hasHealthCheck(): bool
    {
        return true;
    }
}
