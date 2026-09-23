<?php

declare(strict_types=1);

namespace Castor\Docker;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;
use Castor\Console\Output\VerbosityLevel;
use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Installer\InstallerOptions;
use Castor\Docker\Installer\ListenerEditor;
use Castor\Docker\Installer\NeedsDatabase;
use Castor\Docker\Service\ServiceInterface;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

use function Castor\app;
use function Castor\capture;
use function Castor\context;
use function Castor\io;
use function Castor\variable;
use function Castor\run;

/**
 * @param list<string> $profiles
 */
#[AsTask(description: 'Builds the infrastructure', aliases: ['build'], namespace: 'docker')]
function build(
    #[AsArgument(description: 'The service to act on, all of them when omitted', autocomplete: 'Castor\Docker\autocomplete_service_name')]
    ?string $service = null,
    #[AsOption(mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED)]
    array $profiles = [],
): void {
    io()->title('Building infrastructure');

    $command = [];

    $buildArgs = variable('build_args', []);

    $command = [
        ...$command,
        'build',
    ];

    foreach ($buildArgs as $key => $value) {
        $command[] = '--build-arg';
        $command[] = "{$key}={$value}";
    }

    if ($service) {
        $command[] = $service;
    }

    if (!$profiles) {
        $profiles = get_default_profiles();
        $profiles[] = 'builder';
    }

    docker_compose($command, profiles: $profiles);
}

/**
 * @param list<string> $profiles
 */
#[AsTask(description: 'Builds and starts the infrastructure', aliases: ['up'], namespace: 'docker')]
function up(
    #[AsArgument(description: 'The service to act on, all of them when omitted', autocomplete: 'Castor\Docker\autocomplete_service_name')]
    ?string $service = null,
    #[AsOption(mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED)]
    array $profiles = [],
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
        io()->note('Did you forget to run "castor docker:build"?');
        io()->note('Or you forget to login to the registry?');

        throw $e;
    }

    // Bring back any port that was exposed before (see expose_service_port()).
    if (!$service) {
        restore_exposed_services();
    }
}

/**
 * @param list<string> $profiles
 */
#[AsTask(description: 'Stops the infrastructure', aliases: ['stop'], namespace: 'docker')]
function stop(
    #[AsArgument(description: 'The service to act on, all of them when omitted', autocomplete: 'Castor\Docker\autocomplete_service_name')]
    ?string $service = null,
    #[AsOption(mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED)]
    array $profiles = [],
): void {
    if (!$service || !$profiles) {
        io()->title('Stopping infrastructure');
    }

    // A tunnel to a stopped project would only answer errors, to anyone who
    // still has its public URL.
    if (!$service) {
        close_project_tunnels();
    }

    $command = ['stop'];

    if ($service) {
        $command[] = $service;
    }

    docker_compose($command, profiles: $profiles);

    // Expose forwarders are standalone containers (not compose services), so a
    // full stop must take them down too.
    if (!$service) {
        stop_exposed_services();
    }
}

/**
 * @param list<string> $profiles
 */
