<?php

declare(strict_types=1);

namespace Castor\Docker;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsTask;
use Castor\Context;
use Symfony\Component\Console\Completion\CompletionInput;

use function Castor\capture;
use function Castor\context;
use function Castor\io;
use function Castor\run;

/**
 * The upstream cloudflared image. Its entrypoint already passes
 * "--no-autoupdate": a container is replaced, not updated in place.
 */
function get_tunnel_image(): string
{
    return 'cloudflare/cloudflared:2026.9.1';
}

/**
 * How long "docker:tunnel:start" waits for Cloudflare to hand out the URLs,
 * in seconds.
 */
function get_tunnel_timeout(): int
{
    return 30;
}

/**
 * Every domain of the project a tunnel can be opened to, with the compose
 * service serving it.
 *
 * Read from the same labels as "docker:about", so a domain declared by a
 * service, by an #[AsDockerComposeBuilder] function or straight in
 * compose.override.yaml can be tunnelled alike. A wildcard is left out: it is a
 * family of names, and a tunnel has to rewrite the Host to a single one.
 *
 * @return array<string, string> the service, keyed by domain
 */
function get_tunnel_domains(?Context $c = null): array
{
    $domains = [];

    foreach (get_project_urls($c) as $service => $urls) {
        foreach ($urls as $url) {
            // The HTTPS and the plain HTTP site of a domain are one tunnel: it
            // always reaches the router over HTTPS.
            $domain = (string) preg_replace('#^https?://#', '', $url);

            if (str_starts_with($domain, '*.')) {
                continue;
            }

            $domains[$domain] ??= $service;
        }
    }

    return $domains;
}

/**
 * Completion callback for the domains argument of the "docker:tunnel:*" tasks.
 *
 * The argument takes several domains: the ones already on the command line are
 * not offered again. The word being completed is on it too, and has to stay
 * offered, or a domain typed in full would not complete.
 *
 * @return list<string>
 */
function autocomplete_tunnel_domain(CompletionInput $input): array
{
    $given = $input->hasArgument('domains') ? (array) $input->getArgument('domains') : [];
    $given = array_diff($given, [$input->getCompletionValue()]);

    return array_values(array_diff(array_keys(get_tunnel_domains()), $given));
}

/**
 * The container holding the tunnel of a domain. One per domain: a quick tunnel
 * gets one public host name, and forwards everything to a single origin.
 */
function get_tunnel_container_name(string $project, string $domain): string
{
    return "{$project}-tunnel-{$domain}";
}

/**
 * The "docker run" starting the tunnel of a domain.
 *
 * The container joins the network of the global router rather than the one of
 * the project, and sends everything to the router: every domain is then reached
 * the way a browser reaches it, whatever serves it behind. It goes over HTTPS,
 * because a service that does not allow plain HTTP answers it with a redirection
 * to its *local* domain.
 *
 * The Host is rewritten to the local domain, since that is what the router
 * routes on, and so is the SNI, which is what the router mints its on-demand
 * certificate for. cloudflared passes the public host name on in
 * X-Forwarded-Host, which the router hands down to the service untouched (see
 * the trusted_proxies of its Caddyfile). The certificate is left unverified:
 * the router signs it with a local CA the container knows nothing about, and the
 * traffic never leaves the docker host.
 *
 * The containers carry labels of their own rather than the compose ones:
 * "docker:up" runs compose with --remove-orphans, which would take a tunnel of
 * the project down — and a quick tunnel never comes back on the same URL.
 *
 * @return list<string>
 */
function get_tunnel_command(string $project, string $domain): array
{
    return [
        'docker', 'run', '--detach',
        '--name', get_tunnel_container_name($project, $domain),
        '--network', get_router_name() . '_default',
        '--label', 'castor.tunnel=1',
        '--label', 'castor.tunnel.project=' . $project,
        '--label', 'castor.tunnel.domain=' . $domain,
        get_tunnel_image(),
        'tunnel',
        '--url', 'https://' . get_router_name() . ':443',
        '--http-host-header', $domain,
        '--origin-server-name', $domain,
        '--no-tls-verify',
    ];
}

/**
 * The public URL a quick tunnel was given, read from the logs of its container,
 * or null while cloudflared has not got one.
 *
 * The last one wins: a container restarted by hand asks for a new URL, and the
 * logs still hold the previous one. api.trycloudflare.com is where the URL is
 * asked for, and shows up in the logs when asking fails.
 */
