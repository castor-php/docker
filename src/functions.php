<?php

declare(strict_types=1);

namespace Castor\Docker;

use Castor\Attribute\AsListener;
use Castor\Attribute\AsTask;
use Castor\Console\Output\VerbosityLevel;
use Castor\Context;
use Castor\Descriptor\TaskDescriptor;
use Castor\Docker\Attribute\AsDockerComposeBuilder;
use Castor\Docker\Event\DockerComposeBuilderEvent;
use Castor\Docker\Event\DockerComposeWriteEvent;
use Castor\Docker\Event\RegisterServiceEvent;
use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\PHPService;
use Castor\Docker\Service\ServiceInterface;
use Castor\Event\ContextCreatedEvent;
use Castor\Event\FunctionsResolvedEvent;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Terminal;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

use function Castor\capture;
use function Castor\context;
use function Castor\dispatch;
use function Castor\fs;
use function Castor\input;
use function Castor\io;
use function Castor\run;
use function Castor\variable;
use function Castor\yaml_dump;
use function Castor\yaml_parse;

/**
 * The trimmed output of a command probing docker or git, empty when it fails
 * or cannot even start: a missing binary or daemon is an answer here, not an
 * error.
 *
 * @param list<string> $command
 */
function quiet_capture(array $command, ?Context $c = null): string
{
    try {
        return trim(capture($command, context: ($c ?? context())->withQuiet()->withAllowFailure()));
    } catch (\Throwable) {
        return '';
    }
}

/**
 * The compose files to read the services from, the project's own ones along
 * with the one the plugin generates.
 *
 * @return list<string>
 */
function get_compose_files(?Context $c = null): array
{
    $c ??= context();

    return [
        $c->workingDirectory . '/compose.generated.yaml',
        $c->workingDirectory . '/compose.yaml',
        $c->workingDirectory . '/compose.override.yaml',
    ];
}

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
            'PROJECT_ROOT_DOMAIN' => $c->data['root_domain'] ?? DEFAULT_ROOT_DOMAIN,
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

    foreach (get_compose_files($c) as $path) {

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
 * Every domain routed to a container of this project, from the URLs it serves.
 *
 * @return list<string>
 */
function get_project_domains(?Context $c = null): array
{
    $domains = [];

    foreach (get_project_urls($c) as $urls) {
        foreach ($urls as $url) {
            $domain = (string) preg_replace('#^https?://#', '', $url);

            // A wildcard would make the whole "docker network connect" fail.
            if (!str_starts_with($domain, '*.')) {
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

    foreach (get_compose_files($c) as $path) {

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

    $output = quiet_capture([
        'docker', 'ps',
        '--filter', 'label=com.docker.compose.project=' . get_project_name($c),
        '--format', '{{.Label "com.docker.compose.service"}}',
    ], $c);

    if ('' === $output) {
        return [];
    }

    $names = array_values(array_filter(array_map(trim(...), explode("\n", $output))));
    sort($names);

    return array_values(array_unique($names));
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
        // On stderr, to keep the output of the task itself, like the bake plan
        // of "docker:push --dry-run", parsable.
        $io = io()->getErrorStyle();
        $io->title('Initializing Docker Compose file for the context');

        $input = input();

        // Never block on a question while the shell is completing a command.
        if ($input->isInteractive() && '_complete' !== $input->getFirstArgument()) {
            $projectName = $io->ask('Enter your docker compose project name', $projectName);
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

    $userId = get_host_uid();

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

    foreach (get_compose_files($c) as $path) {

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
 * The name castor gives the task, which a mount may have prefixed. Before the
 * tasks are resolved, the one of its attribute.
 */
function task_name(string $function): string
{
    $attribute = (remember_task_descriptors()[$function] ?? null)?->taskAttribute;

    if (null === $attribute) {
        $reflection = new \ReflectionFunction($function);
        $attribute = ($reflection->getAttributes(AsTask::class)[0] ?? null)?->newInstance();

        if (null === $attribute) {
            throw new \LogicException(\sprintf('"%s()" is not a task.', $function));
        }

        $attribute->name = $attribute->name ?: new AsciiSlugger()->slug($reflection->getShortName())->toString();
    }

    return $attribute->namespace ? $attribute->namespace . ':' . $attribute->name : $attribute->name;
}

/**
 * Later listeners rename these same objects, so names stay up to date.
 *
 * @param list<TaskDescriptor> $descriptors
 *
 * @return array<string, TaskDescriptor>
 */
function remember_task_descriptors(array $descriptors = []): array
{
    /** @var array<string, TaskDescriptor> $known */
    static $known = [];

    foreach ($descriptors as $descriptor) {
        if (!$descriptor->function->isClosure()) {
            $known[$descriptor->function->getName()] = $descriptor;
        }
    }

    return $known;
}

#[AsListener(FunctionsResolvedEvent::class)]
function initialize(FunctionsResolvedEvent $functionsResolvedEvent): void
{
    remember_task_descriptors($functionsResolvedEvent->taskDescriptors);

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

/**
 * The user running castor, whom the files written by the containers belong to.
 */
function get_host_uid(): int
{
    return \function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid();
}

function get_host_gid(): int
{
    return \function_exists('posix_getegid') ? posix_getegid() : (int) getmygid();
}
