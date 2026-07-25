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

class ClickhouseService implements ServiceInterface
{
    use HasVersion;

    private bool $backup = false;
    private string $database = 'app';
    private string $username = 'app';
    private string $password = 'app';

    protected function getDefaultVersion(): string
    {
        return '25.8';
    }

    public function withBackup(bool $backup = true): static
    {
        $this->backup = $backup;

        return $this;
    }

    public function withDatabase(string $database): static
    {
        $this->database = $database;

        return $this;
    }

    public function withCredentials(string $username, string $password): static
    {
        $this->username = $username;
        $this->password = $password;

        return $this;
    }

    public function getName(): string
    {
        return 'clickhouse';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $rootDomain = $context->data['root_domain'] ?? 'castor.local';

        return $builder
            ->volume('clickhouse-data')
            ->service('clickhouse')
                ->build(__DIR__ . '/../Resources/clickhouse')
                    ->useTwigFrontend($context)
                    ->dockerfile('Dockerfile')
                    ->arg('clickhouse_version', $this->getVersion())
                    ->arg('backup', (string) $this->backup)
                ->end()
                ->volume('clickhouse-data', '/var/lib/clickhouse')
                ->withHttpRouting("clickhouse.{$rootDomain}")
                ->environment('CLICKHOUSE_DB', $this->database)
                ->environment('CLICKHOUSE_USER', $this->username)
                ->environment('CLICKHOUSE_PASSWORD', $this->password)
                ->environment('CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT', '1')
                ->environment('CLICKHOUSE_KEEPER_HOST', 'clickhouse-keeper')
                ->profile('default')
            ->end()
            ->service('clickhouse-keeper')
                ->build(__DIR__ . '/../Resources/clickhouse')
                    ->useTwigFrontend($context)
                    ->dockerfile('Dockerfile.keeper')
                    ->arg('clickhouse_version', $this->getVersion())
                ->end()
                ->profile('default')
            ->end()
        ;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('clickhouse', 'db', 'Connect to the Clickhouse database'),
            'function' => function (): void {
                docker_compose(['exec', 'clickhouse', 'clickhouse-client', '-d', 'app'], c: context()->toInteractive());
            },
        ];

        yield [
            'task' => new AsTask('expose', $this->getName(), description: 'Expose the clickhouse service (native protocol) over TCP on the host (--stop to stop)'),
            'function' => function (
                #[AsArgument(description: 'Host port to expose on (defaults to the service port)')]
                ?int $port = null,
                bool $stop = false,
            ): void {
                expose_service_port($this->getName(), 9000, $port, $stop);
            },
        ];
    }
}