function parse_tunnel_url(string $logs): ?string
{
    if (!preg_match_all('#https://(?!api\.)[a-z0-9-]+\.trycloudflare\.com\b#', $logs, $matches)) {
        return null;
    }

    return end($matches[0]) ?: null;
}

/**
 * The tunnel containers of the project, running or not.
 *
 * @return array<string, array{container: string, running: bool}> keyed by domain
 */
function get_project_tunnels(?Context $c = null): array
{
    $c ??= context();

    try {
        $output = capture([
            'docker', 'ps', '--all',
            '--filter', 'label=castor.tunnel.project=' . get_project_name($c),
            '--format', '{{.Label "castor.tunnel.domain"}}{{"\t"}}{{.Names}}{{"\t"}}{{.State}}',
        ], context: $c->withQuiet()->withAllowFailure());
    } catch (\Throwable) {
        return [];
    }

    $tunnels = [];

    foreach (explode("\n", $output) as $line) {
        $fields = explode("\t", trim($line));

        if (3 !== \count($fields) || '' === $fields[0]) {
            continue;
        }

        $tunnels[$fields[0]] = ['container' => $fields[1], 'running' => 'running' === $fields[2]];
    }

    ksort($tunnels);

    return $tunnels;
}

/**
 * The logs of a tunnel container. cloudflared writes them on stderr, which
 * "docker logs" keeps apart from stdout.
 */
function get_tunnel_logs(string $container, ?Context $c = null): string
{
    $process = run(['docker', 'logs', $container], context: ($c ?? context())->withQuiet()->withAllowFailure());

    return $process->getOutput() . $process->getErrorOutput();
}

/**
 * Wait for Cloudflare to give each container its URL.
 *
 * A null URL is a tunnel that exited or timed out, returned with its logs so
 * the caller can tell why.
 *
 * @param array<string, string> $containers keyed by domain
 *
 * @return array<string, array{url: ?string, logs: string}> keyed by domain
 */
function wait_for_tunnel_urls(array $containers, ?Context $c = null): array
{
    $c ??= context();
    $deadline = microtime(true) + get_tunnel_timeout();
    $results = [];

    while (true) {
        foreach ($containers as $domain => $container) {
            $logs = get_tunnel_logs($container, $c);
            $url = parse_tunnel_url($logs);

            // cloudflared exits when it cannot get a quick tunnel — Cloudflare
            // limits how many may be asked for — and waiting longer changes
            // nothing then.
            if (null === $url && 'true' === capture(['docker', 'inspect', '-f', '{{.State.Running}}', $container], context: $c->withQuiet()->withAllowFailure())) {
                continue;
            }

            $results[$domain] = ['url' => $url, 'logs' => $logs];
            unset($containers[$domain]);
        }

        if (!$containers || microtime(true) > $deadline) {
            break;
        }

        usleep(500_000);
    }

    foreach ($containers as $domain => $container) {
        $results[$domain] = ['url' => null, 'logs' => get_tunnel_logs($container, $c)];
    }

    return $results;
}

/**
 * Remove the tunnel containers of the project, or only the ones of the given
 * domains.
 *
 * Removed rather than stopped: a quick tunnel never comes back on the same URL,
 * so there is nothing worth keeping in a stopped one.
 *
 * @param list<string> $domains every tunnel of the project when empty
 *
 * @return list<string> the domains whose tunnel was closed
 */
function close_project_tunnels(array $domains = [], ?Context $c = null): array
{
    $c ??= context();
    $tunnels = get_project_tunnels($c);

    if ($domains) {
        $tunnels = array_intersect_key($tunnels, array_flip($domains));
    }

    if (!$tunnels) {
        return [];
    }

    run(['docker', 'rm', '--force', ...array_column($tunnels, 'container')], context: $c->withQuiet()->withAllowFailure());

    return array_keys($tunnels);
}

/**
 * Remove the tunnel containers of every project.
 *
 * Called before the router goes down: they are on its network, which compose
 * cannot remove while they are attached to it — and they have nothing left to
 * forward to anyway.
 */
function close_all_tunnels(?Context $c = null): void
{
    $c ??= context();

    try {
        $ids = array_filter(explode("\n", capture(
            ['docker', 'ps', '--all', '--quiet', '--filter', 'label=castor.tunnel=1'],
            context: $c->withQuiet()->withAllowFailure(),
        )));
    } catch (\Throwable) {
        return;
    }

    if (!$ids) {
        return;
    }

    run(['docker', 'rm', '--force', ...$ids], context: $c->withQuiet()->withAllowFailure());
}

