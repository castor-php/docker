<?php

declare(strict_types=1);

namespace Castor\Docker;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Doctor\Doctor;
use Castor\Docker\Doctor\HostSystemProbe;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Terminal;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

use function Castor\context;
use function Castor\io;
use function Castor\parallel;
use function Castor\variable;
use function Castor\run;

/**
 * @param list<string> $profiles
 */
#[AsTask(description: 'Build the infrastructure', aliases: ['build'], namespace: 'docker')]
function build(
    #[AsArgument(description: 'The service to act on, all of them when omitted', autocomplete: 'Castor\Docker\autocomplete_service_name')]
    ?string $service = null,
    #[AsOption(description: 'The compose profiles to act on, the default ones when omitted', mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED)]
    array $profiles = [],
    #[AsOption(description: 'Build each service on its own, in parallel, so services sharing a stage keep their build cache', mode: InputOption::VALUE_NONE | InputOption::VALUE_NEGATABLE)]
    ?bool $parallel = null,
): void {
    io()->title('Building infrastructure');

    $command = ['build'];

    foreach (variable('build_args', []) as $key => $value) {
        $command[] = '--build-arg';
        $command[] = "{$key}={$value}";
    }

    if (!$profiles) {
        $profiles = get_default_profiles();
        $profiles[] = 'builder';
    }

    $services = $service || !is_build_parallel($parallel) ? [] : get_buildable_services($profiles);

    if (\count($services) < 2) {
        docker_compose($service ? [...$command, $service] : $command, profiles: $profiles);

        return;
    }

    // One build per service: in a single session BuildKit merges the stages
    // services share, then races the caches of those services over them and
    // randomly loses the steps of all but one (moby/buildkit#6418).
    $builds = [];

    foreach ($services as $service) {
        $builds[$service] = static fn(): Process => docker_compose([...$command, $service], profiles: $profiles, progress: 'plain');
    }

    parallel(...$builds);
}

function is_build_parallel(?bool $option = null, ?Context $c = null): bool
{
    return $option ?? (($c ?? context())->data['build_parallel'] ?? false);
}

/**
 * @param list<string> $profiles
 *
 * @return list<string>
 */
function get_buildable_services(array $profiles): array
{
    return array_map('strval', array_keys(array_filter(get_resolved_services($profiles), static fn(array $config): bool => isset($config['build']))));
}

/**
 * The services as compose resolves them: includes followed, variables
 * interpolated, overrides merged.
 *
 * @param list<string> $profiles "*" for every one
 *
 * @return array<string, array{build?: array{cache_from?: list<string>}, depends_on?: array<string, mixed>}>
 */
function get_resolved_services(array $profiles): array
{
    $config = json_decode(docker_compose(['config', '--format', 'json'], context()->withQuiet(), profiles: $profiles)->getOutput(), true);

    return \is_array($config['services'] ?? null) ? $config['services'] : [];
}

/**
 * @param list<string> $profiles
 */
#[AsTask(description: 'Build and start the infrastructure', aliases: ['up'], namespace: 'docker')]
function up(
    #[AsArgument(description: 'The service to act on, all of them when omitted', autocomplete: 'Castor\Docker\autocomplete_service_name')]
    ?string $service = null,
    #[AsOption(description: 'The compose profiles to act on, the default ones when omitted', mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED)]
    array $profiles = [],
    #[AsOption(description: 'Build the infrastructure first')]
    bool $build = false,
): void {
    if ($build) {
        build($service, $profiles);
    }

    if (!$service && !$profiles) {
        io()->title('Starting infrastructure');
    }

    $command = ['up', '--detach', '--no-build', '--remove-orphans'];

    if ($service) {
        $command[] = $service;
    }

    try {
        docker_compose($command, profiles: $profiles);
    } catch (ExceptionInterface $e) {
        io()->error('An error occured while starting the infrastructure.');
        io()->note('Did you forget to run "castor ' . task_name('Castor\Docker\build') . '"?');
        io()->note('Or you forget to login to the registry?');

        throw $e;
    }

    if (!$service) {
        restore_exposed_services();
    }
}

/**
 * @param list<string> $profiles
 */
