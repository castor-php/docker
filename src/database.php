<?php

declare(strict_types=1);

namespace Castor\Docker;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsTask;
use Castor\Docker\Service\DumpableServiceInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

use function Castor\capture;
use function Castor\context;
use function Castor\fs;
use function Castor\io;
use function Castor\run;

/**
 * Decompressing needs no such table: a dump is recognised by its first bytes,
 * see restore_script().
 */
const DUMP_COMPRESSIONS = [
    'gz' => 'gzip -c',
    'zst' => 'zstd -q -T0 -c',
    'xz' => 'xz -T0 -c',
];

/**
 * The "{service}:dump" and "{service}:restore" tasks of a database service.
 *
 * @return iterable<array{task: AsTask, function: \Closure}>
 */
function get_dump_tasks(DumpableServiceInterface $service): iterable
{
    $name = $service->getName();
    $examples = implode(', ', array_map(static fn(string $format): string => '.' . $format, describe_dump_files($service->getDumpFormats())));

    yield [
        'task' => new AsTask('dump', $name, \sprintf('Dump the %s database to a file (%s), or to the standard output', $name, $examples)),
        'function' => static fn(
            #[AsArgument(description: 'The file to write, its extension giving the format and the compression ("-" or nothing for the standard output)')]
            ?string $file = null,
        ): int => dump_database($service, $file) ? 0 : 1,
    ];

    yield [
        'task' => new AsTask('restore', $name, \sprintf('Replace the content of the %s database with a dump, read from a file or from the standard input', $name)),
        'function' => static fn(
            #[AsArgument(description: 'The dump to restore, compressed or not ("-" or nothing for the standard input)')]
            ?string $file = null,
        ): int => restore_database($service, $file) ? 0 : 1,
    ];
}

/**
 * Every format, and the text ones compressed too.
 *
 * @param list<string> $formats
 *
 * @return list<string>
 */
function describe_dump_files(array $formats): array
{
    $files = [];

    foreach ($formats as $format) {
        $files[] = $format;

        if ('sql' === $format) {
            foreach (array_keys(DUMP_COMPRESSIONS) as $compression) {
                $files[] = $format . '.' . $compression;
            }
        }
    }

    return $files;
}

/**
 * "prod.sql.zst" is a zstd-compressed "sql" dump, "prod.dump" an uncompressed
 * "dump".
 *
 * @param list<string> $formats
 *
 * @return array{format: string, compression: ?string}
 */
function resolve_dump_file(string $file, array $formats): array
{
    $extensions = explode('.', strtolower(basename($file)));
    array_shift($extensions);

    $compression = null;

    if ($extensions && \array_key_exists(end($extensions), DUMP_COMPRESSIONS)) {
        $compression = array_pop($extensions);
    }

    $format = end($extensions);

    if (false === $format || !\in_array($format, $formats, true)) {
        throw new \InvalidArgumentException(\sprintf('Cannot tell the format of "%s" from its name: name it after one of %s.', basename($file), implode(', ', array_map(static fn(string $f): string => '.' . $f, describe_dump_files($formats)))));
    }

    return ['format' => $format, 'compression' => $compression];
}

/**
 * The script of the service, compressed if asked to, written to the standard
 * output or to CASTOR_OUTPUT.
 *
 * A file is written under a temporary name and renamed once complete, so a dump
 * failing half-way leaves no truncated file behind the real name. It is handed
 * to the user running castor, since the container writes it as root.
 */
function dump_script(DumpableServiceInterface $service, string $format, ?string $compression): string
{
    $compress = null === $compression ? 'cat' : DUMP_COMPRESSIONS[$compression];

    return implode("\n", [
        'set -eo pipefail',
        $service->getConnectionScript(),
        $service->getDumpScript($format),
        wait_script(),
        <<<BASH
            if [ -z "\${CASTOR_OUTPUT:-}" ]; then
                castor_dump | {$compress}
                exit
            fi

            partial="\$(dirname "\$CASTOR_OUTPUT")/.\$(basename "\$CASTOR_OUTPUT").partial"
            trap 'rm -f "\$partial"' EXIT
            castor_dump | {$compress} > "\$partial"
            chown "\$CASTOR_OWNER" "\$partial"
            mv "\$partial" "\$CASTOR_OUTPUT"
            BASH,
    ]);
}

