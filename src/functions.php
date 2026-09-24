<?php

declare(strict_types=1);

namespace Castor\Docker;

use Castor\Attribute\AsListener;
use Castor\Console\Output\VerbosityLevel;
use Castor\Context;
use Castor\Descriptor\TaskDescriptor;
use Castor\Docker\Attribute\AsDockerComposeBuilder;
use Castor\Docker\Event\DockerComposeBuilderEvent;
use Castor\Docker\Event\DockerComposeWriteEvent;
use Castor\Docker\Event\RegisterServiceEvent;
use Castor\Docker\Event\RegisterServiceInstallerEvent;
use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Installer\ClickhouseInstaller;
use Castor\Docker\Installer\DatabaseServiceInstaller;
use Castor\Docker\Installer\ElasticsearchInstaller;
use Castor\Docker\Installer\InputType;
use Castor\Docker\Installer\InstallerOptions;
use Castor\Docker\Installer\ListenerEditor;
use Castor\Docker\Installer\MailpitInstaller;
use Castor\Docker\Installer\MariaDBInstaller;
use Castor\Docker\Installer\MeilisearchInstaller;
use Castor\Docker\Installer\MercureInstaller;
use Castor\Docker\Installer\MySQLInstaller;
use Castor\Docker\Installer\NodeInstaller;
use Castor\Docker\Installer\PostgresInstaller;
use Castor\Docker\Installer\RabbitMQInstaller;
use Castor\Docker\Installer\RedisInstaller;
use Castor\Docker\Installer\RustFSInstaller;
use Castor\Docker\Installer\RustInstaller;
use Castor\Docker\Installer\ServiceInstaller;
use Castor\Docker\Installer\SymfonyInstaller;
use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\DatabaseServiceInterface;
use Castor\Docker\Service\PHPService;
use Castor\Docker\Service\ServiceInterface;
use Castor\Event\ContextCreatedEvent;
use Castor\Event\FunctionsResolvedEvent;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Terminal;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

use function Castor\capture;
use function Castor\context;
use function Castor\dispatch;
use function Castor\fs;
use function Castor\get_cache;
use function Castor\input;
use function Castor\io;
use function Castor\run;
use function Castor\variable;
use function Castor\yaml_dump;
use function Castor\yaml_parse;

/**
 * Read from the "docker_profiles" context data, so a project with more than the
 * two built-in profiles does not have to pass --profiles to every task.
 *
 * @return list<string>
 */
function get_default_profiles(?Context $c = null): array
{
    $profiles = ($c ?? context())->data['docker_profiles'] ?? null;

    if (\is_array($profiles) && [] !== $profiles) {
        return array_values($profiles);
    }

    return ['default'];
}

/**
 * @param list<string> $subCommand
 * @param list<string> $profiles
 * @param ?string      $progress    "auto", "tty", "plain", "json" or "quiet", left to compose when null
 */
function docker_compose(array $subCommand, ?Context $c = null, array $profiles = [], ?string $progress = null): Process
{
    $c ??= context();
    $profiles = $profiles ?: get_default_profiles($c);

    $projectName = get_project_name($c);

    $c = $c
        ->withTimeout(null)
        ->withEnvironment([
            // Takes precedence over the "name" of compose.yaml, so the context
            // stays the single source of truth for everything derived from it.
            'COMPOSE_PROJECT_NAME' => $projectName,
            'PROJECT_NAME' => $projectName,
            'PROJECT_ROOT_DOMAIN' => $c->data['root_domain'] ?? 'local.test',
            'REGISTRY' => variable('registry'),
        ])
    ;

    $command = [
        'docker',
        'compose',
    ];

    if (null !== $progress) {
        $command[] = '--progress';
        $command[] = $progress;
    }

    foreach ($profiles as $profile) {
        $command[] = '--profile';
        $command[] = $profile;
    }

    $command[] = '-f';
    $command[] = $c->workingDirectory . '/compose.yaml';

    $command = array_merge($command, $subCommand);

    // The router is not a service of this file: it joins the project network
    // from the outside, so it is attached after "up" and detached before "down".
    $network = get_project_network($c);
    $subCommandName = $subCommand[0] ?? null;

    if ('down' === $subCommandName) {
        disconnect_router_from_network($network);
    }

    if ('up' === $subCommandName) {
        autostart_router($c);
    }

    $process = run($command, context: $c);

    if ('up' === $subCommandName) {
        connect_router_to_network($network, get_project_domains($c));
    }

    if ('stop' === $subCommandName || 'down' === $subCommandName) {
        autostop_router($c);
    }

    return $process;
}

/**
 * Falls back to the "name" of compose.yaml: the context is only enriched when
 * castor instantiates a declared #[AsContext], and a project that declares
 * none boots on a bare one.
 */
function get_project_name(?Context $c = null): string
{
    $c ??= context();

    if (isset($c->data['project_name'])) {
        return $c->data['project_name'];
    }

    $composeFile = $c->workingDirectory . '/compose.yaml';

    if (file_exists($composeFile) && ($content = file_get_contents($composeFile))) {
        $name = yaml_parse($content)['name'] ?? null;

        if (\is_string($name) && '' !== $name) {
            return $name;
        }
    }

    return basename($c->workingDirectory);
}

function get_project_network(?Context $c = null): string
{
    $c ??= context();

    return get_project_name($c) . '_default';
}

/**
 * Read from the compose files rather than asked to docker: this feeds the shell
 * completion, which must answer instantly and without a running daemon.
 *
 * @return list<string>
 */
function get_compose_service_names(?Context $c = null): array
{
    $c ??= context();
    $names = [];

    foreach (['compose.generated.yaml', 'compose.yaml', 'compose.override.yaml'] as $file) {
        $path = $c->workingDirectory . '/' . $file;

        if (!file_exists($path) || !($content = file_get_contents($path))) {
            continue;
        }

        $parsed = yaml_parse($content);
        $services = \is_array($parsed) && \is_array($parsed['services'] ?? null) ? $parsed['services'] : [];

        foreach (array_keys($services) as $name) {
            if (\is_string($name)) {
                $names[$name] = true;
            }
        }
    }

    ksort($names);

    return array_keys($names);
}

/**
 * @return list<string>
 */
function autocomplete_service_name(CompletionInput $input): array
{
    return get_compose_service_names();
}

/**
 * The services declared in castor.php, which are fewer than the containers
 * they generate.
 *
 * @return list<string>
 */
function autocomplete_registered_service_name(CompletionInput $input): array
{
    $names = array_map(
        static fn(ServiceInterface $service): string => $service->getName(),
        collect_services(),
    );

    sort($names);

    return array_values(array_unique($names));
}

