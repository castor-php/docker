<?php

declare(strict_types=1);

namespace Castor\Docker;

use Castor\Attribute\AsListener;
use Castor\Container;
use Castor\Context;
use Castor\Descriptor\TaskDescriptor;
use Castor\Docker\Event\RegisterServiceEvent;
use Castor\Docker\Event\RegisterServiceInstallerEvent;
use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Installer\ClickhouseInstaller;
use Castor\Docker\Installer\DatabaseServiceInstaller;
use Castor\Docker\Installer\ElasticsearchInstaller;
use Castor\Docker\Installer\InputType;
use Castor\Docker\Installer\ListenerEditor;
use Castor\Docker\Installer\MailpitInstaller;
use Castor\Docker\Installer\MariaDBInstaller;
use Castor\Docker\Installer\MySQLInstaller;
use Castor\Docker\Installer\PostgresInstaller;
use Castor\Docker\Installer\RabbitMQInstaller;
use Castor\Docker\Installer\RedisInstaller;
use Castor\Docker\Installer\RustInstaller;
use Castor\Docker\Installer\ServiceInstaller;
use Castor\Docker\Installer\SymfonyInstaller;
use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\DatabaseServiceInterface;
use Castor\Docker\Service\ServiceInterface;
use Castor\Event\ContextCreatedEvent;
use Castor\Event\FunctionsResolvedEvent;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

use function Castor\capture;
use function Castor\context;
use function Castor\fs;
use function Castor\get_cache;
use function Castor\input;
use function Castor\io;
use function Castor\run;
use function Castor\variable;
use function Castor\yaml_dump;
use function Castor\yaml_parse;

/**
 * Get default Docker Compose profiles to activate.
 *
 * @return list<string>
 */
function get_default_profiles(): array
{
    return ['default'];
}

/**
 * @param list<string> $subCommand
 * @param list<string> $profiles
 */
function docker_compose(array $subCommand, ?Context $c = null, array $profiles = []): Process
{
    $c ??= context();
    $profiles = $profiles ?: get_default_profiles();

    $c = $c
        ->withTimeout(null)
        ->withEnvironment([
            'PROJECT_NAME' => get_project_name($c),
            'PROJECT_ROOT_DOMAIN' => $c->data['root_domain'] ?? 'local.test',
            'REGISTRY' => variable('registry'),
        ])
    ;

    $command = [
        'docker',
        'compose',
    ];

    foreach ($profiles as $profile) {
        $command[] = '--profile';
        $command[] = $profile;
    }

    $command[] = '-f';
    $command[] = $c->workingDirectory . '/compose.yaml';

    $command = array_merge($command, $subCommand);

    // The global router is not a service of this compose file: it joins the
    // project network from the outside, so it has to be attached once the
    // network exists, and detached before "down" removes it.
    $network = get_project_network($c);
    $subCommandName = $subCommand[0] ?? null;

    if ('down' === $subCommandName) {
        disconnect_router_from_network($network);
    }

    $process = run($command, context: $c);

    if ('up' === $subCommandName) {
        connect_router_to_network($network);
    }

    return $process;
}

/**
 * The docker compose project name.
 *
 * Read from the "name" of compose.yaml rather than from the context data: the
 * context is only enriched when castor instantiates a declared #[AsContext],
 * and a project that declares none boots on a bare one.
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

/**
 * The default network compose creates for this project.
 */
function get_project_network(?Context $c = null): string
{
    $c ??= context();

    return get_project_name($c) . '_default';
}

function docker_compose_run(
    string $runCommand,
    string $service,
    ?Context $c = null,
    bool $noDeps = true,
    ?string $workDir = null,
    bool $portMapping = false,
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

    $command[] = $service;
    $command[] = '/bin/sh';
    $command[] = '-c';
    $command[] = "exec {$runCommand}";

    return docker_compose($command, c: $c);
}