/**
 * The compression is told from the first bytes rather than from a file name: a
 * dump read from the standard input has none, and a gzipped "prod.sql" is
 * common enough.
 *
 * Read ahead with "dd" one byte at a time: reading a pipe any other way can
 * swallow more than asked, and those bytes would be lost to the rest.
 */
function restore_script(DumpableServiceInterface $service): string
{
    return implode("\n", [
        'set -eo pipefail',
        $service->getConnectionScript(),
        $service->getRestoreScript(),
        wait_script(),
        <<<'BASH'
            if [ -n "${CASTOR_INPUT:-}" ]; then
                exec < "$CASTOR_INPUT"
            fi

            head=$(mktemp)
            dd bs=1 count=6 of="$head" 2>/dev/null

            case "$(od -An -tx1 "$head" | tr -d ' \n')" in
                '') echo 'The dump is empty.' >&2; exit 1 ;;
                1f8b*) decompress='gzip -dc' ;;
                28b52ffd*) decompress='zstd -dc' ;;
                fd377a585a00) decompress='xz -dc' ;;
                425a68*) decompress='bzip2 -dc' ;;
                *) decompress='cat' ;;
            esac

            if ! command -v "${decompress%% *}" >/dev/null; then
                echo "The dump is compressed with ${decompress%% *}, which the image of the service does not have: decompress it first." >&2
                exit 1
            fi

            cat "$head" - | $decompress | castor_restore
            BASH,
    ]);
}

/**
 * Wait for the server to accept connections over the network, which the
 * throwaway container reaches it through.
 *
 * The health check is not enough: the official images initialise a fresh volume
 * with a temporary server on a local socket only, and the health check of MySQL
 * already answers from that one.
 */
function wait_script(): string
{
    return <<<'BASH'
        for attempt in $(seq 1 120); do
            if castor_ready >/dev/null 2>&1; then
                break
            fi

            if [ "$attempt" = 120 ]; then
                echo 'The database does not accept connections.' >&2
                exit 1
            fi

            sleep 0.5
        done
        BASH;
}

/**
 * To the standard output when there is no file (or "-"). A file is written by
 * the throwaway container, which mounts its directory: streaming it through
 * the output of docker would be several times slower.
 */
function dump_database(DumpableServiceInterface $service, ?string $file = null): bool
{
    $toStdout = null === $file || '-' === $file;
    // Whatever the task says must not end up in the dump.
    $io = $toStdout ? io()->getErrorStyle() : io();

    if ($toStdout && \defined('STDOUT') && stream_isatty(\STDOUT)) {
        $io->error(\sprintf('Give the file to write the dump to, or redirect the output: castor %s:dump > dump.sql', $service->getName()));

        return false;
    }

    if ($toStdout) {
        // Starting it may start the router too, which says so on the standard
        // output — in the middle of the dump.
        if (array_diff($service->getDumpServices(), get_running_service_names())) {
            $io->error(\sprintf('The %1$s database is not running: start it first, with castor docker:up %1$s.', $service->getName()));

            return false;
        }

        $format = $service->getDumpFormats()[0];
        $compression = null;
    } else {
        ['format' => $format, 'compression' => $compression] = resolve_dump_file($file, $service->getDumpFormats());
    }

    $started = start_database($service, $io);
    $environment = [];
    $volumes = [];

    if (!$toStdout) {
        $directory = \dirname($file);

        if (!is_dir($directory)) {
            fs()->mkdir($directory);
        }

        $volumes[] = realpath($directory) . ':/castor/output';
        $environment['CASTOR_OUTPUT'] = '/castor/output/' . basename($file);
        $environment['CASTOR_OWNER'] = get_host_owner();
    }

    try {
        $output = $toStdout ? static function (string $type, string $bytes, Process $process): void {
            fwrite(Process::OUT === $type ? \STDOUT : \STDERR, $bytes);
            // Kept in memory otherwise, the whole dump of it.
            $process->clearOutput();
            $process->clearErrorOutput();
        } : null;

        run_database_container($service, dump_script($service, $format, $compression), $environment, $volumes, output: $output);
    } finally {
        if ($started) {
            docker_compose(['stop', ...$service->getDumpServices()], context()->withQuiet());
        }
    }

    if (!$toStdout) {
        $io->success(\sprintf('The %s database is dumped to %s (%s).', $service->getName(), $file, format_bytes((int) filesize($file))));
    }

    return true;
}

