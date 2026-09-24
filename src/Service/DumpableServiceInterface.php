<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

/**
 * A service whose data can be dumped to a file and restored from one.
 *
 * It only says how, as bash run in a throwaway container of its own image, so
 * the tools are those of the server at its version and nothing is needed on the
 * host. The rest is shared, see dump_database() and restore_database().
 */
interface DumpableServiceInterface extends ServiceInterface
{
    /**
     * The formats a dump can be written in, named by their file extension
     * ("sql", "dump", "zip"). The first one is what goes to the standard
     * output.
     *
     * @return non-empty-list<string>
     */
    public function getDumpFormats(): array;

    /**
     * Bash defining what the two scripts below build on: the client functions,
     * and a "castor_ready" succeeding once the server accepts connections.
     */
    public function getConnectionScript(): string;

    /**
     * Bash defining a "castor_dump" function, which writes a dump of the
     * database in the given format on its standard output.
     */
    public function getDumpScript(string $format): string;

    /**
     * Bash defining a "castor_restore" function, reading an already
     * decompressed dump on its standard input.
     */
    public function getRestoreScript(): string;

    /**
     * What the scripts connect with: host, credentials, database.
     *
     * @return array<string, string>
     */
    public function getDumpEnvironment(): array;

    /**
     * The compose services that have to run for a dump or a restore, this one
     * first.
     *
     * @return non-empty-list<string>
     */
    public function getDumpServices(): array;
}
