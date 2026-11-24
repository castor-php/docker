<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasDatabaseLink;
use Castor\Docker\Service\Behaviour\HasMysqlConfiguration;
use Castor\Docker\Service\Behaviour\HasName;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\docker_compose_exec;
use function Castor\Docker\expose_task;
use function Castor\Docker\get_dump_tasks;
use function Castor\Docker\interactive_context;

/**
 * MySQL and MariaDB: one server family, sharing the configuration, the dump
 * format and most of the tools, under different names.
 */
abstract class AbstractMysqlFamilyService implements DatabaseServiceInterface, DumpableServiceInterface
{
    use HasDatabaseLink;
    use HasMysqlConfiguration;
    use HasName;
    use HasVersion;

    protected string $rootPassword = 'root';

    protected string $database = 'app';

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

    /**
     * Names the image, the client and the prefix of the image variables alike.
     */
    abstract protected function isMariaDB(): bool;

    /**
     * Over TCP: the init server answers on the socket only, so a socket check
     * would pass before the real server is up.
     */
    abstract protected function getHealthcheck(): string;

    /**
     * The "serverVersion" of DATABASE_URL, null to leave it out.
     */
    abstract protected function getServerVersion(): ?string;

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $name = $this->getName();
        $variables = strtoupper($this->getFlavour());

        $service = $builder
            ->volume($name . '-data')
            ->service($name)
                ->image($this->getFlavour() . ':' . $this->getVersion())
                ->environment($variables . '_ROOT_PASSWORD', $this->rootPassword)
                ->environment($variables . '_DATABASE', $this->database)
                ->volume($name . '-data', '/var/lib/mysql')
                ->healthcheck($this->getHealthcheck(), startPeriod: '2m')
                ->profile('default')
        ;

        $this->applyConfiguration($builder, $service);

        return $builder;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('client', $this->getName(), 'Open a ' . $this->getFlavour() . ' session on the database'),
            'function' => function (): void {
                docker_compose_exec([$this->getFlavour(), '-u', 'root', '-p' . $this->rootPassword, $this->database], $this->getName(), interactive_context());
            },
        ];

        yield expose_task($this, 3306);

        yield from get_dump_tasks($this);
    }

    public function getDatabaseURL(): string
    {
        return 'mysql://root:' . $this->rootPassword . '@' . $this->getName() . ':3306/' . $this->database . '?' . http_build_query(array_filter([
            'serverVersion' => $this->getServerVersion(),
            'charset' => 'utf8mb4',
        ]));
    }

    public function getDumpFormats(): array
    {
        return ['sql'];
    }

    public function getDumpEnvironment(): array
    {
        return [
            'CASTOR_HOST' => $this->getName(),
            'CASTOR_PASSWORD' => $this->rootPassword,
            'CASTOR_DATABASE' => $this->database,
        ];
    }

    public function getDumpServices(): array
    {
        return [$this->getName()];
    }

    /**
     * The credentials go through an option file rather than the command line,
     * where the client warns about them on every call.
     */
    public function getConnectionScript(): string
    {
        [$client, $dump] = $this->isMariaDB() ? ['mariadb', 'mariadb-dump'] : ['mysql', 'mysqldump'];

        return strtr(<<<'BASH'
            castor_credentials=$(mktemp)
            castor_password=${CASTOR_PASSWORD//\\/\\\\}
            castor_password=${castor_password//\"/\\\"}
            printf '[client]\nhost=%s\nuser=root\npassword="%s"\n' "$CASTOR_HOST" "$castor_password" > "$castor_credentials"
            castor_client() { "$(command -v {client} || command -v mysql)" --defaults-extra-file="$castor_credentials" "$@"; }
            castor_dumper() { "$(command -v {dump} || command -v mysqldump)" --defaults-extra-file="$castor_credentials" "$@"; }
            castor_ready() { castor_client -e 'SELECT 1'; }
            BASH, ['{client}' => $client, '{dump}' => $dump]);
    }

    /**
     * A dump meant to be restored anywhere: consistent without locking the
     * tables, and nothing tied to this server — no tablespace, no GTID.
     */
    public function getDumpScript(string $format): string
    {
        $options = '--single-transaction --routines --triggers --events --hex-blob --no-tablespaces';

        if (!$this->isMariaDB()) {
            $options .= ' --set-gtid-purged=OFF';
        }

        return strtr(<<<'BASH'
            castor_dump() { castor_dumper {options} "$CASTOR_DATABASE"; }
            BASH, ['{options}' => $options]);
    }

    /**
     * MySQL has no RENAME DATABASE, and moving tables across schemas breaks on
     * triggers, so the dump is restored in place once the connections left on
     * the database are closed.
     *
     * The dumps of a recent MariaDB open with a line enabling its sandbox mode,
     * which the MySQL client rejects: it is dropped on the way in.
     */
    public function getRestoreScript(): string
    {
        $filter = $this->isMariaDB() ? 'cat' : <<<'BASH'
            sed -e '1{/999999\\- enable the sandbox mode/d}'
            BASH;

        return strtr(<<<'BASH'
            castor_restore() {
                castor_client -N -e "SELECT id FROM information_schema.processlist WHERE db = '$CASTOR_DATABASE' AND id <> CONNECTION_ID()" \
                    | while read -r id; do castor_client -e "KILL $id" 2>/dev/null || true; done
                castor_client -e "DROP DATABASE IF EXISTS \`$CASTOR_DATABASE\`; CREATE DATABASE \`$CASTOR_DATABASE\`"
                {filter} | castor_client "$CASTOR_DATABASE"
            }
            BASH, ['{filter}' => $filter]);
    }

    protected function getFlavour(): string
    {
        return $this->isMariaDB() ? 'mariadb' : 'mysql';
    }
}
