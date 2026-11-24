<?php

declare(strict_types=1);

namespace Castor\Docker;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\ServiceInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

use function Castor\capture;
use function Castor\context;
use function Castor\get_cache;
use function Castor\io;
use function Castor\run;

/**
 * The "expose" task of a service, forwarding its TCP port to the host.
 *
 * @return array{task: AsTask, function: \Closure}
 */
function expose_task(ServiceInterface $service, int $containerPort, ?string $protocol = null): array
{
    $name = $service->getName();

    return [
        'task' => new AsTask('expose', $name, description: \sprintf('Expose the %s service%s over TCP on the host (--stop to stop)', $name, $protocol ? " ({$protocol})" : '')),
        'function' => static function (
            #[AsArgument(description: 'Host port to expose on (defaults to the service port)')]
            ?int $port = null,
            bool $stop = false,
        ) use ($name, $containerPort): void {
            expose_service_port($name, $containerPort, $port, $stop);
        },
    ];
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
