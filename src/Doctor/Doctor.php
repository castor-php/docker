<?php

declare(strict_types=1);

namespace Castor\Docker\Doctor;

use Castor\Context;
use Symfony\Component\Filesystem\Path;

use function Castor\Docker\format_bytes;
use function Castor\Docker\get_project_name;
use function Castor\Docker\get_project_network;
use function Castor\Docker\get_router_certs_directory;
use function Castor\Docker\compare_router_configuration;
use function Castor\Docker\get_router_name;
use function Castor\Docker\get_worktree_base_domain;
use function Castor\Docker\get_worktree_name;
use function Castor\Docker\get_plugin_version;
use function Castor\Docker\is_worktree_domain;
use function Castor\Docker\worktree_root_domain;

/**
 * Only concludes: every question goes to the SystemProbe, so nothing here runs
 * a command or reads a file, and the tests can put it in front of any machine
 * they describe.
 *
 * A check that needs something another one reported missing is skipped rather
 * than failed, so the report points at the cause instead of its consequences.
 */
final class Doctor
{
    /** The compose.yaml of every project includes the generated file. */
    private const COMPOSE_INCLUDE = '2.20.0';
    /** The compose file of the router inlines its Caddyfile, with "configs.content". */
    private const COMPOSE_CONFIG_CONTENT = '2.23.1';
    /** Compose builds through Buildx from this release on, and refuses one older than BUILDX. */
    private const COMPOSE_BUILDS_WITH_BUILDX = '2.40.2';
    private const BUILDX = '0.17.0';

    private const DISK_ERROR = 2_000_000_000;
    private const DISK_WARNING = 10_000_000_000;

    /** @var array{client: ?string, server: ?string, platform: ?string, error: ?string}|null */
    private ?array $docker = null;

    /** @var array{name: string, os: string, rootDir: string, containerdStore: bool}|false|null */
    private array|false|null $dockerInfo = false;

    private string|false|null $composeVersion = false;

    /** @var array{version: ?string, checksum: ?string, socket: ?string, networks: list<string>}|false|null */
    private array|false|null $router = false;

    /** @var array<string, list<string>>|null */
    private ?array $projectUrls = null;

    /** @var list<array{id: string, service: string, oneOff: bool, state: string, status: string, size: ?int, image: string}>|null */
    private ?array $projectContainers = null;

    public function __construct(
        private readonly Context $context,
        private readonly SystemProbe $probe,
        private readonly ?string $pluginVersion = null,
    ) {}

    /**
     * @return array<string, list<Check>> the checks, by section
     */
    public function run(): array
    {
        $report = [
            'Docker' => $this->checkDocker(),
            'Project' => $this->checkProject(),
            'Router' => $this->checkRouter(),
            'Ports' => $this->checkPorts(),
            'HTTPS' => $this->checkHttps(),
            'DNS' => $this->checkDns(),
        ];

        if (null !== get_worktree_name($this->context)) {
            $report['Worktree'] = $this->checkWorktree();
        }

        return $report;
    }

    /**
     * @return list<Check>
     */
    public function checkDocker(): array
    {
        return [
            $this->checkDaemon(),
            $this->checkCompose(),
            $this->checkBuildx(),
            $this->checkDiskSpace(),
        ];
    }

    /**
     * @return list<Check>
     */
    public function checkProject(): array
    {
        return [
            $this->checkComposeFiles(),
            ...$this->checkContainers(),
            $this->checkUser(),
            ...$this->checkMounts(),
        ];
    }

