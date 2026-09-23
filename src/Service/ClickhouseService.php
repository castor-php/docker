<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasName;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\docker_compose;
use function Castor\Docker\expose_service_port;
use function Castor\Docker\get_dump_tasks;
use function Castor\Docker\interactive_context;
use function Castor\context;

class ClickhouseService implements DumpableServiceInterface
{
    use HasName;
    use HasVersion;

    /**
     * Where BACKUP may write, which "{name}:dump" and "{name}:restore" go
     * through: a directory of the data volume, so the throwaway container
     * running them reaches the archive the server wrote.
     */
    private const BACKUPS_DIRECTORY = '/var/lib/clickhouse/backups/';

    private const BACKUPS_CONFIGURATION = <<<'XML'
        <clickhouse>
            <backups>
                <allowed_path>/var/lib/clickhouse/backups/</allowed_path>
            </backups>
        </clickhouse>
        XML;

    private bool $backup = false;
    private string $database = 'app';
    private string $username = 'app';
    private string $password = 'app';

    protected function getDefaultVersion(): string
    {
        return '26.8';
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

    protected function getDefaultName(): string
    {
        return 'clickhouse';
    }

    /**
     * The keeper container that comes with this instance.
     */
    public function getKeeperName(): string
    {
        return $this->getName() . '-keeper';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $rootDomain = $context->data['root_domain'] ?? 'castor.local';

        $name = $this->getName();
        $keeper = $this->getKeeperName();

        return $builder
            ->volume($name . '-data')
            ->config($name . '-backups', self::BACKUPS_CONFIGURATION)
            ->service($name)
                ->build(__DIR__ . '/../Resources/clickhouse')
                    ->useTwigFrontend($context)
                    ->dockerfile('Dockerfile')
                    ->arg('clickhouse_version', $this->getVersion())
                    ->arg('backup', (string) $this->backup)
                ->end()
                ->volume($name . '-data', '/var/lib/clickhouse')
                ->config($name . '-backups', '/etc/clickhouse-server/config.d/castor-backups.xml')
                // The image exposes 8123 (HTTP) and 9000 (native protocol);
                // without a port Caddy picks whichever it finds first, and
                // routing to the native one answers 502.
                ->withHttpRouting("{$name}.{$rootDomain}", 8123)
                ->environment('CLICKHOUSE_DB', $this->database)
                ->environment('CLICKHOUSE_USER', $this->username)
                ->environment('CLICKHOUSE_PASSWORD', $this->password)
                ->environment('CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT', '1')
                ->environment('CLICKHOUSE_KEEPER_HOST', $keeper)
                ->healthcheck(['CMD', 'wget', '--quiet', '--spider', 'http://127.0.0.1:8123/ping'], startPeriod: '1m')
                ->profile('default')
            ->end()
            ->service($keeper)
                ->build(__DIR__ . '/../Resources/clickhouse')
                    ->useTwigFrontend($context)
                    ->dockerfile('Dockerfile.keeper')
                    ->arg('clickhouse_version', $this->getVersion())
                ->end()
                // No HTTP client in this image.
                ->healthcheck(['CMD', 'clickhouse-keeper-client', '--host', '127.0.0.1', '--port', '9181', '--query', 'ruok'], startPeriod: '1m')
                ->profile('default')
            ->end()
        ;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('client', $this->getName(), 'Open a clickhouse-client session on the database'),
            'function' => function (): void {
                docker_compose(['exec', $this->getName(), 'clickhouse-client', '-d', $this->database], c: interactive_context());
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

        yield from get_dump_tasks($this);
    }

    /**
     * A BACKUP archive, the only format holding both the schema and the data
     * of a whole database — and a copy of its data parts, so restoring one is
     * not a replay of inserts.
     */
    public function getDumpFormats(): array
    {
        return ['zip'];
    }

    public function getDumpEnvironment(): array
    {
        return [
            'CASTOR_HOST' => $this->getName(),
            'CASTOR_USER' => $this->username,
            'CASTOR_PASSWORD' => $this->password,
            'CASTOR_DATABASE' => $this->database,
        ];
    }

    /**
     * Replicated tables keep their metadata in the keeper, which has to run for
     * them to be dropped and restored.
     */
    public function getDumpServices(): array
    {
        return [$this->getName(), $this->getKeeperName()];
    }

    public function getConnectionScript(): string
    {
        return <<<'BASH'
            castor_query() { clickhouse-client --host "$CASTOR_HOST" --user "$CASTOR_USER" --password "$CASTOR_PASSWORD" --query "$1" >/dev/null; }
            castor_ready() { castor_query 'SELECT 1'; }
            BASH;
    }

    public function getDumpScript(string $format): string
    {
        return strtr(<<<'BASH'
            castor_dump() {
                local backup="{directory}castor-dump-$$.zip"
                castor_query "BACKUP DATABASE \`$CASTOR_DATABASE\` TO File('$backup')"
                cat "$backup"
                rm -f "$backup"
            }
            BASH, ['{directory}' => self::BACKUPS_DIRECTORY]);
    }

    /**
     * The archive is copied where the server may read it, next to its data.
     * The database is dropped synchronously first: the keeper would otherwise
     * still hold the replicas of its tables when RESTORE creates them again.
     */
    public function getRestoreScript(): string
    {
        return strtr(<<<'BASH'
            castor_restore() {
                local head backup="{directory}castor-restore-$$.zip"
                head=$(mktemp)
                dd bs=1 count=4 of="$head" 2>/dev/null

                if [ "$(od -An -tx1 "$head" | tr -d ' \n')" != 504b0304 ]; then
                    echo 'This is not a ClickHouse BACKUP archive, the .zip written by BACKUP DATABASE ... TO File(...).' >&2
                    exit 1
                fi

                mkdir -p {directory}
                chown clickhouse:clickhouse {directory}
                cat "$head" - > "$backup"
                chown clickhouse:clickhouse "$backup"
                trap 'rm -f "$backup"' EXIT

                castor_query "DROP DATABASE IF EXISTS \`$CASTOR_DATABASE\` SYNC"
                castor_query "RESTORE DATABASE \`$CASTOR_DATABASE\` FROM File('$backup')"
            }
            BASH, ['{directory}' => self::BACKUPS_DIRECTORY]);
    }
}