#[AsTask(description: 'Stop the infrastructure', aliases: ['stop'], namespace: 'docker')]
function stop(
    #[AsArgument(description: 'The service to act on, all of them when omitted', autocomplete: 'Castor\Docker\autocomplete_service_name')]
    ?string $service = null,
    #[AsOption(description: 'The compose profiles to act on, the default ones when omitted', mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED)]
    array $profiles = [],
): void {
    if (!$service && !$profiles) {
        io()->title('Stopping infrastructure');
    }

    // A tunnel to a stopped project would only answer errors.
    if (!$service) {
        close_project_tunnels();
    }

    $command = ['stop'];

    if ($service) {
        $command[] = $service;
    }

    docker_compose($command, profiles: $profiles);

    // Expose forwarders are standalone containers, not compose services.
    if (!$service) {
        stop_exposed_services();
    }
}

/**
 * @param list<string> $profiles
 */
#[AsTask(description: 'Display the logs of the infrastructure', aliases: ['logs'], namespace: 'docker')]
function logs(
    #[AsArgument(description: 'The service to act on, all of them when omitted', autocomplete: 'Castor\Docker\autocomplete_service_name')]
    ?string $service = null,
    #[AsOption(description: 'The compose profiles to act on, the default ones when omitted', mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED)]
    array $profiles = [],
): void {
    $command = ['logs', '--tail', '150'];
    $c = context();

    if (Process::isTtySupported()) {
        $c = $c->withTty();
        $command[] = '-f';
    }

    if ($service) {
        $command[] = $service;
    }

    docker_compose($command, c: $c, profiles: $profiles);
}

#[AsTask(description: 'Clear the logs of a service, or of every service', namespace: 'docker:logs', name: 'clear')]
function logs_clear(
    #[AsArgument(description: 'The service to clear, all of them when omitted', autocomplete: 'Castor\Docker\autocomplete_service_name')]
    ?string $service = null,
): void {
    $c = context();

    if (null !== $service && !\in_array($service, $names = get_compose_service_names($c), true)) {
        io()->error(\sprintf('Unknown service "%s".', $service));
        io()->note('Available: ' . (implode(', ', $names) ?: '(none)'));

        return;
    }

    $logPaths = get_container_log_paths($service, $c);

    if (!$logPaths) {
        io()->warning($service === null
            ? 'No container of this project exists yet: nothing to clear.'
            : \sprintf('The "%s" service has no container: nothing to clear.', $service));

        return;
    }

    $cleared = [];

    foreach ($logPaths as $name => $logPath) {
        if ('' === $logPath) {
            io()->note(\sprintf('"%s" does not write its logs to a file, its logging driver keeps them elsewhere.', $name));

            continue;
        }

        truncate_container_log($logPath, $c);
        $cleared[] = $name;
    }

    if (!$cleared) {
        return;
    }

    io()->success(\sprintf('Cleared the logs of %s.', implode(', ', $cleared)));
}

#[AsTask(description: 'List the containers and their status', aliases: ['ps'], namespace: 'docker')]
function ps(): void
{
    docker_compose(['ps']);
}

/**
 * Everything is read from the compose files, so the task answers whether or not
 * the infrastructure runs — what is running only decorates the listing.
 */
