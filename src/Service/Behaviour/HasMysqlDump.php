<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Behaviour;

/**
 * Dumps and restores for the MySQL family: MySQL and MariaDB share the format,
 * the options and most of the tools, under different names.
 *
 * The class using it provides $rootPassword and $database.
 */
trait HasMysqlDump
{
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
     * tables, with the routines, triggers and events, binary columns in hex,
     * and nothing tied to this server — no tablespace, no GTID.
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
     * triggers: the dump is restored in place, once the connections left on the
     * database are closed — a session whose database was dropped under it
     * answers "No database selected" until it reconnects.
     *
     * The dumps of a recent MariaDB open with a line enabling its sandbox mode,
     * which the MySQL client rejects: it is dropped on the way into MySQL.
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

    abstract protected function isMariaDB(): bool;
}