    /**
     * @return list<Check>
     */
    public function checkRouter(): array
    {
        if (!$this->isDaemonReachable()) {
            return [Check::skipped('Router', 'Needs the Docker daemon.')];
        }

        $router = $this->router();
        $routes = [] !== $this->projectUrls();
        $checks = [];

        if (null === $router) {
            $checks[] = match (true) {
                !$routes => Check::ok('Router', 'Stopped, and not needed: this project routes no domain.'),
                $this->isProjectRunning() => Check::error('Router', 'Stopped while the project runs: none of its URLs answers.', 'Start it: castor docker:router:enable'),
                $this->probe->routerAutostart() => Check::ok('Router', 'Stopped, castor docker:up starts it along with the project.'),
                default => Check::warning('Router', 'Stopped, and its autostart is off: castor docker:up leaves it stopped.', 'Start it: castor docker:router:enable'),
            };
        } elseif (compare_router_configuration($router['version'], $router['checksum'], $this->pluginVersion) >= 0) {
            $checks[] = Check::ok('Router', \sprintf('Running, created by castor-php/docker %s, as recent as this project needs.', $router['version'] ?? '(version unknown)'));
        } else {
            // A running router is never recreated behind the projects it serves.
            $checks[] = Check::warning(
                'Router',
                \sprintf('Running an older configuration than the %s of this project, created by castor-php/docker %s.', $this->pluginVersion ?? get_plugin_version(), $router['version'] ?? '(version unknown)'),
                'Apply this one: castor docker:router:restart — it briefly interrupts every project the router serves.',
            );
        }

        // A project routing no domain has no use for a router nobody started.
        if ($routes || null !== $router) {
            $checks[] = $this->checkSocket($router);
        }

        if (null !== $router && $routes && $this->isProjectRunning()) {
            $network = get_project_network($this->context);

            $checks[] = \in_array($network, $router['networks'], true)
                ? Check::ok('Network', \sprintf('The router joined %s.', $network))
                : Check::error('Network', \sprintf('The router has not joined %s: it cannot reach the containers of the project.', $network), 'Make it join the running projects: castor docker:router:enable');
        }

        return $checks;
    }

    /**
     * @return list<Check>
     */
    public function checkPorts(): array
    {
        $checks = [];

        // The router is global, but only a project routing a domain needs it.
        if ([] !== $this->projectUrls()) {
            $checks[] = $this->checkPort(80, null);
            $checks[] = $this->checkPort(443, null);
        }

        foreach ($this->probe->projectPublishedPorts() as $service => $ports) {
            foreach ($ports as $port) {
                // A range, or a port compose reads from a variable, is not one
                // this check can look up.
                if (ctype_digit($port)) {
                    $checks[] = $this->checkPort((int) $port, $service);
                }
            }
        }

        return $checks ?: [Check::ok('Ports', 'The project binds no host port.')];
    }

    /**
     * @return list<Check>
     */
    public function checkHttps(): array
    {
        if ([] === $this->projectUrls()) {
            return [Check::skipped('HTTPS', 'This project routes no domain.')];
        }

        $caRoot = $this->probe->mkcertCaRoot();

        if (null === $caRoot) {
            return [Check::warning(
                'mkcert',
                'mkcert is not installed: the router signs with a CA of its own, which no browser trusts.',
                'Install mkcert (https://github.com/FiloSottile/mkcert), run mkcert -install, then castor docker:router:enable',
            )];
        }

        $ca = $caRoot . '/rootCA.pem';
        $hash = $this->probe->fileHash($ca);

        if (null === $hash) {
            return [Check::warning('mkcert', \sprintf('mkcert has no CA in %s yet.', $caRoot), 'Create it and trust it: mkcert -install, then castor docker:router:enable')];
        }

        $checks = [Check::ok('mkcert', \sprintf('The mkcert CA is in %s.', $caRoot))];

        // The Caddyfile imports the CA at startup, so a running router has to
        // be restarted to pick up a new copy.
        $copy = $this->probe->fileHash(get_router_certs_directory() . '/rootCA.pem');
        $apply = null !== $this->router() ? 'castor docker:router:restart' : 'castor docker:router:enable';

        if (null === $copy || null === $this->probe->fileHash(get_router_certs_directory() . '/caddy/ca.caddy')) {
            $checks[] = Check::warning('Router CA', 'The router has no copy of the mkcert CA: it signs with a CA of its own, which no browser trusts.', 'Copy it: ' . $apply);
        } elseif ($copy !== $hash) {
            $checks[] = Check::warning('Router CA', 'The router signs with another mkcert CA than the one installed now.', 'Copy the current one: ' . $apply);
        } else {
            $checks[] = Check::ok('Router CA', 'The router signs its certificates with the mkcert CA.');
        }

        $checks[] = match ($this->probe->isTrustedBySystem($ca)) {
            true => Check::ok('System trust', 'The system trusts the mkcert CA.'),
            false => Check::warning('System trust', 'The system does not trust the mkcert CA: curl, and the browsers reading the system store, reject the certificates of the router.', 'Trust it: mkcert -install'),
            null => Check::skipped('System trust', 'Cannot ask the trust store of this system.'),
        };

        // The browser of a WSL user runs on Windows, which keeps a store of its
        // own that mkcert, run in the distribution, never touches.
        if ('wsl' === $this->probe->platform()) {
            $checks[] = match ($this->probe->isTrustedByWindows($ca)) {
                true => Check::ok('Windows trust', 'Windows trusts the mkcert CA.'),
                false => Check::warning(
                    'Windows trust',
                    'Windows does not trust the mkcert CA: a browser running on Windows rejects the certificates of the router.',
                    'Trust it on Windows: certutil.exe -user -addstore Root "$(wslpath -w "$(mkcert -CAROOT)/rootCA.pem")"',
                ),
                null => Check::skipped('Windows trust', 'Cannot run certutil.exe from this distribution.'),
            };
        }

        return $checks;
    }