/**
 * From the standard input when there is no file (or "-").
 *
 * The containers depending on the database are stopped for the duration: an
 * application would otherwise hold connections on a database being dropped, or
 * read one half restored, and a worker losing its connection exits for good.
 */
function restore_database(DumpableServiceInterface $service, ?string $file = null): bool
{
    $fromStdin = null === $file || '-' === $file;
    $environment = [];
    $volumes = [];
    $input = null;

    if ($fromStdin) {
        if (\defined('STDIN') && stream_isatty(\STDIN)) {
            io()->error(\sprintf('Give the dump to restore, or pipe it into the task: castor %s:restore < dump.sql', $service->getName()));

            return false;
        }

        $input = \STDIN;
    } else {
        if (!is_file($file)) {
            io()->error(\sprintf('The file "%s" does not exist.', $file));

            return false;
        }

        $volumes[] = realpath($file) . ':/castor/input:ro';
        $environment['CASTOR_INPUT'] = '/castor/input';
    }

    start_database($service, io());
    $dependents = get_running_dependents($service->getDumpServices());

    if ($dependents) {
        io()->comment(\sprintf('Stopping %s while the database is restored.', implode(', ', $dependents)));
        docker_compose(['stop', ...$dependents], context()->withQuiet());
    }

    try {
        run_database_container($service, restore_script($service), $environment, $volumes, $input);
    } finally {
        if ($dependents) {
            docker_compose(['up', '--detach', '--no-deps', '--no-recreate', ...$dependents], context()->withQuiet());
        }
    }

    io()->success(\sprintf('The %s database is restored from %s.', $service->getName(), $fromStdin ? 'the standard input' : $file));

    return true;
}

/**
 * Returns whether any had to be started, so a dump can put back the state it
 * found.
 */
function start_database(DumpableServiceInterface $service, SymfonyStyle $io): bool
{
    $needed = $service->getDumpServices();

    if (!array_diff($needed, get_running_service_names())) {
        return false;
    }

    $io->comment(\sprintf('Starting %s.', implode(', ', $needed)));
    docker_compose(['up', '--detach', '--wait', ...$needed], context()->withQuiet());

    return true;
}

/**
 * @param list<string> $services
 *
 * @return list<string>
 */
function get_running_dependents(array $services): array
{
    $running = get_running_service_names();

    return array_values(array_intersect(find_dependents(get_services(), $services), $running));
}

/**
 * The compose services declaring a "depends_on" on one of the given ones.
 *
 * @param array<string, array{depends_on?: array<string, mixed>|list<string>}> $composeServices
 * @param list<string>                                                          $services
 *
 * @return list<string>
 */
function find_dependents(array $composeServices, array $services): array
{
    $dependents = [];

    foreach ($composeServices as $name => $definition) {
        $dependsOn = $definition['depends_on'] ?? [];
        $dependsOn = array_is_list($dependsOn) ? $dependsOn : array_keys($dependsOn);

        if (!\in_array($name, $services, true) && array_intersect($services, $dependsOn)) {
            $dependents[] = (string) $name;
        }
    }

    sort($dependents);

    return $dependents;
}

/**
 * What tells a database holding data from one that was never started.
 */