/**
 * @return list<string>
 */
function autocomplete_installer_name(CompletionInput $input): array
{
    $names = array_keys(collect_service_installers());
    sort($names);

    return $names;
}

/**
 * The workers to offer depend on the application the task belongs to, which an
 * attribute cannot carry: it is read back from the namespace of the command
 * being completed.
 *
 * @return list<string>
 */
function autocomplete_worker_name(CompletionInput $input): array
{
    $command = $input->getFirstArgument();

    if (null === $command || false === ($application = strstr($command, ':', true))) {
        return [];
    }

    foreach (collect_services() as $service) {
        if ($service instanceof PHPService && $service->getName() === $application) {
            return $service->getWorkerNames();
        }
    }

    return [];
}

/**
 * Every domain routed to a container of this project.
 *
 * Taken from the "caddy" labels rather than from the services, so a domain
 * declared straight on the builder counts too.
 *
 * @return list<string>
 */
function get_project_domains(?Context $c = null): array
{
    $c ??= context();
    $composeFile = $c->workingDirectory . '/compose.generated.yaml';

    if (!file_exists($composeFile) || !($content = file_get_contents($composeFile))) {
        return [];
    }

    $compose = yaml_parse($content);
    $domains = [];

    foreach ($compose['services'] ?? [] as $service) {
        foreach ($service['labels'] ?? [] as $label) {
            // "caddy=a.test b.test", "caddy_1=http://a.test" for withHttpAccess().
            if (!\is_string($label) || 1 !== preg_match('/^caddy(?:_\d+)?=(.+)$/', $label, $matches)) {
                continue;
            }

            foreach (preg_split('/\s+/', trim($matches[1])) ?: [] as $domain) {
                $domain = (string) preg_replace('#^https?://#', '', $domain);

                // A wildcard or a matcher would make the whole
                // "docker network connect" fail.
                if (1 !== preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*$/', $domain)) {
                    continue;
                }

                $domains[$domain] = true;
            }
        }
    }

    return array_keys($domains);
}

/**
 * Every HTTP(S) URL the project serves, keyed by the compose service serving it.
 *
 * Read from the "caddy" labels of every compose file, so a domain declared by a
 * service, a builder or compose.override.yaml is listed the same way.
 *
 * @return array<string, list<string>>
 */
function get_project_urls(?Context $c = null): array
{
    $c ??= context();
    $urls = [];

    foreach (['compose.generated.yaml', 'compose.yaml', 'compose.override.yaml'] as $file) {
        $path = $c->workingDirectory . '/' . $file;

        if (!file_exists($path) || !($content = file_get_contents($path))) {
            continue;
        }

        $parsed = yaml_parse($content);
        $services = \is_array($parsed) && \is_array($parsed['services'] ?? null) ? $parsed['services'] : [];

        foreach ($services as $name => $service) {
            if (!\is_string($name) || !\is_array($service)) {
                continue;
            }

            foreach (normalize_compose_labels($service['labels'] ?? null) as $label => $value) {
                if (1 !== preg_match('/^caddy(?:_\d+)?$/', $label)) {
                    continue;
                }

                foreach (preg_split('/\s+/', trim($value)) ?: [] as $domain) {
                    $scheme = str_starts_with($domain, 'http://') ? 'http' : 'https';
                    $domain = (string) preg_replace('#^https?://#', '', $domain);

                    // A caddy matcher or an uninterpolated placeholder is no URL.
                    if (1 !== preg_match('/^(\*\.)?[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*$/', $domain)) {
                        continue;
                    }

                    $urls[$name]["{$scheme}://{$domain}"] = true;
                }
            }
        }
    }

    ksort($urls);

    return array_map(array_keys(...), $urls);
}

/**
 * Compose accepts labels either as a map or as a list of "key=value" strings.
 *
 * @return array<string, string>
 */
function normalize_compose_labels(mixed $labels): array
{
    if (!\is_array($labels)) {
        return [];
    }

    $normalized = [];

    foreach ($labels as $key => $value) {
        if (\is_string($key)) {
            $normalized[$key] = (string) $value;

            continue;
        }

        if (!\is_string($value) || !str_contains($value, '=')) {
            continue;
        }

        [$name, $labelValue] = explode('=', $value, 2);
        $normalized[$name] = $labelValue;
    }

    return $normalized;
}

/**
 * The compose services of this project that have a running container.
 *
 * @return list<string>
 */
function get_running_service_names(?Context $c = null): array
{
    $c ??= context();

    try {
        $output = trim(capture([
            'docker', 'ps',
            '--filter', 'label=com.docker.compose.project=' . get_project_name($c),
            '--format', '{{.Label "com.docker.compose.service"}}',
        ], context: $c->withQuiet()->withAllowFailure()));
    } catch (\Throwable) {
        return [];
    }

    if ('' === $output) {
        return [];
    }

    $names = array_values(array_filter(array_map(trim(...), explode("\n", $output))));
    sort($names);

    return array_values(array_unique($names));
}

/**
 * Parse a size the way the docker CLI writes it — "1.26GB", "254.1MiB", "0B".
 *
 * Docker prints disk sizes in SI units and memory in binary ones, hence both
 * scales.
 */
function parse_docker_size(string $size): ?int
{
    if (!preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*([a-zA-Z]*)\s*$/', $size, $matches)) {
        return null;
    }

    $units = [
        '' => 1,
        'b' => 1,
        'kb' => 1000,
        'mb' => 1000 ** 2,
        'gb' => 1000 ** 3,
        'tb' => 1000 ** 4,
        'pb' => 1000 ** 5,
        'kib' => 1024,
        'mib' => 1024 ** 2,
        'gib' => 1024 ** 3,
        'tib' => 1024 ** 4,
        'pib' => 1024 ** 5,
    ];

    $unit = strtolower($matches[2]);

    if (!isset($units[$unit])) {
        return null;
    }

    return (int) round((float) $matches[1] * $units[$unit]);
}

/**
 * Parse a percentage as the docker CLI writes it — "99.27%", or "--".
 */
function parse_docker_percent(string $percent): ?float
{
    if (!preg_match('/^\s*(\d+(?:\.\d+)?)\s*%\s*$/', $percent, $matches)) {
        return null;
    }

    return (float) $matches[1];
}

/**
 * Render a byte count the way docker renders one.
 */
function format_bytes(int|float $bytes): string
{
    $units = ['B', 'kB', 'MB', 'GB', 'TB', 'PB'];
    $value = (float) $bytes;
    $unit = 0;

    while (abs($value) >= 1000 && $unit < \count($units) - 1) {
        $value /= 1000;
        ++$unit;
    }

    return \sprintf('%.4g%s', $value, $units[$unit]);
}