    /**
     * @return list<Check>
     */
    public function checkDns(): array
    {
        $domains = $this->projectDomains();

        if (!$domains) {
            return [Check::skipped('DNS', 'This project routes no domain.')];
        }

        // Browsers, curl and systemd-resolved send every "*.localhost" to the
        // loopback by themselves (RFC 6761), whatever the resolver says.
        $domains = array_values(array_filter($domains, static fn(string $domain): bool => 'localhost' !== $domain && !str_ends_with($domain, '.localhost')));

        if (!$domains) {
            return [Check::ok('This machine', 'Every domain is under .localhost, which resolves to this machine by itself.')];
        }

        $resolved = [];

        foreach ($domains as $domain) {
            $resolved[$domain] = $this->probe->resolve($domain);
        }

        $checks = [$this->checkResolution('This machine', $resolved, '/etc/hosts')];

        // WSL resolves with its own /etc/hosts, the browser on Windows with the
        // hosts file of Windows: both have to know the domains.
        if ('wsl' === $this->probe->platform()) {
            $windows = $this->probe->resolveOnWindows($domains);

            $checks[] = null === $windows
                ? Check::skipped('Windows', 'Cannot run powershell.exe from this distribution.')
                : $this->checkResolution('Windows', array_map(static fn(string $domain): array => $windows[$domain] ?? [], array_combine($domains, $domains)), 'C:\Windows\System32\drivers\etc\hosts, as an administrator');
        }

        return $checks;
    }

    /**
     * @return list<Check>
     */
    public function checkWorktree(): array
    {
        $worktree = get_worktree_name($this->context);

        if (null === $worktree) {
            return [];
        }

        $root = worktree_root_domain($worktree, get_worktree_base_domain($this->context));
        $checks = [Check::ok('Checkout', \sprintf('The %s worktree, a stack of its own: the %s compose project, served under %s.', $worktree, get_project_name($this->context), $root))];

        $shared = array_values(array_filter($this->projectDomains(), fn(string $domain): bool => !is_worktree_domain($domain, $this->context)));

        $checks[] = $shared
            ? Check::warning(
                'Domains',
                \sprintf('%s: not under %s, so the router hands %s to whichever checkout it sees first.', implode(', ', $shared), $root, 1 === \count($shared) ? 'it' : 'them'),
                \sprintf('Declare %s under %s: derived from root_domain, a domain follows the checkout.', 1 === \count($shared) ? 'it' : 'them', $root),
            )
            : Check::ok('Domains', \sprintf('Every domain is under %s.', $root));

        $ports = [];

        foreach ($this->probe->projectPublishedPorts() as $service => $published) {
            foreach ($published as $port) {
                $ports[] = \sprintf('%s (%s)', $port, $service);
            }
        }

        $checks[] = $ports
            ? Check::warning(
                'Host ports',
                \sprintf('%s: a host port belongs to the machine, so only one checkout at a time can publish it.', implode(', ', $ports)),
                \sprintf('Publish %s on another port in this checkout, or reach the service with castor <service>:expose <port>, which each checkout remembers on its own.', 1 === \count($ports) ? 'it' : 'them'),
            )
            : Check::ok('Host ports', 'No host port published: nothing is shared with the other checkouts.');

        return $checks;
    }

