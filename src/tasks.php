<?php

declare(strict_types=1);

namespace Castor\Docker;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Installer\ListenerEditor;
use Castor\Docker\Installer\NeedsDatabase;
use Castor\Docker\Service\ServiceInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

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

#[AsTask(description: 'Install a service, register it in castor.php, then build and start it', namespace: 'docker:service', name: 'install')]
function service_install(
    #[AsArgument(description: 'The service to install (omit to list the available ones)', autocomplete: 'Castor\Docker\autocomplete_installer_name')]
    ?string $name = null,
    #[AsOption(description: 'The file holding the RegisterServiceEvent listener (defaults to castor.php)')]
    ?string $file = null,
): void {
    $installers = collect_service_installers();

    if ($name === null || !isset($installers[$name])) {
        if ($name !== null) {
            io()->error(\sprintf('Unknown service "%s".', $name));
        }

        io()->section('Available services');
        foreach ($installers as $installer) {
            io()->writeln(\sprintf('  <info>%s</info> — %s', $installer->getName(), $installer->getDescription()));
        }

        return;
    }

    $installer = $installers[$name];
    $c = context();
    $file ??= $c->workingDirectory . '/castor.php';

    io()->title(\sprintf('Installing "%s"', $installer->getName()));

    $answers = ask_installer_inputs($installer);

    $source = is_file($file) ? (file_get_contents($file) ?: "<?php\n") : "<?php\n";
    $editor = new ListenerEditor($source);

    /** @var ServiceInterface[] $extraServices */
    $extraServices = [];

    if ($installer instanceof NeedsDatabase) {
        $database = resolve_database_link($editor, $installers);
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

    docker_compose(['down', '--remove-orphans', '--volumes', '--rmi=local']);
}

#[AsTask(description: 'Push images cache to the registry', namespace: 'docker', name: 'push', aliases: ['push'])]
function push(bool $dryRun = false): void
{
    $registry = variable('registry');

    if (!$registry) {
        throw new \RuntimeException('You must define a registry to push images.');
    }

    // Generate bake file
    $targets = [];

    foreach (get_services() as $service => $config) {
        $cacheFrom = $config['build']['cache_from'][0] ?? null;

        if (null === $cacheFrom) {
            continue;
        }

        $cacheFrom = explode(',', $cacheFrom);
        $reference = null;
        $type = null;

        if (1 === \count($cacheFrom)) {
            $reference = $cacheFrom[0];
            $type = 'registry';
        } else {
            foreach ($cacheFrom as $part) {
                $from = explode('=', $part);

                if (2 !== \count($from)) {
                    continue;
                }

                if ('type' === $from[0]) {
                    $type = $from[1];
                }

                if ('ref' === $from[0]) {
                    $reference = $from[1];
                }
            }
        }

        $targets[$service] = [
            'reference' => $reference,
            'type' => $type,
            'context' => $config['build']['context'],
            'dockerfile' => $config['build']['dockerfile'] ?? 'Dockerfile',
            'target' => $config['build']['target'] ?? null,
            'contexts' => $config['build']['additional_contexts'] ?? [],
            'args' => $config['build']['args'] ?? [],
        ];
    }

    $content = \sprintf(<<<'EOHCL'
        group "default" {
            targets = [%s]
        }

        EOHCL, implode(', ', array_map(fn($name) => \sprintf('"%s"', $name), array_keys($targets))));

    foreach ($targets as $service => $target) {
        $additionalContexts = "";
        $args = "";

        foreach ($target['contexts'] as $name => $path) {
            $additionalContexts .= \sprintf("%s = \"%s\"\n", $name, $path);
        }

        foreach ($target['args'] as $key => $value) {
            $args .= \sprintf("%s = \"%s\"\n", $key, $value);
        }

        $content .= \sprintf(<<<'EOHCL'
            target "%s" {
                context    = "%s"
                contexts   = {
                    %s
                }
                dockerfile = "%s"
                cache-from = ["%s"]
                cache-to   = ["type=%s,ref=%s,mode=max"]
                target     = "%s"
                args = {
                    %s
                }
            }

            EOHCL, $service, $target['context'], $additionalContexts, $target['dockerfile'], $target['reference'], $target['type'], $target['reference'], $target['target'], $args);
    }

    if ($dryRun) {
        io()->write($content);

        return;
    }

    // write bake file in tmp file
    $bakeFile = tempnam(sys_get_temp_dir(), 'bake');
    file_put_contents($bakeFile, $content);

    // Run bake
    run(['docker', 'buildx', 'bake', '-f', $bakeFile], context: context()->withEnvironment([
        'BUILDX_BAKE_ENTITLEMENTS_FS' => '0',
    ]));
}

/**
 * @return array<string, array{profiles?: list<string>, build: array{context: string, dockerfile?: string, cache_from?: list<string>, target?: string, additional_contexts?: array<string, string>, args?: array<string, string>}}>
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