/**
 * Parse the "key=value,key=value" label list the docker CLI prints.
 *
 * @return array<string, string>
 */
function parse_docker_labels(string $labels): array
{
    $parsed = [];

    foreach (explode(',', $labels) as $label) {
        $pair = explode('=', $label, 2);

        if (2 === \count($pair) && '' !== $pair[0]) {
            $parsed[$pair[0]] = $pair[1];
        }
    }

    return $parsed;
}

/**
 * Every container compose created for this project, running or not.
 *
 * $withSize makes docker walk the filesystem of each container to measure its
 * writable layer, so only the disk usage task asks for it.
 *
 * @return list<array{id: string, service: string, oneOff: bool, state: string, status: string, size: ?int, image: string}>
 */
function get_project_containers(bool $withSize = false, ?Context $c = null): array
{
    $c ??= context();

    // Read field by field rather than as JSON: the "Labels" of the JSON output
    // is a flat "key=value,key=value" string, ambiguous for a label holding a
    // comma — com.docker.compose.project.config_files does.
    $format = implode("\t", [
        '{{.ID}}',
        '{{.Label "com.docker.compose.service"}}',
        '{{.Label "com.docker.compose.oneoff"}}',
        '{{.State}}',
        '{{.Status}}',
        '{{.Size}}',
        '{{.Image}}',
    ]);

    $command = [
        'docker', 'ps',
        '--all',
        '--filter', 'label=com.docker.compose.project=' . get_project_name($c),
        '--format', $format,
    ];

    if ($withSize) {
        $command[] = '--size';
    }

    try {
        $output = trim(capture($command, context: $c->withQuiet()->withAllowFailure()->withTimeout(null)));
    } catch (\Throwable) {
        return [];
    }

    $containers = [];

    foreach (explode("\n", $output) as $line) {
        $fields = explode("\t", trim($line));

        if (7 !== \count($fields) || '' === $fields[0]) {
            continue;
        }

        [$id, $service, $oneOff, $state, $status, $size, $image] = $fields;

        $containers[] = [
            'id' => $id,
            'service' => '' !== $service ? $service : $image,
            'oneOff' => 'True' === $oneOff,
            'state' => $state,
            'status' => $status,
            // "49.2kB (virtual 1.36GB)": only the first number is the
            // container's, the rest is the image it shares with its siblings.
            'size' => parse_docker_size(explode(' ', $size)[0]),
            'image' => $image,
        ];
    }

    usort($containers, fn(array $a, array $b) => [$a['service'], $a['id']] <=> [$b['service'], $b['id']]);

    return $containers;
}

/**
 * What the given containers are consuming right now, keyed by container id.
 *
 * @param list<string> $ids only running containers: docker has nothing to
 *                          sample on a stopped one
 *
 * @return array<string, array{cpu: ?float, memory: ?int, memoryLimit: ?int, memoryPercent: ?float, netIn: ?int, netOut: ?int, blockIn: ?int, blockOut: ?int, pids: ?int}>
 */
function get_container_stats(array $ids, ?Context $c = null): array
{
    // "docker stats" with no argument samples every container of the daemon.
    if (!$ids) {
        return [];
    }

    $c ??= context();

    try {
        $output = trim(capture(
            array_merge(['docker', 'stats', '--no-stream', '--format', '{{json .}}'], $ids),
            context: $c->withQuiet()->withAllowFailure()->withTimeout(null),
        ));
    } catch (\Throwable) {
        return [];
    }

    $stats = [];

    foreach (explode("\n", $output) as $line) {
        $sample = json_decode(trim($line), true);

        if (!\is_array($sample) || !\is_string($sample['ID'] ?? null)) {
            continue;
        }

        $memory = array_map(trim(...), explode('/', $sample['MemUsage'] ?? ''));
        $network = array_map(trim(...), explode('/', $sample['NetIO'] ?? ''));
        $block = array_map(trim(...), explode('/', $sample['BlockIO'] ?? ''));

        $stats[$sample['ID']] = [
            'cpu' => parse_docker_percent($sample['CPUPerc'] ?? ''),
            'memory' => parse_docker_size($memory[0]),
            'memoryLimit' => parse_docker_size($memory[1] ?? ''),
            'memoryPercent' => parse_docker_percent($sample['MemPerc'] ?? ''),
            'netIn' => parse_docker_size($network[0]),
            'netOut' => parse_docker_size($network[1] ?? ''),
            'blockIn' => parse_docker_size($block[0]),
            'blockOut' => parse_docker_size($block[1] ?? ''),
            'pids' => is_numeric($sample['PIDs'] ?? null) ? (int) $sample['PIDs'] : null,
        ];
    }

    return $stats;
}

/**
 * The images and volumes of this project, and the disk they take.
 *
 * "docker system df" is the only place docker reports the size of a volume or
 * the layers an image shares, and it reports them for the whole daemon.
 *
 * Images carry no compose label, so they are recognised by name: the
 * "<project>-<service>" this plugin builds, and the image every project
 * container was started from.
 *
 * @return array{images: list<array{name: string, size: ?int, exclusive: ?int, containers: int}>, volumes: list<array{name: string, size: ?int, links: int}>}
 */
