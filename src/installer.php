<?php

declare(strict_types=1);

namespace Castor\Docker;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsListener;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;
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
use Castor\Docker\Installer\NeedsDatabase;
use Castor\Docker\Installer\NodeInstaller;
use Castor\Docker\Installer\PostgresInstaller;
use Castor\Docker\Installer\RabbitMQInstaller;
use Castor\Docker\Installer\RedisInstaller;
use Castor\Docker\Installer\RustFSInstaller;
use Castor\Docker\Installer\RustInstaller;
use Castor\Docker\Installer\ServiceInstaller;
use Castor\Docker\Installer\SymfonyInstaller;
use Castor\Docker\Service\DatabaseServiceInterface;
use Castor\Docker\Service\ServiceInterface;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Process;

use function Castor\app;
use function Castor\context;
use function Castor\dispatch;
use function Castor\input;
use function Castor\io;
use function Castor\variable;

/**
 * @param list<string> $tokens
 */
#[AsTask(description: 'Install a service, register it in castor.php, then build and start it', namespace: 'docker:service', name: 'install')]
function service_install(
    #[AsArgument(description: 'The service to install (omit to list the available ones)', autocomplete: 'Castor\Docker\autocomplete_installer_name')]
    ?string $name = null,
    #[AsOption(description: 'The file holding the RegisterServiceEvent listener (defaults to castor.php)')]
    ?string $file = null,
    // A service's options are only known once the service is, so they are
    // parsed out of the raw command line rather than declared here.
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

    // Regenerated in-process with the freshly created instances, so build/up
    // see the new services immediately.
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

    // The service's containers become orphans and are dropped below. Named
    // volumes are kept, so the data survives a re-install.
    $remaining = array_filter($services, static fn(ServiceInterface $s): bool => $s->getName() !== $name);
    generate_compose_file($c, $remaining);

    if (isset(get_exposed_services()[$name])) {
        expose_service_port($name, 0, stop: true);
    }

    docker_compose(['up', '--detach', '--no-build', '--remove-orphans']);

    io()->success(\sprintf('"%s" removed.', $name));
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
