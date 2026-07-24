<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\docker_compose;
use function Castor\Docker\expose_service_port;
use function Castor\context;

readonly class ClickhouseService implements ServiceInterface
{
    public function __construct(
        private string $version,
        private bool $backup = false,
        private string $database = 'app',
        private string $username = 'app',
        private string $password = 'app',
    ) {}

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
                    ->dockerfile('Dockerfile')
                    ->arg('clickhouse_version', $this->version)
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
                    ->dockerfile('Dockerfile.keeper')
                    ->arg('clickhouse_version', $this->version)
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