function get_project_disk_usage(?Context $c = null): array
{
    $c ??= context();
    $empty = ['images' => [], 'volumes' => []];

    try {
        // Measuring every volume of the daemon takes a while, so no timeout.
        $output = capture(
            ['docker', 'system', 'df', '--verbose', '--format', '{{json .}}'],
            context: $c->withQuiet()->withAllowFailure()->withTimeout(null),
        );
    } catch (\Throwable) {
        return $empty;
    }

    $usage = json_decode(trim($output), true);

    if (!\is_array($usage)) {
        return $empty;
    }

    $project = get_project_name($c);

    // Not matched on the id: "docker system df" reports the digest of the image
    // index, a container that of the image configuration.
    $imageNames = [$project => true];

    foreach (get_compose_service_names($c) as $service) {
        $imageNames[$project . '-' . $service] = true;
    }

    foreach (get_project_containers(c: $c) as $container) {
        // A container whose image lost its tag names it by id, which matches
        // nothing here.
        if ('' !== $container['image'] && !preg_match('/^[0-9a-f]{12,64}$/', $container['image'])) {
            $imageNames[$container['image']] = true;
        }
    }

    $images = [];

    foreach ($usage['Images'] ?? [] as $image) {
        $repository = $image['Repository'] ?? '';
        $tag = $image['Tag'] ?? '';

        // The plugin builds untagged images, docker tags what it pulls.
        if (!isset($imageNames[$repository]) && !isset($imageNames[$repository . ':' . $tag])) {
            continue;
        }

        $images[] = [
            'name' => '' === $tag || '<none>' === $tag ? $repository : $repository . ':' . $tag,
            'size' => parse_docker_size($image['Size'] ?? ''),
            'exclusive' => parse_docker_size($image['UniqueSize'] ?? ''),
            'containers' => (int) ($image['Containers'] ?? 0),
        ];
    }

    $volumes = [];

    foreach ($usage['Volumes'] ?? [] as $volume) {
        $labels = parse_docker_labels($volume['Labels'] ?? '');

        if (($labels['com.docker.compose.project'] ?? null) !== $project) {
            continue;
        }

        $volumes[] = [
            'name' => $volume['Name'] ?? '',
            'size' => parse_docker_size($volume['Size'] ?? ''),
            'links' => (int) ($volume['Links'] ?? 0),
        ];
    }

    usort($images, fn(array $a, array $b) => $a['name'] <=> $b['name']);
    usort($volumes, fn(array $a, array $b) => $a['name'] <=> $b['name']);

    return ['images' => $images, 'volumes' => $volumes];
}

/**
 * @return array{cpus: ?int, memory: ?int}
 */
function get_docker_host_resources(?Context $c = null): array
{
    $c ??= context();

    try {
        $output = trim(capture(
            ['docker', 'info', '--format', "{{.NCPU}}\t{{.MemTotal}}"],
            context: $c->withQuiet()->withAllowFailure(),
        ));
    } catch (\Throwable) {
        return ['cpus' => null, 'memory' => null];
    }

    $fields = explode("\t", $output);

    return [
        'cpus' => is_numeric($fields[0]) ? (int) $fields[0] : null,
        'memory' => is_numeric($fields[1] ?? null) ? (int) $fields[1] : null,
    ];
}

/**
 * A context for a command that wants a terminal — a shell, a database session.
 *
 * castor's toInteractive() throws without a TTY, which is too strict here: a
 * piped "castor app:bash" is a scripted shell and works fine, it just must not
 * ask for one. The rest of toInteractive() is kept either way: no timeout, and
 * a non-zero exit is the last command of the shell, not a failed task.
 */
function interactive_context(?Context $c = null): Context
{
    $c ??= context();

    if ($c->supportsInteraction()) {
        return $c->toInteractive();
    }

    return $c->withTimeout(null)->withAllowFailure();
}

/**
 * Silence the "Container app-builder-run-8c9d8bef Creating" chatter every
 * "docker compose run" prints in front of the output actually asked for.
 */
function get_compose_progress(?Context $c = null): ?string
{
    $c ??= context();

    return $c->verbosityLevel->value > VerbosityLevel::NORMAL->value ? null : 'quiet';
}

/**
 * The size of the terminal castor runs in, as the COLUMNS and LINES a command
 * in a container is given.
 *
 * castor runs docker behind a pty born 0x0, and the tty compose allocates in
 * the container inherits those zeroes: everything sizing its output to the
 * terminal then falls back to 80 columns. COLUMNS and LINES are read before
 * the tty is asked, so handing them over restores the real width.
 *
 * @param null|array{int, int} $size defaults to the size of the terminal castor runs in
 *
 * @return array<string, string>
 */
function get_terminal_size_environment(?Context $c = null, ?array $size = null): array
{
    $c ??= context();

    // docker keeps a real tty in sync, including mid-command resizes that a
    // fixed COLUMNS would hide.
    if ($c->tty) {
        return [];
    }

    $size ??= get_host_terminal_size();

    if (null === $size) {
        return [];
    }

    return [
        'COLUMNS' => (string) $size[0],
        'LINES' => (string) $size[1],
    ];
}

/**
 * @return null|array{int, int}
 */
function get_host_terminal_size(): ?array
{
    if (!\defined('STDOUT') || !stream_isatty(\STDOUT)) {
        return null;
    }

    $terminal = new Terminal();

    return [$terminal->getWidth(), $terminal->getHeight()];
}

/**
 * Run a one-off command in a service container ("docker compose run --rm").
 *
 * A list of tokens is handed to docker untouched, so an argument holding a
 * space, a quote or a "$" arrives whole. A string goes through a shell in the
 * container instead, which is what a command written to use one needs.
 *
 * @param string|array<int, string> $runCommand
 * @param array<string, string>     $environment extra variables, passed as "-e KEY=VALUE"
 * @param list<string>          $ports       extra published ports, passed as "-p 10080:10080"
 */
function docker_compose_run(
    string|array $runCommand,
    string $service,
    ?Context $c = null,
    bool $noDeps = true,
    ?string $workDir = null,
    bool $portMapping = false,
    array $environment = [],
    ?string $entrypoint = null,
    array $ports = [],
): Process {
    $command = [
        'run',
        '--rm',
    ];

    if ($noDeps) {
        $command[] = '--no-deps';
    }

    if ($portMapping) {
        $command[] = '--service-ports';
    }

    if (null !== $workDir) {
        $command[] = '-w';
        $command[] = $workDir;
    }

    if (null !== $entrypoint) {
        $command[] = '--entrypoint';
        $command[] = $entrypoint;
    }

    foreach ($environment + get_terminal_size_environment($c) as $key => $value) {
        $command[] = '-e';
        $command[] = "{$key}={$value}";
    }

    foreach ($ports as $port) {
        $command[] = '-p';
        $command[] = $port;
    }

    $command[] = $service;

    foreach (to_container_command($runCommand) as $token) {
        $command[] = $token;
    }

    try {
        return docker_compose($command, c: $c, progress: get_compose_progress($c));
    } catch (ExceptionInterface $e) {
        // The process exception only names "docker compose", not the container.
        throw new \RuntimeException(\sprintf('The command "%s" failed in the "%s" service.', describe_command($runCommand), $service), previous: $e);
    }
}

/**
 * Run a command in the container a service is already running
 * ("docker compose exec"), rather than in a throwaway one.
 *
 * @param string|array<int, string> $command
 * @param array<string, string>     $environment extra variables, passed as "-e KEY=VALUE"
 */