#[AsTask(description: 'Displays infrastructure logs', aliases: ['logs'], namespace: 'docker')]
function logs(
    #[AsArgument(description: 'The service to act on, all of them when omitted', autocomplete: 'Castor\Docker\autocomplete_service_name')]
    ?string $service = null,
    #[AsOption(mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED)]
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

/**
 * Truncate the log files docker keeps for the containers of the project.
 *
 * The containers are left running: emptying the file in place is what makes
 * "castor docker:logs" start from a clean slate without restarting anything.
 */
#[AsTask(description: 'Clears the logs of a service, or of every service', namespace: 'docker:logs', name: 'clear')]
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
            // Any driver but "json-file" keeps its logs somewhere else, and
            // there is no file of ours to empty.
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

#[AsTask(description: 'Lists containers status', aliases: ['ps'], namespace: 'docker')]
function ps(): void
{
    docker_compose(['ps']);
}

/**
 * Sum up the project: what it is made of, and every address it answers on.
 *
 * Everything is read from the compose files, so the task answers whether or not
 * the infrastructure runs — what is running only decorates the listing.
 */
#[AsTask(description: 'Sums up the project and lists all its URLs', aliases: ['about'], namespace: 'docker')]
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
            'This checkout is the <comment>%s</comment> worktree: it runs its own stack, under the <comment>%s</comment> compose project. See <comment>castor worktree:list</comment>.',
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
            io()->note('Start it with "castor docker:router:enable".');
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
 * What the project costs the machine it runs on: CPU and memory of every
 * container, and the disk its containers, images and volumes take.
 */
#[AsTask(description: 'Shows the CPU, memory and disk the project uses', aliases: ['stats'], namespace: 'docker')]
function stats(
    #[AsOption(description: 'Skip the disk usage, which makes docker measure every image, container and volume')]
    bool $noDisk = false,
): void {
    $c = context();
    $detailed = $c->verbosityLevel->value > VerbosityLevel::NORMAL->value;

    io()->title(\sprintf('Stats for "%s"', get_project_name($c)));

    $containers = get_project_containers(withSize: !$noDisk, c: $c);
    $running = array_values(array_filter($containers, fn(array $container) => 'running' === $container['state']));
    $samples = get_container_stats(array_column($running, 'id'), $c);

    io()->section(\sprintf('Containers (%d running out of %d)', \count($running), \count($containers)));

    if (!$containers) {
        io()->text('This project has no container. Start it with <comment>castor docker:up</comment>.');
    } else {
        $rows = [];
        $totals = ['cpu' => 0.0, 'memory' => 0, 'netIn' => 0, 'netOut' => 0, 'blockIn' => 0, 'blockOut' => 0, 'pids' => 0];

        foreach ($containers as $container) {
            $sample = $samples[$container['id']] ?? null;

            foreach ($totals as $key => $total) {
                $totals[$key] = $total + ($sample[$key] ?? 0);
            }

            $rows[] = [
                $container['service'] . ($container['oneOff'] ? ' <fg=gray>(one-off)</>' : ''),
                'running' === $container['state']
                    ? '<fg=green>' . $container['status'] . '</>'
                    : '<fg=yellow>' . $container['status'] . '</>',
                null === ($sample['cpu'] ?? null) ? '-' : \sprintf('%.2f%%', $sample['cpu']),
                null === ($sample['memory'] ?? null) ? '-' : format_bytes($sample['memory']),
                null === ($sample['memoryPercent'] ?? null) ? '-' : \sprintf('%.2f%%', $sample['memoryPercent']),
                null === ($sample['netIn'] ?? null) ? '-' : format_bytes($sample['netIn']) . ' / ' . format_bytes($sample['netOut'] ?? 0),
                null === ($sample['blockIn'] ?? null) ? '-' : format_bytes($sample['blockIn']) . ' / ' . format_bytes($sample['blockOut'] ?? 0),
                $sample['pids'] ?? '-',
            ];
        }

        $rows[] = new TableSeparator();
        $rows[] = [
            '<info>Total</>',
            '',
            \sprintf('<info>%.2f%%</>', $totals['cpu']),
            '<info>' . format_bytes($totals['memory']) . '</>',
            '',
            format_bytes($totals['netIn']) . ' / ' . format_bytes($totals['netOut']),
            format_bytes($totals['blockIn']) . ' / ' . format_bytes($totals['blockOut']),
            (string) $totals['pids'],
        ];

        io()->table(['Service', 'Status', 'CPU', 'Memory', 'Mem %', 'Net I/O', 'Block I/O', 'PIDs'], $rows);

        $host = get_docker_host_resources($c);
        $proportions = [];

        if (null !== $host['cpus']) {
            $proportions[] = \sprintf('%.2f of its %d cores', $totals['cpu'] / 100, $host['cpus']);
        }

        if (null !== $host['memory']) {
            $proportions[] = \sprintf(
                '%s of its %s of memory (%.2f%%)',
                format_bytes($totals['memory']),
                format_bytes($host['memory']),
                $host['memory'] > 0 ? $totals['memory'] / $host['memory'] * 100 : 0,
            );
        }

        if ($proportions) {
            io()->text('Right now this project takes ' . implode(' and ', $proportions) . ' from this machine.');
        }
    }

    if ($noDisk) {
        return;
    }

    io()->section('Disk usage');

    $usage = get_project_disk_usage($c);

    $imageSize = array_sum(array_column($usage['images'], 'size'));
    $imageExclusive = array_sum(array_column($usage['images'], 'exclusive'));
    $containerSize = array_sum(array_column($containers, 'size'));
    $volumeSize = array_sum(array_column($usage['volumes'], 'size'));

    io()->table(
        ['What', 'Count', 'Size', 'Exclusive'],
        [
            ['Images', \count($usage['images']), format_bytes($imageSize), format_bytes($imageExclusive)],
            ['Containers', \count($containers), format_bytes($containerSize), format_bytes($containerSize)],
            ['Volumes', \count($usage['volumes']), format_bytes($volumeSize), format_bytes($volumeSize)],
            new TableSeparator(),
            [
                '<info>Total</>',
                '',
                '<info>' . format_bytes($imageSize + $containerSize + $volumeSize) . '</>',
                '<info>' . format_bytes($imageExclusive + $containerSize + $volumeSize) . '</>',
            ],
        ],
    );

    io()->text('"Exclusive" is what destroying the project would actually free: layers its images share with images of other projects only count in "Size".');

    if (!$detailed) {
        io()->comment('Run with <comment>-v</comment> to list every image and volume.');

        return;
    }

    if ($usage['images']) {
        io()->table(
            ['Image', 'Size', 'Exclusive', 'Containers'],
            array_map(
                fn(array $image) => [$image['name'], format_bytes($image['size'] ?? 0), format_bytes($image['exclusive'] ?? 0), $image['containers']],
                $usage['images'],
            ),
        );
    }

    if ($usage['volumes']) {
        io()->table(
            ['Volume', 'Size', 'Used by'],
            array_map(
                fn(array $volume) => [
                    $volume['name'],
                    format_bytes($volume['size'] ?? 0),
                    0 === $volume['links'] ? '<fg=yellow>nothing</>' : \sprintf('%d container(s)', $volume['links']),
                ],
                $usage['volumes'],
            ),
        );
    }
}

/**
 * @param list<string> $tokens
 */
#[AsTask(description: 'Install a service, register it in castor.php, then build and start it', namespace: 'docker:service', name: 'install')]
function service_install(
    #[AsArgument(description: 'The service to install (omit to list the available ones)', autocomplete: 'Castor\Docker\autocomplete_installer_name')]
    ?string $name = null,
    #[AsOption(description: 'The file holding the RegisterServiceEvent listener (defaults to castor.php)')]
    ?string $file = null,
    // Every service answers its own questions, so its options are only known
    // once the service is: they are parsed out of the raw command line rather
    // than declared here.
    #[AsRawTokens]
    array $tokens = [],
): void {
    $installers = collect_service_installers();
    $applicationOptions = array_values(app()->getDefinition()->getOptions());
    $name ??= find_installer_name($tokens, $installers, $applicationOptions);

    if ($name === null || !isset($installers[$name])) {
        if ($name !== null) {
            io()->error(\sprintf('Unknown service "%s".', $name));
        }

        io()->section('Available services');
        foreach ($installers as $installer) {
            io()->writeln(\sprintf('  <info>%s</info> — %s', $installer->getName(), $installer->getDescription()));

            $usage = InstallerOptions::usage($installer);

            if ($usage !== []) {
                io()->writeln('    <comment>' . implode(' ', $usage) . '</comment>');
            }
        }

        return;
    }

    $installer = $installers[$name];
    $c = context();

    // The options of the service answer its questions upfront: what is passed
    // is not asked, and with none left to ask the install runs unattended.
    $options = InstallerOptions::parse($installer, $tokens, $applicationOptions);

    $file ??= $options->file ?? $c->workingDirectory . '/castor.php';

    io()->title(\sprintf('Installing "%s"', $installer->getName()));

    $answers = ask_installer_inputs($installer, $options->answers);

    $source = is_file($file) ? (file_get_contents($file) ?: "<?php\n") : "<?php\n";
    $editor = new ListenerEditor($source);

    /** @var ServiceInterface[] $extraServices */
    $extraServices = [];

    if ($installer instanceof NeedsDatabase) {
        $database = resolve_database_link($editor, $installers, $options->database);
        $answers['database'] = $database['variable'];
        $answers['database_instance'] = $database['instance'];
        $extraServices = $database['services'];
    }

    $builder = new ServiceStatementBuilder($editor->getEventVariable());
    $installer->buildStatements($builder, $answers);
    $editor->addImports($builder->getImports());
    $editor->addStatements($builder->getStatements());

    file_put_contents($file, $editor->getSource());
    io()->success(\sprintf('Registered "%s" in %s.', $installer->getName(), basename($file)));

    // Host-side preparation, then regenerate the compose file in-process with the
    // freshly created instances so build/up see the new services immediately.
    $installer->prepare($answers);

    $services = [...collect_services(), ...$extraServices, $installer->createInstance($answers)];
    generate_compose_file($c, $services);

    build();
    $installer->scaffold($answers);
    up();
    $installer->postUp($answers);

    io()->success(\sprintf('"%s" is installed and running.', $installer->getName()));
}

#[AsTask(description: 'Remove a service from castor.php and tear down its containers', namespace: 'docker:service', name: 'remove')]
function service_remove(
    #[AsArgument(description: 'The registered service to remove (omit to list them)', autocomplete: 'Castor\Docker\autocomplete_registered_service_name')]
    ?string $name = null,
    #[AsOption(description: 'The file holding the RegisterServiceEvent listener (defaults to castor.php)')]
    ?string $file = null,
): void {
    $c = context();
    $services = collect_services();

    /** @var array<string, ServiceInterface> $registered */
    $registered = [];
    foreach ($services as $service) {
        if ($service->getName() !== 'router') {
            $registered[$service->getName()] = $service;
        }
    }

    if ($name === null || !isset($registered[$name])) {
        if ($name !== null) {
            io()->error(\sprintf('No registered service named "%s".', $name));
        }

        io()->section('Registered services');
        foreach (array_keys($registered) as $registeredName) {
            io()->writeln('  <info>' . $registeredName . '</info>');
        }

        return;
    }

    $service = $registered[$name];
    $file ??= $c->workingDirectory . '/castor.php';

    if (!is_file($file)) {
        io()->error(\sprintf('%s does not exist.', $file));

        return;
    }

    io()->title(\sprintf('Removing "%s"', $name));

    $editor = new ListenerEditor((string) file_get_contents($file));

    try {
        $removed = $editor->removeService($service::class, $name);
    } catch (\RuntimeException $e) {
        io()->error($e->getMessage());

        return;
    }

    if (!$removed) {
        io()->error(\sprintf('Could not find the registration of "%s" in %s.', $name, basename($file)));

        return;
    }

    file_put_contents($file, $editor->getSource());
    io()->success(\sprintf('Removed "%s" from %s.', $name, basename($file)));

    // Regenerate the compose file without the service, then drop its (now orphan)
    // containers. Named volumes are kept, so the data survives a re-install.
    $remaining = array_filter($services, static fn(ServiceInterface $s): bool => $s->getName() !== $name);
    generate_compose_file($c, $remaining);

    if (isset(get_exposed_services()[$name])) {
        expose_service_port($name, 0, stop: true);
    }

    docker_compose(['up', '--detach', '--no-build', '--remove-orphans']);

    io()->success(\sprintf('"%s" removed.', $name));
}

#[AsTask(description: 'Cleans the infrastructure (remove container, volume, networks)', aliases: ['destroy'], namespace: 'docker')]
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

#[AsTask(description: 'Push the images and their build cache to the registry', namespace: 'docker', name: 'push', aliases: ['push'])]
function push(
    bool $dryRun = false,
    #[AsOption(description: 'The tag the images are published under')]
    string $tag = 'latest',
): void {
    $registry = variable('registry');

    if (!$registry) {
        throw new \RuntimeException('You must define a registry to push images.');
    }

    // Only a service declaring a cache_from has somewhere to push its build
    // cache back to.
    $targets = [];

    foreach (get_services() as $service => $config) {
        $cacheFrom = $config['build']['cache_from'][0] ?? null;

        if (null !== $cacheFrom) {
            $targets[$service] = normalize_cache_entry($cacheFrom);
        }
    }

    if (!$targets) {
        throw new \RuntimeException('No service declares a build cache, there is nothing to push.');
    }

    $source = get_source_url();

    // A cache manifest carries no label, so a registry that reads one to link
    // a package — ghcr.io — only ever learns where the image comes from from
    // the image this task pushes next to it. Pushing without that label leaves
    // an orphan package behind, which nothing but the account that created it
    // may write to afterwards: the CI would then be locked out of the very
    // cache it is supposed to feed.
    if (null === $source && str_starts_with($registry, 'ghcr.io/')) {
        throw new \RuntimeException('Could not tell which repository these images come from, and ghcr.io needs it to attach the packages to it. Add a "repository" variable to your context, holding either "org/project" or the full URL of the repository.');
    }

    $revision = get_source_revision();

    $c = context();

    // bake reads the compose file itself — "include:" and all — so the build
    // context, the dockerfile, the target, the args, the additional contexts
    // and the cache-from all come from there, already interpolated. Only the
    // cache-to has no compose equivalent, and profiles do not apply: bake sees
    // every service that has a "build".
    $command = ['docker', 'buildx', 'bake', '-f', $c->workingDirectory . '/compose.yaml'];

    foreach ($targets as $service => $cacheTo) {
        $command[] = '--set';
        $command[] = \sprintf('%s.cache-to=%s,mode=max', $service, $cacheTo);

        $cacheRef = get_cache_reference($cacheTo);

        // A cache living anywhere but in a registry — "type=gha", "type=local"
        // — names no repository to publish an image to, so that service keeps
        // pushing its cache and nothing else.
        if (null === $cacheRef) {
            continue;
        }

        $image = get_image_reference($cacheRef, $tag);

        if ($image === $cacheRef) {
            throw new \RuntimeException(\sprintf('Pushing "%s" under the tag "%s" would overwrite the build cache of "%s". Pick another --tag.', $image, $tag, $service));
        }

        $command[] = '--set';
        $command[] = \sprintf('%s.tags=%s', $service, $image);
        // Per target, rather than a global "--push": the services whose cache
        // is not a registry one must not be pushed anywhere.
        $command[] = '--set';
        $command[] = \sprintf('%s.output=type=registry', $service);
        // Only ghcr.io is refused a push it could not label, other registries
        // read no such thing and have no reason to turn a push down.
        if (null !== $source) {
            $command[] = '--set';
            $command[] = \sprintf('%s.labels.org.opencontainers.image.source=%s', $service, $source);
        }

        if (null !== $revision) {
            $command[] = '--set';
            $command[] = \sprintf('%s.labels.org.opencontainers.image.revision=%s', $service, $revision);
        }
    }

    if ($dryRun) {
        $command[] = '--print';
    }

    // Naming the targets is what keeps the services that build without a cache
    // out: bake's default group is every buildable service of the project.
    run([...$command, ...array_keys($targets)], context: $c->withEnvironment([
        // bake does not go through docker_compose(), so the variables the
        // generated compose file interpolates have to be given to it here.
        'COMPOSE_PROJECT_NAME' => get_project_name($c),
        'PROJECT_NAME' => get_project_name($c),
        'REGISTRY' => $registry,
        // The build contexts live in the plugin, outside of the project
        // directory, which bake asks to confirm on every run otherwise.
        'BUILDX_BAKE_ENTITLEMENTS_FS' => '0',
    ]));
}

/**
 * A compose "cache_from" accepts both a bare image reference and a full
 * "type=...,ref=..." entry, but "--set <target>.cache-to=" only understands the
 * latter — buildx rejects a bare reference there.
 */
function normalize_cache_entry(string $cacheFrom): string
{
    foreach (explode(',', $cacheFrom) as $field) {
        if (str_starts_with($field, 'type=')) {
            return $cacheFrom;
        }
    }

    return 'type=registry,ref=' . $cacheFrom;
}

/**
 * The image a registry cache entry is stored in, null for a cache that lives
 * outside of a registry.
 */
function get_cache_reference(string $cacheEntry): ?string
{
    $ref = null;

    foreach (explode(',', $cacheEntry) as $field) {
        if ('type=registry' === $field) {
            continue;
        }

        if (str_starts_with($field, 'type=')) {
            return null;
        }

        if (str_starts_with($field, 'ref=')) {
            $ref = substr($field, 4);
        }
    }

    return '' === $ref ? null : $ref;
}

/**
 * The same repository as the cache, under another tag: one package holds them
 * both, and linking that package is what the image is pushed for.
 */
function get_image_reference(string $cacheRef, string $tag): string
{
    $repository = explode('@', $cacheRef)[0];
    $colon = strrpos($repository, ':');

    // The colon of a "registry:5000/image" is the port, not a tag.
    if (false !== $colon && $colon > (strrpos($repository, '/') ?: 0)) {
        $repository = substr($repository, 0, $colon);
    }

    return $repository . ':' . $tag;
}

/**
 * Where the images come from, as "org.opencontainers.image.source" spells it.
 *
 * GitHub attaches a package to the repository this names, and a package
 * attached to a repository inherits its permissions — which is how everyone
 * who may push to the repository may push its images, and not only whoever
 * pushed them first.
 */
function get_source_url(): ?string
{
    $repository = variable('repository', '');

    if ('' !== $repository) {
        return normalize_source_url($repository);
    }

    $repository = getenv('GITHUB_REPOSITORY');

    if (\is_string($repository) && '' !== $repository) {
        $server = getenv('GITHUB_SERVER_URL') ?: 'https://github.com';

        return rtrim($server, '/') . '/' . $repository;
    }

    $remote = capture_git(['git', 'remote', 'get-url', 'origin']);

    return null === $remote ? null : normalize_source_url($remote);
}

function get_source_revision(): ?string
{
    $revision = getenv('GITHUB_SHA');

    if (\is_string($revision) && '' !== $revision) {
        return $revision;
    }

    return capture_git(['git', 'rev-parse', 'HEAD']);
}

/**
 * The forms a git remote takes — scp-like, ssh://, https:// — and the
 * "org/project" shorthand a context may hold, as the browsable URL GitHub
 * expects.
 */
function normalize_source_url(string $remote): ?string
{
    $remote = trim($remote);

    if ('' === $remote) {
        return null;
    }

    if (preg_match('#^ssh://(?:[^@/]+@)?(.+)$#', $remote, $matches)) {
        $remote = 'https://' . $matches[1];
    } elseif (preg_match('#^(?:[\w.-]+@)?([\w.-]+):(?!//)(.+)$#', $remote, $matches)) {
        $remote = 'https://' . $matches[1] . '/' . ltrim($matches[2], '/');
    } elseif (preg_match('#^[\w.-]+/[\w.-]+$#', $remote)) {
        $remote = 'https://github.com/' . $remote;
    }

    return preg_replace('#\.git$#', '', $remote);
}

/**
 * @param list<string> $command
 */
function capture_git(array $command): ?string
{
    try {
        $output = trim(capture($command, context: context()->withQuiet()->withAllowFailure()));
    } catch (\Throwable) {
        // No git on this machine, or a directory git does not track: the
        // images have no repository to name, which is all the caller asked.
        return null;
    }

    return '' === $output ? null : $output;
}

/**
 * The compose services of the project, fully resolved, whatever profile they
 * belong to.
 *
 * @return array<string, array{build?: array{cache_from?: list<string>}}>
 */
function get_services(): array
{
    return json_decode(
        docker_compose(
            ['config', '--format', 'json'],
            context()->withQuiet(),
            profiles: ['*'],
        )->getOutput(),
        true,
    )['services'];
}