    private function checkDaemon(): Check
    {
        $docker = $this->docker();

        if (null === $docker['client']) {
            return Check::error('Docker', 'The docker command is not installed.', 'Install Docker: https://docs.docker.com/engine/install/');
        }

        if (null !== $docker['server']) {
            return Check::ok('Docker', 'Docker Engine ' . $docker['server'] . (null !== $docker['platform'] ? ', ' . $docker['platform'] : '') . '.');
        }

        $error = trim(explode("\n", trim((string) $docker['error']))[0]) ?: 'no answer';

        if (str_contains(strtolower($error), 'permission denied')) {
            return Check::error('Docker', 'The Docker daemon refuses this user: ' . $error, 'Join the docker group, then open a new session: sudo usermod -aG docker $USER');
        }

        return Check::error('Docker', 'The Docker daemon cannot be reached: ' . $error, match ($this->probe->platform()) {
            'wsl' => 'Start Docker Desktop and turn its integration with this distribution on (Settings › Resources › WSL integration), or start the daemon installed in the distribution: sudo service docker start',
            'darwin' => 'Start Docker Desktop, or the VM running your daemon (colima start).',
            'linux' => 'Start it: sudo systemctl start docker',
            default => 'Start Docker Desktop.',
        });
    }

    private function checkCompose(): Check
    {
        if (null === $this->docker()['client']) {
            return Check::skipped('Compose', 'Needs the docker command.');
        }

        $version = $this->composeVersion();
        $fix = 'Upgrade it: https://docs.docker.com/compose/install/';

        if (null === $version) {
            return Check::error('Compose', 'The compose plugin of docker is not installed.', 'Install it: https://docs.docker.com/compose/install/');
        }

        if (version_compare($version, self::COMPOSE_INCLUDE, '<')) {
            return Check::error('Compose', \sprintf('Compose %s is too old: the compose.yaml of the project includes the generated file, and "include:" needs Compose %s.', $version, self::COMPOSE_INCLUDE), $fix);
        }

        if (version_compare($version, self::COMPOSE_CONFIG_CONTENT, '<')) {
            return Check::error('Compose', \sprintf('Compose %s is too old: the router inlines its Caddyfile in its compose file, which needs Compose %s.', $version, self::COMPOSE_CONFIG_CONTENT), $fix);
        }

        return Check::ok('Compose', \sprintf('Docker Compose %s.', $version));
    }

    private function checkBuildx(): Check
    {
        if (null === $this->docker()['client']) {
            return Check::skipped('Buildx', 'Needs the docker command.');
        }

        $version = $this->probe->buildxVersion();
        $compose = $this->composeVersion();
        $buildsWithBuildx = null !== $compose && version_compare($compose, self::COMPOSE_BUILDS_WITH_BUILDX, '>=');
        $fix = 'Install the docker-buildx-plugin package of your distribution, or see https://github.com/docker/buildx#installing';

        if (null === $version) {
            return $buildsWithBuildx
                ? Check::error('Buildx', \sprintf('Buildx is not installed, and Compose %s builds through it: castor docker:build fails.', $compose), $fix)
                : Check::warning('Buildx', 'Buildx is not installed: castor docker:push builds with "docker buildx bake".', $fix);
        }

        if ($buildsWithBuildx && version_compare($version, self::BUILDX, '<')) {
            return Check::error('Buildx', \sprintf('Buildx %s is too old: Compose %s builds through Buildx %s or later, so castor docker:build fails.', $version, $compose, self::BUILDX), $fix);
        }

        // Only a project pushing its build cache cares: the "docker" driver
        // exports a cache to a registry with the containerd image store alone.
        $registry = $this->context->data['registry'] ?? null;

        if (\is_string($registry) && '' !== $registry && 'docker' === $this->probe->buildxDriver() && false === ($this->dockerInfo()['containerdStore'] ?? null)) {
            return Check::warning(
                'Buildx',
                \sprintf('Buildx %s, but its builder uses the "docker" driver, which cannot export the build cache castor docker:push pushes without the containerd image store.', $version),
                'Create a builder that can, once: docker buildx create --use --driver docker-container',
            );
        }

        return Check::ok('Buildx', \sprintf('Buildx %s.', $version));
    }

    private function checkDiskSpace(): Check
    {
        $info = $this->dockerInfo();

        if (null === $info) {
            return Check::skipped('Disk space', 'Needs the Docker daemon.');
        }

        // Docker Desktop, Colima, a remote host: not a disk of this machine.
        if (!$this->isLocalDaemon()) {
            return Check::skipped('Disk space', \sprintf('The daemon runs on "%s" (%s), whose disk cannot be measured from here.', $info['name'], $info['os']));
        }

        $free = $this->probe->freeDiskSpace($info['rootDir']);

        if (null === $free) {
            return Check::skipped('Disk space', \sprintf('Cannot measure the free space of %s.', $info['rootDir']));
        }

        $result = \sprintf('%s free in %s', format_bytes($free), $info['rootDir']);
        $fix = 'Free some: docker system prune removes the stopped containers, the unused networks and the dangling images, docker builder prune the build cache.';

        if ($free < self::DISK_ERROR) {
            return Check::error('Disk space', $result . ': builds, and containers writing to their own layer, fail with "no space left on device".', $fix);
        }

        if ($free < self::DISK_WARNING) {
            return Check::warning('Disk space', $result . ': a few builds are enough to fill it.', $fix);
        }

        return Check::ok('Disk space', $result . '.');
    }