function docker_compose_exec(
    string|array $command,
    string $service,
    ?Context $c = null,
    ?string $workDir = null,
    array $environment = [],
    bool $privileged = false,
): Process {
    $arguments = ['exec'];

    if (null !== $workDir) {
        $arguments[] = '-w';
        $arguments[] = $workDir;
    }

    if ($privileged) {
        $arguments[] = '--privileged';
    }

    foreach ($environment + get_terminal_size_environment($c) as $key => $value) {
        $arguments[] = '-e';
        $arguments[] = "{$key}={$value}";
    }

    $arguments[] = $service;

    foreach (to_container_command($command) as $token) {
        $arguments[] = $token;
    }

    try {
        return docker_compose($arguments, c: $c, progress: get_compose_progress($c));
    } catch (ExceptionInterface $e) {
        throw new \RuntimeException(\sprintf('The command "%s" failed in the running "%s" service.', describe_command($command), $service), previous: $e);
    }
}

/**
 * A list is exec'd as it is; a string gets a shell, since it may hold a pipe or
 * a "&&", and "exec" replaces that shell so signals reach the command.
 *
 * @param string|array<int, string> $command
 *
 * @return list<string>
 */
function to_container_command(string|array $command): array
{
    if (\is_array($command)) {
        return array_values($command);
    }

    return ['/bin/sh', '-c', "exec {$command}"];
}

/**
 * @param string|array<int, string> $command
 */
function describe_command(string|array $command): string
{
    return \is_array($command) ? implode(' ', $command) : $command;
}

/**
 * @param string|array<int, string> $runCommand
 * @param array<string, string>     $environment
 * @param list<string>              $ports
 */
function docker_exit_code(
    string|array $runCommand,
    string $service = 'builder',
    ?Context $c = null,
    bool $noDeps = true,
    ?string $workDir = null,
    bool $portMapping = false,
    array $environment = [],
    ?string $entrypoint = null,
    array $ports = [],
): int {
    // Makes docker_compose_run() return instead of throwing: the caller wants
    // the exit code.
    $c = ($c ?? context())->withAllowFailure();

    $process = docker_compose_run(
        runCommand: $runCommand,
        service: $service,
        c: $c,
        noDeps: $noDeps,
        workDir: $workDir,
        portMapping: $portMapping,
        environment: $environment,
        entrypoint: $entrypoint,
        ports: $ports,
    );

    return $process->getExitCode() ?? 0;
}

/**
 * The log file docker writes for each container of the project, or of one
 * service, keyed by container name. Stopped containers are included, and a
 * container whose logging driver keeps no file comes back with an empty path.
 *
 * @return array<string, string>
 */
function get_container_log_paths(?string $service = null, ?Context $c = null): array
{
    $c ??= context();

    $command = ['ps', '--all', '--quiet'];

    if (null !== $service) {
        $command[] = $service;
    }

    // Every profile: a container of the "builder" profile has logs too.
    $ids = array_values(array_filter(array_map(
        trim(...),
        explode("\n", trim(docker_compose($command, $c->withQuiet()->withAllowFailure(), profiles: ['*'])->getOutput())),
    )));

    if (!$ids) {
        return [];
    }

    $found = capture(
        ['docker', 'inspect', '--format', '{{.Name}}{{"\t"}}{{.LogPath}}', ...$ids],
        context: $c->withQuiet()->withAllowFailure(),
    );

    $paths = [];

    foreach (explode("\n", trim($found)) as $line) {
        if ('' === trim($line)) {
            continue;
        }

        [$name, $path] = array_pad(explode("\t", $line, 2), 2, '');
        $paths[ltrim(trim($name), '/')] = trim($path);
    }

    return $paths;
}

/**
 * Empty a container log file in place, so the container keeps running on the
 * same log stream.
 *
 * The file belongs to root, and on Docker Desktop it lives inside the VM:
 * entering the mount namespace of the docker host's init covers both cases.
 */
function truncate_container_log(string $logPath, ?Context $c = null): void
{
    $c ??= context();

    if (is_writable($logPath)) {
        file_put_contents($logPath, '');

        return;
    }

    run([
        'docker', 'run', '--rm', '--privileged', '--pid=host',
        'alpine:3',
        'nsenter', '-t', '1', '-m', '-u', '-i', '-n', '--',
        'truncate', '-s', '0', $logPath,
    ], context: $c->withQuiet());
}

/**
 * Expose a service's TCP port on the host, or stop exposing it when $stop is true.
 *
 * Runs a socat forwarder publishing $hostPort to $service:$containerPort over
 * the project network. There is one per service: calling this again replaces
 * it. The set is remembered in the cache so "docker:up" restores it — see
 * restore_exposed_services().
 */
function expose_service_port(string $service, int $containerPort, ?int $hostPort = null, bool $stop = false): void
{
    $hostPort ??= $containerPort;
    $context = context();
    $project = get_project_name($context);
    $name = "{$project}-expose-{$service}";

    // Idempotent, and how --stop works.
    run(['docker', 'rm', '-f', $name], context: $context->withQuiet()->withAllowFailure());

    $exposed = get_exposed_services();

    if ($stop) {
        unset($exposed[$service]);
        set_exposed_services($exposed);

        io()->success("Stopped exposing the \"{$service}\" service.");

        return;
    }

    if (null !== ($holder = find_published_port_holder($hostPort, $context))) {
        io()->error(\sprintf('The port %d is already published by "%s".', $hostPort, $holder));
        io()->note(\sprintf('Expose "%s" on another port instead: castor %s:expose <port>.', $service, $service));

        // Remembered all the same: the forwarder comes back on the next
        // "docker:up", once the port is free.
        $exposed[$service] = ['container_port' => $containerPort, 'host_port' => $hostPort];
        set_exposed_services($exposed);

        return;
    }

    run([
        'docker', 'run', '--detach',
        '--name', $name,
        '--network', "{$project}_default",
        '--publish', "{$hostPort}:{$hostPort}",
        '--restart', 'unless-stopped',
        // An orphan of the compose project, so "docker:destroy" tears it down
        // with the rest; castor.expose is how "docker:stop" finds them.
        '--label', "com.docker.compose.project={$project}",
        '--label', "com.docker.compose.service=expose-{$service}",
        '--label', 'castor.expose=1',
        'alpine/socat',
        "TCP-LISTEN:{$hostPort},fork,reuseaddr",
        "TCP:{$service}:{$containerPort}",
    ], context: $context->withQuiet());

    $exposed[$service] = ['container_port' => $containerPort, 'host_port' => $hostPort];
    set_exposed_services($exposed);

    io()->success("Exposing \"{$service}\" on tcp://127.0.0.1:{$hostPort}");
}

/**
 * The container already publishing a host port, or null when it is free. A port
 * taken by a plain host process is left to docker to report.
 */
