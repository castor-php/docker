<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasDatabaseLink;
use Castor\Docker\Service\Behaviour\HasName;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\docker_compose;
use function Castor\Docker\expose_service_port;
use function Castor\Docker\get_dump_tasks;
use function Castor\Docker\interactive_context;
use function Castor\context;

class PostgresService implements DatabaseServiceInterface, DumpableServiceInterface
{
    use HasDatabaseLink;
    use HasName;
    use HasVersion;

    protected function getDefaultVersion(): string
    {
        return '18.4';
    }

    protected function getDefaultName(): string
    {
        return 'postgres';
    }

    /**
     * Postgres 18 moved PGDATA to a versioned subdirectory and declares its
     * volume one level up; before that the data directory was the mount point
     * itself. A tag naming no version ("latest", "bookworm") is a recent one.
     */
    protected function getDataDirectory(): string
    {
        $version = $this->getVersionNumber();

        return null !== $version && (int) $version < 18 ? '/var/lib/postgresql/data' : '/var/lib/postgresql';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $name = $this->getName();

        return $builder
            ->volume($name . '_data')
            ->service($name)
                ->image('postgres:' . $this->getVersion())
                ->environment('POSTGRES_USER', 'app')
                ->environment('POSTGRES_PASSWORD', 'app')
                ->volume($name . '_data', $this->getDataDirectory())
                // TCP only: ignores the socket-only init server.
                ->healthcheck(['CMD-SHELL', 'pg_isready -U app -h 127.0.0.1'], startPeriod: '2m')
                ->profile('default')
            ->end()
        ;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('client', $this->getName(), 'Open a psql session on the database'),
            'function' => function (): void {
                docker_compose(['exec', $this->getName(), 'psql', '-U', 'app', 'app'], c: interactive_context());
            },
        ];

        yield [
            'task' => new AsTask('expose', $this->getName(), description: 'Expose the postgres service over TCP on the host (--stop to stop)'),
            'function' => function (
                #[AsArgument(description: 'Host port to expose on (defaults to the service port)')]
                ?int $port = null,
                bool $stop = false,
            ): void {
                expose_service_port($this->getName(), 5432, $port, $stop);
            },
        ];

        yield from get_dump_tasks($this);
    }

    /**
     * Plain SQL first, what goes to the standard output: any client reads it.
     * "dump" is the custom format of pg_dump, compressed already, and restored
     * in parallel.
     */
    public function getDumpFormats(): array
    {
        return ['sql', 'dump'];
    }

    public function getDumpEnvironment(): array
    {
        return [
            'PGHOST' => $this->getName(),
            'PGUSER' => 'app',
            'PGPASSWORD' => 'app',
            'CASTOR_DATABASE' => 'app',
        ];
    }

    public function getDumpServices(): array
    {
        return [$this->getName()];
    }

    public function getConnectionScript(): string
    {
        return <<<'BASH'
            castor_psql() { PGOPTIONS='-c client_min_messages=warning' psql -X -q -v ON_ERROR_STOP=1 "$@"; }
            castor_ready() { pg_isready -q && castor_psql -d postgres -c 'SELECT 1' >/dev/null; }
            BASH;
    }

    /**
     * Without owners nor privileges: the roles of one server are rarely those
     * of the next, and a dump naming them fails to restore anywhere else.
     */
    public function getDumpScript(string $format): string
    {
        $options = 'dump' === $format ? '--format=custom' : '--no-owner --no-privileges';

        return <<<BASH
            castor_dump() { pg_dump {$options} "\$CASTOR_DATABASE"; }
            BASH;
    }

    /**
     * The dump is restored in a scratch database, which then takes the place
     * of the current one: a dump that fails to restore — truncated, or made
     * for another schema — leaves the database as it was, and the swap takes
     * an instant.
     *
     * pg_dump's archive formats are restored by pg_restore, in parallel for the
     * custom one; plain SQL by psql, which carries on past an error the way it
     * always does: a dump made elsewhere hands its tables to roles that do not
     * exist here, and failing on each would make most of them unusable. The
     * errors are counted, and reported.
     */
    public function getRestoreScript(): string
    {
        return <<<'BASH'
            castor_load() {
                local head errors
                head=$(mktemp)
                dd bs=1 count=262 of="$head" 2>/dev/null

                if [ "$(head -c 5 "$head")" = PGDMP ]; then
                    # Read from a file, pg_restore can run in parallel.
                    cat "$head" - > /tmp/castor.dump || return
                    pg_restore --no-owner --no-privileges --exit-on-error --jobs="$(nproc)" -d "$1" /tmp/castor.dump
                elif [ "$(tail -c +258 "$head" | head -c 5)" = ustar ]; then
                    cat "$head" - | pg_restore --no-owner --no-privileges --exit-on-error -d "$1"
                else
                    errors=$(mktemp)
                    cat "$head" - | psql -X -q -d "$1" 2>&1 >/dev/null | tee "$errors" >&2 || return
                    count=$(grep -c ' ERROR: ' "$errors" || true)

                    if [ "$count" != 0 ]; then
                        echo "$count statement(s) of the dump failed, see above." >&2
                    fi
                fi
            }

            castor_restore() {
                local scratch="${CASTOR_DATABASE}__castor_restore"

                castor_psql -d postgres -c "DROP DATABASE IF EXISTS \"$scratch\"" -c "CREATE DATABASE \"$scratch\""

                if ! castor_load "$scratch"; then
                    castor_psql -d postgres -c "DROP DATABASE IF EXISTS \"$scratch\"" || true
                    echo 'The dump could not be restored, the database is left as it was.' >&2
                    exit 1
                fi

                castor_psql -d postgres \
                    -c "DROP DATABASE IF EXISTS \"$CASTOR_DATABASE\" WITH (FORCE)" \
                    -c "ALTER DATABASE \"$scratch\" RENAME TO \"$CASTOR_DATABASE\""
            }
            BASH;
    }

    public function getDatabaseURL(): string
    {
        return 'postgresql://app:app@' . $this->getName() . ':5432/app?' . http_build_query(array_filter([
            'serverVersion' => $this->getVersionNumber(),
            'charset' => 'utf8',
        ]));
    }

    public function hasHealthCheck(): bool
    {
        return true;
    }
}