    private function checkComposeFiles(): Check
    {
        if (null === $this->composeVersion()) {
            return Check::skipped('Compose files', 'Needs Docker Compose.');
        }

        $error = $this->probe->composeConfigError();

        if (null === $error) {
            return Check::ok('Compose files', 'Docker Compose accepts compose.yaml and the files it includes.');
        }

        return Check::error(
            'Compose files',
            'Docker Compose rejects the project: ' . $error,
            'Fix the file it names. compose.generated.yaml is rewritten from castor.php on every run: change castor.php, or override the service in compose.override.yaml.',
        );
    }

    /**
     * @return list<Check>
     */
    private function checkContainers(): array
    {
        if (!$this->isDaemonReachable()) {
            return [Check::skipped('Containers', 'Needs the Docker daemon.')];
        }

        $containers = $this->projectContainers();

        if (!$containers) {
            return [Check::ok('Containers', 'None created yet: castor docker:up starts them.')];
        }

        $checks = [];

        foreach ($containers as $container) {
            $service = $container['service'];
            $fix = 'See why: castor docker:logs ' . $service;

            if ('restarting' === $container['state']) {
                $checks[] = Check::error('Containers', \sprintf('%s keeps restarting (%s).', $service, $container['status']), $fix);
            } elseif (str_contains($container['status'], '(unhealthy)')) {
                $checks[] = Check::warning('Containers', \sprintf('%s is unhealthy.', $service), $fix);
            } elseif (
                'exited' === $container['state']
                && !$container['oneOff']
                && preg_match('{^Exited \((\d+)\)}', $container['status'], $matches)
                // Stopped by docker itself, or by a Ctrl+C: not a crash.
                && !\in_array((int) $matches[1], [0, 130, 137, 143], true)
            ) {
                $checks[] = Check::warning('Containers', \sprintf('%s exited with code %d.', $service, $matches[1]), $fix);
            }
        }

        if ($checks) {
            return $checks;
        }

        $running = \count(array_filter($containers, static fn(array $container): bool => 'running' === $container['state']));

        return [Check::ok('Containers', \sprintf('%d running, %d stopped.', $running, \count($containers) - $running))];
    }

    private function checkUser(): Check
    {
        $current = $this->probe->currentUserId();
        $userId = $this->userId();

        if (0 === $current) {
            return Check::warning('User', 'castor runs as root: so do the containers, and every file they write in the project belongs to root.', 'Run castor as your own user, without sudo.');
        }

        $owner = $this->probe->fileOwner($this->context->workingDirectory);

        if (null !== $owner && $owner !== $userId) {
            return Check::warning(
                'User',
                \sprintf('The containers run as uid %d, but the project belongs to uid %d: the files they write in it are not its owner\'s.', $userId, $owner),
                \sprintf('Run castor as the owner of the project, or set \'user_id\' => %d in the context.', $owner),
            );
        }

        return Check::ok('User', \sprintf('The containers run as uid %d, who owns the project.', $userId));
    }

    /**
     * @return list<Check>
     */
    private function checkMounts(): array
    {
        $userId = $this->userId();
        $root = Path::canonicalize($this->context->workingDirectory);
        $mounts = $this->probe->projectBindMounts();
        $checks = [];

        foreach ($mounts as $path) {
            $owner = $this->probe->fileOwner($path);

            // Missing: the next castor run creates it, as the right user.
            if (null === $owner) {
                continue;
            }

            $shown = Path::isBasePath($root, $path) && $path !== $root ? Path::makeRelative($path, $root) : $path;
            $fix = \sprintf('Take it back: sudo chown -R %s %s', $userId === $this->probe->currentUserId() ? '$(id -u):$(id -g)' : $userId, $shown);

            if (!$this->probe->isWritable($path)) {
                $checks[] = Check::error('Mounts', \sprintf('%s is not writable: the containers cannot write in it.', $shown), $fix);
            } elseif ($owner !== $userId && $path !== $root) {
                // The project directory itself is the "User" check's.
                $checks[] = Check::warning('Mounts', \sprintf('%s belongs to uid %d, the containers run as uid %d.', $shown, $owner, $userId), $fix);
            }
        }

        if ($checks) {
            return $checks;
        }

        return [Check::ok('Mounts', $mounts ? \sprintf('The %d directories the project mounts are writable.', \count($mounts)) : 'The project mounts no directory of its own.')];
    }