function find_published_port_holder(int $hostPort, ?Context $c = null): ?string
{
    $c ??= context();

    $found = trim(capture(
        ['docker', 'ps', '--filter', "publish={$hostPort}", '--format', '{{.Names}}'],
        context: $c->withQuiet()->withAllowFailure(),
        onFailure: '',
    ));

    return '' === $found ? null : explode("\n", $found)[0];
}

/**
 * @return array<string, array{container_port: int, host_port: int}>
 */
function get_exposed_services(?Context $c = null): array
{
    $item = get_cache()->getItem(get_exposed_services_cache_key($c));
    $value = $item->isHit() ? $item->get() : [];

    if (!\is_array($value)) {
        return [];
    }

    /** @var array<string, array{container_port: int, host_port: int}> $value */
    return $value;
}

/**
 * @param array<string, array{container_port: int, host_port: int}> $exposed
 */
function set_exposed_services(array $exposed, ?Context $c = null): void
{
    $item = get_cache()->getItem(get_exposed_services_cache_key($c));
    $item->set($exposed);

    get_cache()->save($item);
}

/**
 * Castor's cache is one directory shared by every project of the machine, so
 * the set has to be scoped — on the directory rather than on the project name,
 * so two checkouts of the same repository keep their own ports.
 */
function get_exposed_services_cache_key(?Context $c = null): string
{
    $c ??= context();

    return 'infrastructure.exposed.' . substr(hash('xxh128', Path::canonicalize($c->workingDirectory)), 0, 16);
}

/**
 * Re-create the forwarder of every remembered exposed service, so the ports
 * come back after "docker:up". Already running ones are left untouched.
 */
function restore_exposed_services(): void
{
    $context = context();
    $project = get_project_name($context);

    foreach (get_exposed_services() as $service => $ports) {
        $running = trim(capture(
            ['docker', 'ps', '--quiet', '--filter', "name=^{$project}-expose-{$service}$", '--filter', 'status=running'],
            context: $context->withAllowFailure(),
        ));

        if ($running !== '') {
            continue;
        }

        // Another checkout may hold the port: leave the entry alone so it
        // comes back later.
        if (null !== ($holder = find_published_port_holder($ports['host_port'], $context))) {
            io()->note(\sprintf('Not exposing "%s": the port %d is published by "%s".', $service, $ports['host_port'], $holder));

            continue;
        }

        expose_service_port($service, $ports['container_port'], $ports['host_port']);
    }
}

/**
 * Stop every "<service>:expose" forwarder container of the current project.
 */
function stop_exposed_services(): void
{
    $context = context();
    $project = get_project_name($context);

    $ids = trim(capture([
        'docker', 'ps', '--quiet',
        '--filter', 'label=castor.expose=1',
        '--filter', "label=com.docker.compose.project={$project}",
    ], context: $context->withAllowFailure()));

    if ($ids === '') {
        return;
    }

    run(['docker', 'stop', ...explode("\n", $ids)], context: $context->withQuiet()->withAllowFailure());
}

#[AsListener(ContextCreatedEvent::class)]
function on_init_context(ContextCreatedEvent $event): void
{
    $event->context = initialize_project($event->context);
}

/**
 * Create compose.yaml if the project has none, and return the context enriched
 * with the data the services build on.
 *
 * Castor only dispatches ContextCreatedEvent for a context declared with
 * #[AsContext], so this also runs on boot, where it is idempotent.
 */
function initialize_project(Context $context): Context
{
    $composeFile = $context->workingDirectory . '/compose.yaml';
    $projectName = basename($context->workingDirectory);

    if (!file_exists($composeFile)) {
        io()->title('Initializing Docker Compose file for the context');

        $input = input();

        // Never block on a question while the shell is completing a command.
        if ($input->isInteractive() && '_complete' !== $input->getFirstArgument()) {
            $projectName = io()->ask('Enter your docker compose project name', $projectName);
        }

        file_put_contents(
            $composeFile,
            <<<YAML
                # This is your docker-compose file. It has been generated by Castor, but you can edit it if needed.
                name: {$projectName}
                include:
                   - path:
                         - compose.generated.yaml # This file is generated you should not remove or edit this line / file.
                         - compose.override.yaml # This file is for your local overrides of existing services.

                # Here you can also add your own services.
                YAML
        );
    } elseif ($content = file_get_contents($composeFile)) {

        $baseCompose = yaml_parse($content);

        if (($baseCompose['name'] ?? null) !== null) {
            $projectName = $baseCompose['name'];
        }
    }

    $userId = \function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();

    // The context wins over the "name" of compose.yaml, which is the only way
    // a second checkout of the same repository can run beside the first.
    $projectName = $context->data['project_name'] ?? $projectName;
    $rootDomain = $context->data['root_domain'] ?? null;
    $worktree = worktree_of($context);

    if (null !== $worktree) {
        // This runs on every boot, and a project deriving its own names from
        // the worktree must not see them suffixed twice.
        if (!str_ends_with($projectName, '-' . $worktree)) {
            $projectName .= '-' . $worktree;
        }

        $rootDomain ??= DEFAULT_ROOT_DOMAIN;

        if (!str_starts_with($rootDomain, $worktree . '.')) {
            $rootDomain = $worktree . '.' . $rootDomain;
        }
    }

    $data = [
        'project_name' => $projectName,
        'worktree' => $worktree,
        'user_id' => $userId,
    ];

    if (null !== $rootDomain) {
        $data['root_domain'] = $rootDomain;
    }

    return $context->withData($data);
}

/**
 * The worktree a context runs in, honouring a name the project pins itself and
 * "worktree_isolation" turned off to share one stack between checkouts.
 */
function worktree_of(Context $context): ?string
{
    if (false === ($context->data['worktree_isolation'] ?? true)) {
        return null;
    }

    return $context->data['worktree'] ?? detect_worktree($context->workingDirectory);
}

/**
 * @return ServiceInterface[]
 */
function collect_services(): array
{
    return dispatch(new RegisterServiceEvent())->services;
}

/**
 * Create the host directories the services bind-mount.
 *
 * Docker would create a missing bind mount source itself, but as root, leaving
 * it read-only for the user the containers run as. Only paths inside the
 * project are created: the docker socket and other system paths are not ours.
 */
function create_mount_directories(Context $c, ComposeBuilder $composeBuilder): void
{
    $root = Path::canonicalize($c->workingDirectory);
    $roots = get_project_mount_roots($c);

    foreach ($composeBuilder->getBindMountSources() as $source) {
        $path = Path::makeAbsolute($source, $root);

        if (!array_filter($roots, static fn(string $base): bool => Path::isBasePath($base, $path))) {
            continue;
        }

        if (!file_exists($path)) {
            fs()->mkdir($path);

            continue;
        }

        // Created as root by docker on an earlier run, and nothing here can
        // fix it.
        if (!is_writable($path) && '_complete' !== input()->getFirstArgument()) {
            io()->warning(\sprintf('"%s" is not writable, the containers may fail to write in it. Take its ownership back with "sudo chown -R $(id -u):$(id -g) %s".', $path, $path));
        }
    }
}