/**
 * @param list<string> $domains
 */
#[AsTask(name: 'start', namespace: 'docker:tunnel', description: 'Opens a public HTTPS tunnel to the domains of the project', aliases: ['tunnel'])]
function tunnel_start(
    #[AsArgument(description: 'The domains to tunnel, every domain of the project when omitted', autocomplete: 'Castor\Docker\autocomplete_tunnel_domain')]
    array $domains = [],
): void {
    $c = context();
    $project = get_project_name($c);
    $routed = get_tunnel_domains($c);

    io()->title(\sprintf('Tunnelling "%s"', $project));

    if (!$routed) {
        io()->error('This project routes no domain: there is nothing to tunnel.');
        io()->note('Give a service a domain with withDomain() to reach it over HTTP.');

        return;
    }

    // Checked before anything is opened: a typo in the last domain should not
    // leave the others tunnelled behind an error.
    if ($unknown = array_diff($domains, array_keys($routed))) {
        io()->error(\sprintf('The project does not route %s.', implode(', ', array_map(static fn(string $domain): string => "\"{$domain}\"", $unknown))));
        io()->note('Available: ' . implode(', ', array_keys($routed)));

        return;
    }

    if (!is_router_running()) {
        io()->error('The router is not running: the tunnels reach the project through it.');
        io()->note('Start the project with "castor docker:up", or the router with "castor docker:router:enable".');

        return;
    }

    warn_if_router_outdated('It may overwrite the X-Forwarded-Host the tunnels send, and the application then sees its local domain rather than the public one.');

    $selected = $domains ? array_intersect_key($routed, array_flip($domains)) : $routed;
    $existing = get_project_tunnels($c);
    $containers = [];

    foreach (array_keys($selected) as $selectedDomain) {
        $tunnel = $existing[$selectedDomain] ?? null;

        if (null !== $tunnel && $tunnel['running']) {
            $containers[$selectedDomain] = $tunnel['container'];

            continue;
        }

        // A tunnel that exited — the quick tunnel could not be created, the
        // docker daemon restarted — has no URL left to give.
        if (null !== $tunnel) {
            run(['docker', 'rm', '--force', $tunnel['container']], context: $c->withQuiet()->withAllowFailure());
        }

        io()->comment(\sprintf('Opening a tunnel to %s', $selectedDomain));
        // No timeout: the first one pulls the cloudflared image.
        run(get_tunnel_command($project, $selectedDomain), context: $c->withQuiet()->withTimeout(null));
        $containers[$selectedDomain] = get_tunnel_container_name($project, $selectedDomain);
    }

    $results = wait_for_tunnel_urls($containers, $c);
    $running = get_running_service_names($c);
    $rows = [];
    $failed = [];

    foreach ($selected as $selectedDomain => $service) {
        ['url' => $url, 'logs' => $logs] = $results[$selectedDomain];

        if (null === $url) {
            $failed[$selectedDomain] = $logs;
        }

        $rows[] = [
            $service,
            $selectedDomain,
            null === $url ? '<fg=red>failed</>' : \sprintf('<href=%s>%s</>', $url, $url),
            status_label($service, $running),
        ];
    }

    io()->table(['Service', 'Domain', 'Public URL', 'Status'], $rows);

    foreach ($failed as $failedDomain => $logs) {
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $logs))));

        io()->error(array_merge(
            [\sprintf('No URL came for %s, the last lines cloudflared wrote are:', $failedDomain)],
            \array_slice($lines, -5) ?: ['(nothing)'],
        ));
    }

    if (\count($failed) === \count($selected)) {
        return;
    }

    io()->note([
        'A new URL may take a few seconds to answer, the time for its DNS record to spread.',
        'Anyone with the URL can reach the service: close the tunnels with "castor docker:tunnel:stop". "castor docker:stop" closes them too.',
    ]);
}

/**
 * @param list<string> $domains
 */
#[AsTask(name: 'stop', namespace: 'docker:tunnel', description: 'Closes the public tunnels of the project')]
function tunnel_stop(
    #[AsArgument(description: 'The domains whose tunnel to close, all of them when omitted', autocomplete: 'Castor\Docker\autocomplete_tunnel_domain')]
    array $domains = [],
): void {
    $closed = close_project_tunnels($domains);

    if (!$closed) {
        io()->comment($domains ? \sprintf('No tunnel is open to %s.', implode(', ', $domains)) : 'No tunnel is open.');

        return;
    }

    io()->success(\sprintf('Closed the tunnel of %s.', implode(', ', $closed)));
}