    /**
     * @param array{version: ?string, checksum: ?string, socket: ?string, networks: list<string>}|null $router
     */
    private function checkSocket(?array $router): Check
    {
        $socket = $this->probe->routerSocketPath();
        $endpoint = $this->endpointSocket();
        $fix = \sprintf('Give it the socket of the daemon your projects run on — export DOCKER_SOCKET_PATH=%s — then castor docker:router:enable', $endpoint ?? '<socket>');

        if (null !== $router && null !== $router['socket'] && $router['socket'] !== $socket) {
            return Check::warning(
                'Docker socket',
                \sprintf('The router watches %s, but castor would now give it %s.', $router['socket'], $socket),
                \sprintf('Recreate it on %s: castor docker:router:enable — or export DOCKER_SOCKET_PATH=%s if the one it watches is right.', $socket, $router['socket']),
            );
        }

        // The daemon resolves the bind mount on its own host: Docker Desktop
        // and Colima find their own socket in their VM, whatever this machine
        // has at that path.
        if (!$this->isLocalDaemon()) {
            return Check::ok('Docker socket', \sprintf('The router watches %s, which the daemon resolves on its own host.', $socket));
        }

        $real = $this->probe->realPath($socket);

        if (null === $real) {
            return Check::error('Docker socket', \sprintf('The router watches %s, which does not exist: it would see no container, and serve nothing.', $socket), $fix);
        }

        if (null !== $endpoint && $this->probe->realPath($endpoint) !== $real) {
            return Check::warning('Docker socket', \sprintf('The router watches %s, but docker talks to %s: the router sees the containers of another daemon.', $socket, $endpoint), $fix);
        }

        return Check::ok('Docker socket', \sprintf('The router watches %s, the daemon docker talks to.', $socket));
    }

    /**
     * @param ?string $service the service publishing the port, null for the ones of the router
     */
    private function checkPort(int $port, ?string $service): Check
    {
        $label = 'Port ' . $port;
        $consequence = null === $service ? 'the router cannot bind it' : \sprintf('%s cannot publish it', $service);
        $holder = $this->isDaemonReachable() ? $this->probe->portContainer($port) : null;

        if (null !== $holder) {
            if (null === $service && get_router_name() === $holder['name']) {
                return Check::ok($label, 'Held by the router.');
            }

            if (null !== $service && $holder['project'] === get_project_name($this->context)) {
                return Check::ok($label, \sprintf('Published by %s.', $holder['name']));
            }

            return Check::error(
                $label,
                \sprintf('Held by the container %s: %s.', null !== $holder['project'] && '' !== $holder['project'] ? \sprintf('%s, of the %s project', $holder['name'], $holder['project']) : $holder['name'], $consequence),
                \sprintf('Stop it: docker stop %s', $holder['name']) . (null !== $service ? \sprintf(' — or publish %s on another port.', $service) : ''),
            );
        }

        $listener = $this->probe->portListener($port);

        if (null === $listener) {
            return Check::ok($label, 'Free.');
        }

        $who = '' === $listener ? 'a process castor cannot name' : $listener;
        $fix = '' !== $listener ? \sprintf('Stop %s.', $listener) : \sprintf(
            'Find it with %s, and stop it.',
            'darwin' === $this->probe->platform() ? \sprintf('sudo lsof -nP -iTCP:%d -sTCP:LISTEN', $port) : \sprintf("sudo ss -ltnp 'sport = :%d'", $port),
        );

        // docker-proxy, Docker Desktop and rootlesskit hold container ports,
        // and without the daemon there is no telling this is not one.
        if (!$this->isDaemonReachable()) {
            return Check::warning($label, \sprintf('Held by %s, which may be a container: castor cannot tell without the Docker daemon.', $who), $fix);
        }

        return Check::error($label, \sprintf('Held by %s: %s.', $who, $consequence), $fix);
    }

