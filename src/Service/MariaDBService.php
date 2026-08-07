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
use function Castor\Docker\interactive_context;
use function Castor\context;

class MariaDBService implements DatabaseServiceInterface
{
    use HasMysqlConfiguration;
    use HasName;
    use HasVersion;

    private string $rootPassword = 'root';
    private string $database = 'app';

    protected function getDefaultVersion(): string
    {
        return '12.3.2';
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
        return 'mariadb';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $name = $this->getName();

        $service = $builder
            ->volume($name . '-data')
            ->service($name)
                ->image('mariadb:' . $this->getVersion())
                ->environment('MARIADB_ROOT_PASSWORD', $this->rootPassword)
                ->environment('MARIADB_DATABASE', $this->database)
                ->volume($name . '-data', '/var/lib/mysql')
                ->healthcheck('mariadb-admin ping -h localhost')
                ->profile('default')
        ;

        $this->applyConfiguration($builder, $service);

        return $builder;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('client', $this->getName(), 'Open a mariadb session on the database'),
            'function' => function (): void {
                docker_compose(['exec', $this->getName(), 'mariadb', '-u', 'root', '-p' . $this->rootPassword, $this->database], c: interactive_context());
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
        return 'mysql://root:' . $this->rootPassword . '@' . $this->getName() . ':3306/' . $this->database . '?serverVersion=mariadb-' . $this->getVersion() . '&charset=utf8mb4';
    }

    public function hasHealthCheck(): bool
    {
        return true;
    }
}