/**
 * Its own tree, plus for a worktree the main checkout, whose shared home
 * directory it mounts from outside of that tree.
 *
 * @return list<string>
 */
function get_project_mount_roots(Context $c): array
{
    $roots = [Path::canonicalize($c->workingDirectory)];

    if (null !== get_worktree_name($c)) {
        $roots[] = Path::canonicalize(get_main_checkout_directory($c));
    }

    return $roots;
}

/**
 * The host directories the project bind-mounts from its own tree, read back
 * from the compose files. The docker socket and other system paths are left
 * out: they are not the project's to own.
 *
 * @return list<string>
 */
function get_project_bind_mounts(?Context $c = null): array
{
    $c ??= context();
    $root = Path::canonicalize($c->workingDirectory);
    $roots = get_project_mount_roots($c);
    $paths = [];

    foreach (['compose.generated.yaml', 'compose.yaml', 'compose.override.yaml'] as $file) {
        $path = $c->workingDirectory . '/' . $file;

        if (!file_exists($path) || !($content = file_get_contents($path))) {
            continue;
        }

        $compose = yaml_parse($content);

        if (!\is_array($compose) || !\is_array($compose['services'] ?? null)) {
            continue;
        }

        foreach ($compose['services'] as $service) {
            foreach (\is_array($service) && \is_array($service['volumes'] ?? null) ? $service['volumes'] : [] as $volume) {
                $source = match (true) {
                    \is_string($volume) => explode(':', $volume)[0],
                    \is_array($volume) && 'bind' === ($volume['type'] ?? null) => (string) ($volume['source'] ?? ''),
                    default => '',
                };

                // A named volume is a bare name, a path starts with "/" or ".";
                // a variable left to compose to interpolate is none of ours.
                if (!preg_match('{^[/.]}', $source) || str_contains($source, '$')) {
                    continue;
                }

                $source = Path::makeAbsolute($source, $root);

                if (array_filter($roots, static fn(string $base): bool => Path::isBasePath($base, $source))) {
                    $paths[$source] = true;
                }
            }
        }
    }

    return array_keys($paths);
}

/**
 * Make the public domains of the project resolvable from inside its own
 * containers, by pointing each of them at the host gateway.
 *
 * The router joins the project network without a DNS alias, so nothing in the
 * project resolves "api.myproject.test". Docker accepts no wildcard in
 * extra_hosts, so every routed domain is spelled out. Traffic leaves through
 * the host gateway and comes back on the ports the router publishes, which
 * works on Linux as well as on Docker Desktop.
 *
 * Turn it off with the "resolve_domains_via_host" context data.
 */
function add_project_extra_hosts(Context $c, ComposeBuilder $composeBuilder): void
{
    if (false === ($c->data['resolve_domains_via_host'] ?? true)) {
        return;
    }

    $domains = array_filter($composeBuilder->getRoutedDomains(), is_resolvable_project_domain(...));

    if (!$domains) {
        return;
    }

    foreach ($composeBuilder->getServices() as $service) {
        foreach ($domains as $domain) {
            $service->extraHost($domain, 'host-gateway');
        }
    }
}

/**
 * A name without a dot is refused: "localhost" would shadow the loopback entry
 * of /etc/hosts, and any other bare label collides with the container names of
 * the project network.
 */
function is_resolvable_project_domain(string $domain): bool
{
    return str_contains($domain, '.');
}

/**
 * Call every function marked with #[AsDockerComposeBuilder], highest priority
 * first. Castor resolves its own attributes only, hence the lookup here.
 */