    /**
     * @param array<string, list<string>> $resolved the addresses of each domain
     */
    private function checkResolution(string $label, array $resolved, string $hostsFile): Check
    {
        $unresolved = array_keys(array_filter($resolved, static fn(array $addresses): bool => [] === $addresses));
        $elsewhere = array_filter($resolved, static fn(array $addresses): bool => [] !== $addresses && [] === array_filter($addresses, self::isLoopback(...)));

        if (!$unresolved && !$elsewhere) {
            return Check::ok($label, 1 === \count($resolved) ? \sprintf('%s resolves to this machine.', array_key_first($resolved)) : \sprintf('The %d domains resolve to this machine.', \count($resolved)));
        }

        $lines = [];

        if ($unresolved) {
            $lines[] = \sprintf('%s: no such host.', implode(', ', $unresolved));
        }

        foreach ($elsewhere as $domain => $addresses) {
            $lines[] = \sprintf('%s resolves to %s, not to this machine where the router listens.', $domain, implode(', ', $addresses));
        }

        $fix = \sprintf('Add this line to %s: 127.0.0.1 %s', $hostsFile, implode(' ', [...$unresolved, ...array_keys($elsewhere)]));

        return $unresolved ? Check::error($label, implode("\n", $lines), $fix) : Check::warning($label, implode("\n", $lines), $fix);
    }

    private static function isLoopback(string $address): bool
    {
        return str_starts_with($address, '127.') || '::1' === $address || str_starts_with($address, '::ffff:127.');
    }

    /**
     * @return list<string>
     */
    private function projectDomains(): array
    {
        $domains = [];

        foreach ($this->projectUrls() as $urls) {
            foreach ($urls as $url) {
                $domain = (string) preg_replace('#^https?://#', '', $url);

                // A wildcard names no host to look up.
                if (!str_starts_with($domain, '*.')) {
                    $domains[$domain] = true;
                }
            }
        }

        return array_keys($domains);
    }

    private function userId(): int
    {
        $userId = $this->context->data['user_id'] ?? null;

        return \is_int($userId) ? $userId : $this->probe->currentUserId();
    }

    private function isProjectRunning(): bool
    {
        foreach ($this->projectContainers() as $container) {
            if ('running' === $container['state']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the paths the daemon binds are the ones castor sees.
     */
    private function isLocalDaemon(): bool
    {
        $info = $this->dockerInfo();

        return null !== $info && $info['name'] === $this->probe->hostname();
    }

    /**
     * Null when the docker CLI talks to something that is not a unix socket.
     */
    private function endpointSocket(): ?string
    {
        $endpoint = $this->probe->dockerEndpoint();

        return null !== $endpoint && str_starts_with($endpoint, 'unix://') ? substr($endpoint, \strlen('unix://')) : null;
    }

    private function isDaemonReachable(): bool
    {
        return null !== $this->docker()['server'];
    }

    /**
     * @return array{client: ?string, server: ?string, platform: ?string, error: ?string}
     */
    private function docker(): array
    {
        return $this->docker ??= $this->probe->docker();
    }

    /**
     * @return array{name: string, os: string, rootDir: string, containerdStore: bool}|null
     */
    private function dockerInfo(): ?array
    {
        if (false === $this->dockerInfo) {
            $this->dockerInfo = $this->isDaemonReachable() ? $this->probe->dockerInfo() : null;
        }

        return $this->dockerInfo;
    }

    private function composeVersion(): ?string
    {
        if (false === $this->composeVersion) {
            $this->composeVersion = null !== $this->docker()['client'] ? $this->probe->composeVersion() : null;
        }

        return $this->composeVersion;
    }

    /**
     * @return array{version: ?string, checksum: ?string, socket: ?string, networks: list<string>}|null
     */
    private function router(): ?array
    {
        if (false === $this->router) {
            $this->router = $this->isDaemonReachable() ? $this->probe->router() : null;
        }

        return $this->router;
    }

    /**
     * @return array<string, list<string>>
     */
    private function projectUrls(): array
    {
        return $this->projectUrls ??= $this->probe->projectUrls();
    }

    /**
     * @return list<array{id: string, service: string, oneOff: bool, state: string, status: string, size: ?int, image: string}>
     */
    private function projectContainers(): array
    {
        return $this->projectContainers ??= $this->isDaemonReachable() ? $this->probe->projectContainers() : [];
    }
}