function has_database_container(DumpableServiceInterface $service): bool
{
    return '' !== trim(docker_compose(['ps', '--all', '--quiet', $service->getName()], context()->withQuiet()->withAllowFailure())->getOutput());
}

/**
 * Run a script in a throwaway container of the image the service runs.
 *
 * Plain "docker run" rather than "docker compose run": a compose container
 * would carry the labels of the service, the router's among them, and the
 * domain of the service would be routed to it. "--volumes-from" gives it the
 * data directory of the server, where ClickHouse writes its backups.
 *
 * The variables are handed over by name only, so no credential shows in the
 * process list.
 *
 * @param array<string, string> $environment
 * @param list<string>          $volumes
 * @param resource|null         $input
 */
function run_database_container(
    DumpableServiceInterface $service,
    string $script,
    array $environment = [],
    array $volumes = [],
    mixed $input = null,
    ?callable $output = null,
): void {
    $c = context();
    $name = $service->getName();
    $container = trim(explode("\n", docker_compose(['ps', '--quiet', $name], $c->withQuiet())->getOutput())[0]);

    if ('' === $container) {
        throw new \RuntimeException(\sprintf('The "%s" service is not running.', $name));
    }

    $image = trim(capture(['docker', 'inspect', '--format', '{{.Config.Image}}', $container], context: $c));
    $environment = $service->getDumpEnvironment() + $environment;

    $command = ['docker', 'run', '--rm', '--network', get_project_network($c), '--volumes-from', $container];

    if (null !== $input) {
        $command[] = '--interactive';
    }

    foreach ($volumes as $volume) {
        $command[] = '--volume';
        $command[] = $volume;
    }

    foreach (array_keys($environment) as $variable) {
        $command[] = '--env';
        $command[] = $variable;
    }

    array_push($command, '--entrypoint', 'bash', $image, '-c', $script);

    $c = $c->withTimeout(null)->withEnvironment($environment);

    if (null !== $input) {
        $c = $c->withInput($input);
    }

    $process = run($command, context: $c->withAllowFailure(), callback: $output);

    // The failed command would be the whole script: what went wrong is in
    // what the tools printed.
    if (!$process->isSuccessful()) {
        throw new \RuntimeException(\sprintf('The operation failed on the "%s" database, see the output above.', $name));
    }
}

/**
 * Who a file written by a container is handed to: the user running castor.
 */
function get_host_owner(): string
{
    $uid = \function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    $gid = \function_exists('posix_getegid') ? posix_getegid() : getmygid();

    return $uid . ':' . $gid;
}

/**
 * The format restoring fastest.
 */
function get_copy_file_name(DumpableServiceInterface $service): string
{
    $formats = $service->getDumpFormats();

    if (\in_array('dump', $formats, true)) {
        return $service->getName() . '.dump';
    }

    return $service->getName() . '.' . $formats[0] . ('sql' === $formats[0] ? '.zst' : '');
}

/**
 * Each goes through a dump: the checkout keeps running, and the worktree may
 * run another version of the server. The dump is restored by the worktree
 * itself, with its own castor.php, so it lands in whatever the branch declares.
 *
 * @param list<DumpableServiceInterface> $services
 */
function copy_databases_to_worktree(array $services, string $path): void
{
    $directory = sys_get_temp_dir() . '/castor-docker-copy-' . bin2hex(random_bytes(4));
    fs()->mkdir($directory);

    try {
        foreach ($services as $service) {
            $name = $service->getName();

            if (!has_database_container($service)) {
                io()->comment(\sprintf('Nothing to copy from "%s": it was never started here.', $name));

                continue;
            }

            $file = $directory . '/' . get_copy_file_name($service);

            dump_database($service, $file);

            $restore = run([castor_binary(), $name . ':restore', $file], context: in_worktree($path)->withAllowFailure());

            if (!$restore->isSuccessful()) {
                io()->warning(\sprintf('The "%s" database could not be restored in the worktree, it starts empty.', $name));
            }

            fs()->remove($file);
        }
    } finally {
        fs()->remove($directory);
    }
}