function run_compose_builders(Context $c, ComposeBuilder $builder): void
{
    $found = [];

    foreach (get_defined_functions()['user'] as $name) {
        $reflection = new \ReflectionFunction($name);

        foreach ($reflection->getAttributes(AsDockerComposeBuilder::class) as $attribute) {
            $found[] = [$attribute->newInstance()->priority, $reflection];
        }
    }

    usort($found, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

    foreach ($found as [, $reflection]) {
        $reflection->invoke($builder, $c);
    }
}

/**
 * @param ServiceInterface[] $services
 */
function generate_compose_file(Context $c, array $services): void
{
    $composeBuilder = new ComposeBuilder();

    foreach ($services as $service) {
        $composeBuilder = $service->updateCompose($c, $composeBuilder);
    }

    // Before create_mount_directories(): a bind mount added by a listener still
    // gets its host directory created.
    $composeBuilder = dispatch(new DockerComposeBuilderEvent($c, $composeBuilder))->builder;
    run_compose_builders($c, $composeBuilder);

    create_mount_directories($c, $composeBuilder);

    // Before the extra hosts, so a container resolves the domain it will really
    // be served on.
    apply_worktree_domains($c, $composeBuilder);

    // After the listeners, so a domain routed by one of them is resolvable too.
    add_project_extra_hosts($c, $composeBuilder);

    $compose = dispatch(new DockerComposeWriteEvent($c, $composeBuilder->toArray()))->compose;

    file_put_contents(
        $c->workingDirectory . '/compose.generated.yaml',
        "# This file is generated by Castor. Do not edit it manually.\n" . yaml_dump($compose, inline: 5, flags: Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),
    );
}

/**
 * @return array<string, ServiceInstaller>
 */
function collect_service_installers(): array
{
    return dispatch(new RegisterServiceInstallerEvent())->installers;
}

/**
 * Symfony reads a multiple choice's preselection back from one comma-separated
 * string rather than from a list.
 */
function format_choice_default(mixed $default): ?string
{
    if (\is_array($default)) {
        return $default === [] ? null : implode(',', array_map(static fn(mixed $choice): string => (string) $choice, $default));
    }

    return \is_string($default) ? $default : null;
}

/**
 * The service to install, read back from the raw command line: the argument
 * castor binds is lost as soon as an option it knows nothing about precedes
 * it, which is exactly what the per-service install options are.
 *
 * @param list<string>                    $tokens
 * @param array<string, ServiceInstaller> $installers
 * @param list<InputOption>               $applicationOptions the global options that may sit among them ("-n", "-v"…)
 */
function find_installer_name(array $tokens, array $installers, array $applicationOptions = []): ?string
{
    $isValue = false;

    foreach ($tokens as $token) {
        // A value written apart from its option ("--with-name blog") is
        // neither a service nor an option, whatever it looks like.
        if ($isValue) {
            $isValue = false;

            continue;
        }

        if (str_starts_with($token, '-')) {
            $isValue = InstallerOptions::expectsValue($token, $installers, $applicationOptions);

            continue;
        }

        if (isset($installers[$token])) {
            return $token;
        }
    }

    return null;
}

/**
 * A question already answered on the command line is not asked again.
 *
 * @param array<string, mixed> $provided answers coming from the install options, keyed by input name
 *
 * @return array<string, mixed>
 */
function ask_installer_inputs(ServiceInstaller $installer, array $provided = []): array
{
    $answers = [];

    foreach ($installer->getInputs() as $input) {
        if (\array_key_exists($input->name, $provided)) {
            $answers[$input->name] = $provided[$input->name];

            continue;
        }

        $default = $input->resolveDefault($answers);

        $answers[$input->name] = match ($input->type) {
            InputType::Boolean => io()->confirm($input->label, (bool) $default),
            InputType::Choice => io()->choice($input->label, $input->choices, format_choice_default($default), $input->multiple),
            InputType::Integer => (int) io()->ask($input->label, $default === null ? null : (string) $default),
            InputType::Text => (string) io()->ask($input->label, $default === null ? null : (string) $default),
        };
    }

    return $answers;
}

/**
 * Pick an existing database (extracting it to a variable if needed), install a
 * fresh one, or none.
 *
 * @param array<string, ServiceInstaller> $installers
 * @param string|null                     $requested  what "--with-database" asked for: "none", a registered
 *                                                    database, or a database to install — null to ask
 *
 * @return array{variable: ?string, instance: ?DatabaseServiceInterface, services: ServiceInterface[]}
 */
function resolve_database_link(ListenerEditor $editor, array $installers, ?string $requested = null): array
{
    $none = ['variable' => null, 'instance' => null, 'services' => []];

    $existing = [];

    foreach (collect_services() as $service) {
        if ($service instanceof DatabaseServiceInterface) {
            $existing[$service->getName()] = $service;
        }
    }

    $databaseInstallers = array_filter($installers, static fn(ServiceInstaller $installer): bool => $installer instanceof DatabaseServiceInstaller);

    if ($requested !== null) {
        if ($requested === 'none') {
            return $none;
        }

        if (isset($existing[$requested])) {
            return link_database($editor, $existing[$requested], $requested);
        }

        if (isset($databaseInstallers[$requested])) {
            return install_database($editor, $databaseInstallers[$requested]);
        }

        throw new \RuntimeException(\sprintf(
            'Unknown database "%s": expected "none", a registered database (%s) or a database to install (%s).',
            $requested,
            $existing === [] ? 'none registered yet' : implode(', ', array_keys($existing)),
            implode(', ', array_keys($databaseInstallers)),
        ));
    }

    if ($existing !== []) {
        $choice = io()->choice('Link the application to a database', [...array_keys($existing), '(none)'], (string) array_key_first($existing));

        if ($choice === '(none)') {
            return $none;
        }

        return link_database($editor, $existing[$choice], $choice);
    }

    if (!io()->confirm('No database is configured. Install one now?', true)) {
        return $none;
    }

    $names = array_map(static fn(ServiceInstaller $installer): string => $installer->getName(), $databaseInstallers);

    return install_database($editor, $installers[io()->choice('Which database?', array_values($names), 'postgres')]);
}

/**
 * Extracts the instance to a variable when the listener holds it inline.
 *
 * @return array{variable: ?string, instance: ?DatabaseServiceInterface, services: ServiceInterface[]}
 */
function link_database(ListenerEditor $editor, DatabaseServiceInterface $instance, string $name): array
{
    return [
        'variable' => $editor->ensureServiceVariable($instance::class, $name) ?? $name,
        'instance' => $instance,
        'services' => [],
    ];
}

/**
 * @return array{variable: ?string, instance: ?DatabaseServiceInterface, services: ServiceInterface[]}
 */
function install_database(ListenerEditor $editor, ServiceInstaller $installer): array
{
    $answers = ask_installer_inputs($installer);
    $variable = $installer->getName();

    $builder = new ServiceStatementBuilder($editor->getEventVariable());
    $installer->buildStatements($builder, $answers);

    foreach ($builder->getExpressions() as $expression) {
        $expression->assignTo($variable);
    }

    $editor->addImports($builder->getImports());
    $editor->addStatements($builder->getStatements());

    $instance = $installer->createInstance($answers);
    \assert($instance instanceof DatabaseServiceInterface);

    return ['variable' => $variable, 'instance' => $instance, 'services' => [$instance]];
}

#[AsListener(RegisterServiceInstallerEvent::class)]
function register_builtin_installers(RegisterServiceInstallerEvent $event): void
{
    $event->addInstaller(new PostgresInstaller());
    $event->addInstaller(new MySQLInstaller());
    $event->addInstaller(new MariaDBInstaller());
    $event->addInstaller(new RedisInstaller());
    $event->addInstaller(new RabbitMQInstaller());
    $event->addInstaller(new ElasticsearchInstaller());
    $event->addInstaller(new ClickhouseInstaller());
    $event->addInstaller(new MailpitInstaller());
    $event->addInstaller(new MercureInstaller());
    $event->addInstaller(new MeilisearchInstaller());
    $event->addInstaller(new RustFSInstaller());
    $event->addInstaller(new SymfonyInstaller());
    $event->addInstaller(new NodeInstaller());
    $event->addInstaller(new RustInstaller());
}

#[AsListener(FunctionsResolvedEvent::class)]
function initialize(FunctionsResolvedEvent $functionsResolvedEvent): void
{
    // FunctionsResolvedEvent is dispatched once per mount.
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    $services = collect_services();

    foreach ($services as $service) {
        foreach ($service->getTasks() as $task) {
            $functionsResolvedEvent->taskDescriptors[] = new TaskDescriptor(
                $task['task'],
                new \ReflectionFunction($task['function']),
            );
        }
    }

    // "castor list" is booted on a bare context on purpose (see
    // Kernel::configureContext): generating the compose file from it would
    // drop the project configuration.
    if ('list' === input()->getFirstArgument()) {
        return;
    }

    $c = initialize_project(context());
    generate_compose_file($c, $services);

    $overrideFile = $c->workingDirectory . '/compose.override.yaml';

    if (!file_exists($overrideFile)) {
        file_put_contents(
            $overrideFile,
            <<<YAML
                # This file is for your local overrides. It is not generated by Castor.
                YAML
        );
    }
}