function docker_exit_code(
    string $runCommand,
    string $service = 'builder',
    ?Context $c = null,
    bool $noDeps = true,
    ?string $workDir = null,
    bool $portMapping = false,
): int {
    $c = ($c ?? context())->withAllowFailure();

    $process = docker_compose_run(
        runCommand: $runCommand,
        service: $service,
        c: $c,
        noDeps: $noDeps,
        workDir: $workDir,
    );

    return $process->getExitCode() ?? 0;
}

/**
 * Expose a service's TCP port on the host, or stop exposing it when $stop is true.
 *
 * Runs a small socat forwarder container that publishes $hostPort and forwards
 * it to $service:$containerPort over the project network — handy to reach a
 * database or broker from the host without publishing a port statically. There
 * is one forwarder per service: calling this again replaces it, and $stop
 * removes it. The forwarder is tagged as an orphan of the compose project so
 * "docker:destroy" tears it down with the rest of the infrastructure.
 *
 * Meant to be wired into a service task, e.g. "castor mysql:expose [port] [--stop]".
 *
 * The exposed set is remembered in the cache so "docker:up" can restore it (see
 * restore_exposed_services()) — the user never has to re-expose after a restart.
 */
function expose_service_port(string $service, int $containerPort, ?int $hostPort = null, bool $stop = false): void
{
    $hostPort ??= $containerPort;
    $context = context();
    $project = get_project_name($context);
    $name = "{$project}-expose-{$service}";

    // Remove any existing forwarder first (idempotent, and how --stop works).
    run(['docker', 'rm', '-f', $name], context: $context->withQuiet()->withAllowFailure());

    $exposed = get_exposed_services();

    if ($stop) {
        unset($exposed[$service]);
        set_exposed_services($exposed);

        io()->success("Stopped exposing the \"{$service}\" service.");

        return;
    }

    run([
        'docker', 'run', '--detach',
        '--name', $name,
        '--network', "{$project}_default",
        '--publish', "{$hostPort}:{$hostPort}",
        '--restart', 'unless-stopped',
        // Tag as an orphan of the compose project so "docker:destroy"
        // ("compose down --remove-orphans") tears it down with the rest, and
        // with our own label so "docker:stop" can find every forwarder.
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
 * @return array<string, array{container_port: int, host_port: int}>
 */
function get_exposed_services(): array
{
    $item = get_cache()->getItem('infrastructure.exposed');
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
function set_exposed_services(array $exposed): void
{
    $item = get_cache()->getItem('infrastructure.exposed');
    $item->set($exposed);

    get_cache()->save($item);
}

/**
 * Re-create the forwarder of every remembered exposed service, so the exposed
 * ports come back after "docker:up" without the user re-running each task.
 * Services whose forwarder is already running are left untouched.
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

        expose_service_port($service, $ports['container_port'], $ports['host_port']);
    }
}

/**
 * Stop every "<service>:expose" forwarder container of the current project.
 * Called by "docker:stop" so the exposed ports go down with the infrastructure.
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
 * Castor only dispatches ContextCreatedEvent when it instantiates a context
 * declared with #[AsContext]. A project that declares none never goes through
 * it — neither does "castor list", whatever the project — so this also runs on
 * boot, where it is idempotent.
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

    return $context->withData([
        'project_name' => $projectName,
        'user_id' => $userId,
    ]);
}

/**
 * Dispatch RegisterServiceEvent and return the registered services.
 *
 * Note: The Caddy router is no longer automatically registered as a service.
 * It runs globally and is managed via docker:router:* commands.
 *
 * @return ServiceInterface[]
 */
function collect_services(): array
{
    $event = new RegisterServiceEvent();

    Container::get()->eventDispatcher->dispatch($event);

    return $event->services;
}

/**
 * Create the host directories the services bind-mount, when they do not exist
 * yet.
 *
 * Docker creates a missing bind mount source itself, but as root: the shared
 * home directory, an application directory or any other mount would then be
 * read-only for the very user the containers run as, and would need sudo to be
 * removed. Creating them first, from the user running castor, avoids it.
 *
 * Only paths inside the project are created: a service may mount the docker
 * socket or any other system path, which is none of our business.
 */
function create_mount_directories(Context $c, ComposeBuilder $composeBuilder): void
{
    $root = Path::canonicalize($c->workingDirectory);

    foreach ($composeBuilder->getBindMountSources() as $source) {
        $path = Path::makeAbsolute($source, $root);

        if (!Path::isBasePath($root, $path)) {
            continue;
        }

        if (!file_exists($path)) {
            fs()->mkdir($path);

            continue;
        }

        // Left over from a previous run, or from an older version of this
        // plugin: docker created it as root and nothing here can fix it.
        if (!is_writable($path) && '_complete' !== input()->getFirstArgument()) {
            io()->warning(\sprintf('"%s" is not writable, the containers may fail to write in it. Take its ownership back with "sudo chown -R $(id -u):$(id -g) %s".', $path, $path));
        }
    }
}

/**
 * (Re)write compose.generated.yaml from the given services.
 *
 * @param ServiceInterface[] $services
 */
function generate_compose_file(Context $c, array $services): void
{
    $composeBuilder = new ComposeBuilder();

    foreach ($services as $service) {
        $composeBuilder = $service->updateCompose($c, $composeBuilder);
    }

    create_mount_directories($c, $composeBuilder);

    file_put_contents(
        $c->workingDirectory . '/compose.generated.yaml',
        "# This file is generated by Castor. Do not edit it manually.\n" . yaml_dump($composeBuilder->toArray(), inline: 5, flags: Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),
    );
}

/**
 * Build the installer registry: built-in installers plus any added by other
 * plugins through RegisterServiceInstallerEvent.
 *
 * @return array<string, ServiceInstaller>
 */
function collect_service_installers(): array
{
    $event = new RegisterServiceInstallerEvent();

    Container::get()->eventDispatcher->dispatch($event);

    return $event->installers;
}

/**
 * Ask every question of an installer, honouring defaults (and --no-interaction).
 *
 * @return array<string, mixed>
 */
function ask_installer_inputs(ServiceInstaller $installer): array
{
    $answers = [];

    foreach ($installer->getInputs() as $input) {
        $default = $input->resolveDefault($answers);

        $answers[$input->name] = match ($input->type) {
            InputType::Boolean => io()->confirm($input->label, (bool) $default),
            InputType::Choice => io()->choice($input->label, $input->choices, \is_string($default) ? $default : null),
            InputType::Integer => (int) io()->ask($input->label, $default === null ? null : (string) $default),
            InputType::Text => (string) io()->ask($input->label, $default === null ? null : (string) $default),
        };
    }

    return $answers;
}

/**
 * Resolve the database an app should link to: pick an existing one (extracting
 * it to a variable if needed), or install a fresh one, or none.
 *
 * @param array<string, ServiceInstaller> $installers
 *
 * @return array{variable: ?string, instance: ?DatabaseServiceInterface, services: ServiceInterface[]}
 */
function resolve_database_link(ListenerEditor $editor, array $installers): array
{
    $existing = [];

    foreach (collect_services() as $service) {
        if ($service instanceof DatabaseServiceInterface) {
            $existing[$service->getName()] = $service;
        }
    }

    if ($existing !== []) {
        $choice = io()->choice('Link the application to a database', [...array_keys($existing), '(none)'], (string) array_key_first($existing));

        if ($choice === '(none)') {
            return ['variable' => null, 'instance' => null, 'services' => []];
        }

        $instance = $existing[$choice];

        return [
            'variable' => $editor->ensureServiceVariable($instance::class, $choice) ?? $choice,
            'instance' => $instance,
            'services' => [],
        ];
    }

    if (!io()->confirm('No database is configured. Install one now?', true)) {
        return ['variable' => null, 'instance' => null, 'services' => []];
    }

    $databaseInstallers = array_filter($installers, static fn(ServiceInstaller $installer): bool => $installer instanceof DatabaseServiceInstaller);
    $names = array_map(static fn(ServiceInstaller $installer): string => $installer->getName(), $databaseInstallers);
    $installer = $installers[io()->choice('Which database?', array_values($names), 'postgres')];

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
    $event->addInstaller(new SymfonyInstaller());
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
    // Kernel::configureContext): it only needs the task list, and generating
    // the compose file from that context would drop the project configuration.
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