#[AsTask(description: 'Sum up the project and list all its URLs', aliases: ['about'], namespace: 'docker')]
function about(): void
{
    $c = context();
    $running = get_running_service_names($c);
    $routerRunning = is_router_running();

    io()->title('About this project');

    io()->comment('Run <comment>castor</comment> to display all available commands.');
    io()->comment('Run <comment>castor about</comment> to display this project help.');
    io()->comment('Run <comment>castor help [command]</comment> to display Castor help.');

    if (null !== ($worktree = get_worktree_name($c))) {
        io()->comment(\sprintf(
            'This checkout is the <comment>%s</comment> worktree: it runs its own stack, under the <comment>%s</comment> compose project. See <comment>castor ' . task_name('Castor\Docker\worktree_list') . '</comment>.',
            $worktree,
            get_project_name($c),
        ));

        if ($conflicts = get_worktree_conflicts($c)) {
            io()->warning('Its stack is its own, but the following belong to the whole machine and are shared with every other checkout:');
            io()->listing($conflicts);
        }
    }

    io()->section('Available URLs for this project:');

    $urls = get_project_urls($c);

    if (!$urls) {
        io()->text('This project routes no domain. Give a service a domain with withDomain() to reach it over HTTP.');
    } else {
        $rows = [];

        foreach ($urls as $service => $serviceUrls) {
            foreach ($serviceUrls as $index => $url) {
                $rows[] = [
                    0 === $index ? $service : '',
                    \sprintf('<href=%s>%s</>', $url, $url),
                    0 === $index ? status_label($service, $running) : '',
                ];
            }
        }

        io()->table(['Service', 'URL', 'Status'], $rows);

        if (!$routerRunning) {
            io()->warning('The router is stopped: none of these URLs answers.');
            io()->note('Start it with "castor ' . task_name('Castor\Docker\router_enable') . '".');
        }
    }

    $tunnels = array_filter(get_project_tunnels($c), fn(array $tunnel) => $tunnel['running']);

    if (!$tunnels) {
        return;
    }

    io()->section('Public tunnels:');

    $rows = [];

    foreach ($tunnels as $domain => $tunnel) {
        $url = parse_tunnel_url(get_tunnel_logs($tunnel['container'], $c));
        $rows[] = [$domain, null === $url ? '<fg=yellow>waiting for its URL</>' : \sprintf('<href=%s>%s</>', $url, $url)];
    }

    io()->table(['Domain', 'Public URL'], $rows);
}

/**
 * @param list<string> $running
 */
function status_label(string $service, array $running): string
{
    return \in_array($service, $running, true) ? '<fg=green>running</>' : '<fg=yellow>stopped</>';
}

/**
 * Nothing has to run for it to answer: without a Docker daemon, the daemon is
 * what gets reported, and the checks needing it are skipped.
 */
#[AsTask(description: 'Diagnose the environment and say how to fix what is wrong', aliases: ['doctor'], namespace: 'docker')]
function doctor(): int
{
    $c = context();

    io()->title(\sprintf('Diagnosing "%s"', get_project_name($c)));

    $counts = ['error' => 0, 'warning' => 0];
    // What the icon and the label leave of the terminal, so a long fix wraps
    // inside its cell instead of across the table.
    $width = max(40, new Terminal()->getWidth() - 26);

    foreach (new Doctor($c, new HostSystemProbe($c))->run() as $section => $checks) {
        io()->section($section);

        $rows = [];

        foreach ($checks as $check) {
            $counts[$check->status->value] = ($counts[$check->status->value] ?? 0) + 1;
            // A fix reading "castor <service>:expose" holds no style.
            $rows[] = [
                $check->status->symbol(),
                $check->label,
                OutputFormatter::escape($check->result) . (null !== $check->fix ? "\n<fg=cyan>→ " . OutputFormatter::escape($check->fix) . '</>' : ''),
            ];
        }

        io()->createTable()
            ->setHeaders(['', 'Check', 'Result'])
            ->setRows($rows)
            ->setColumnMaxWidth(2, $width)
            ->render();
        io()->newLine();
    }

    $summary = \sprintf(
        '%d error%s, %d warning%s.',
        $counts['error'],
        1 === $counts['error'] ? '' : 's',
        $counts['warning'],
        1 === $counts['warning'] ? '' : 's',
    );

    if ($counts['error']) {
        io()->error($summary . ' Fix the errors first: each of them is enough to keep the project from working.');

        return 1;
    }

    if ($counts['warning']) {
        io()->warning($summary . ' The project can work, but not everything will.');

        return 0;
    }

    io()->success('Nothing to fix.');

    return 0;
}

#[AsTask(description: 'Clean the infrastructure (remove containers, volumes and networks)', aliases: ['destroy'], namespace: 'docker')]
function destroy(
    #[AsOption(description: 'Force the destruction without confirmation', shortcut: 'f')]
    bool $force = false,
): void {
    io()->title('Destroying infrastructure');

    if (!$force) {
        io()->warning('This will permanently remove all containers, volumes, networks... created for this project.');
        io()->note('You can use the --force option to avoid this confirmation.');
        if (!io()->confirm('Are you sure?', false)) {
            io()->comment('Aborted.');

            return;
        }
    }

    close_project_tunnels();

    docker_compose(['down', '--remove-orphans', '--volumes', '--rmi=local']);
}
