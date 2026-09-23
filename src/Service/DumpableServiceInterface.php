<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

/**
 * A service whose data can be dumped to a file and restored from one.
 *
 * The service only says how, as bash run in a throwaway container of its own
 * image: the tools are then those of the server, at its version, and nothing
 * has to be installed on the host. Everything around it — finding the file,
 * compressing, detecting the format of a dump, stopping what uses the database
 * — is shared, see dump_database() and restore_database().
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
     * Bash defining what the two scripts below build on: the client
     * functions, and a "castor_ready" function that succeeds once the server
     * accepts connections over the network.
     */
    public function getConnectionScript(): string;

    /**
     * Bash defining a "castor_dump" function, which writes a dump of the
     * database in the given format on its standard output.
     */
    public function getDumpScript(string $format): string;

    /**
     * Bash defining a "castor_restore" function, which reads a dump on its
     * standard input — already decompressed — and loads it in place of the
     * current content of the database.
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
