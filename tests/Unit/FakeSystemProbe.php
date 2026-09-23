<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use Castor\Docker\Doctor\SystemProbe;

use function Castor\Docker\get_router_certs_directory;
use function Castor\Docker\get_router_checksum;
use function Castor\Docker\get_router_name;

/**
 * A machine on which everything works, for "docker:doctor" to examine: each
 * test breaks the one thing it is about.
 */
final class FakeSystemProbe implements SystemProbe
{
    public string $platform = 'linux';
    public string $hostname = 'laptop';

    /** @var array{client: ?string, server: ?string, platform: ?string, error: ?string} */
    public array $docker = ['client' => '28.5.1', 'server' => '28.5.1', 'platform' => 'Docker Engine - Community', 'error' => null];

    /** @var array{name: string, os: string, rootDir: string, containerdStore: bool}|null */
    public ?array $dockerInfo = ['name' => 'laptop', 'os' => 'Debian GNU/Linux 13 (trixie)', 'rootDir' => '/var/lib/docker', 'containerdStore' => false];

    public ?string $dockerEndpoint = 'unix:///var/run/docker.sock';
    public ?string $composeVersion = '2.40.3';
    public ?string $buildxVersion = '0.29.1';
    public ?string $buildxDriver = 'docker';
    public ?int $freeDiskSpace = 120_000_000_000;
    public string $routerSocketPath = '/var/run/docker.sock';

    /** @var array{version: ?string, checksum: ?string, socket: ?string, networks: list<string>}|null */
    public ?array $router;

    public bool $routerAutostart = true;

    /** @var array<int, array{name: string, project: ?string}> */
    public array $portContainers;

    /** @var array<int, string> */
    public array $portListeners = [];

    public ?string $mkcertCaRoot = '/home/me/.local/share/mkcert';
    public ?bool $trustedBySystem = true;
    public ?bool $trustedByWindows = true;

    /** @var array<string, list<string>> */
    public array $resolved = [];

    /** @var array<string, list<string>>|null */
    public ?array $resolvedOnWindows = [];

    public int $currentUserId = 1000;

    /** @var array<string, int> */
    public array $owners = ['/project' => 1000, '/project/.home' => 1000];

    /** @var list<string> */
    public array $unwritable = [];

    /** @var array<string, string> */
    public array $hashes;

    /** @var array<string, string> */
    public array $realPaths = ['/var/run/docker.sock' => '/run/docker.sock', '/run/docker.sock' => '/run/docker.sock'];

    /** @var array<string, list<string>> */
    public array $projectUrls = ['app' => ['https://app.myproject.test', 'https://myproject.test', 'http://app.myproject.test']];

    /** @var array<string, list<string>> */
    public array $projectPublishedPorts = [];

    /** @var list<string> */
    public array $projectBindMounts = ['/project', '/project/.home'];

    /** @var list<array{id: string, service: string, oneOff: bool, state: string, status: string, size: ?int, image: string}> */
    public array $projectContainers = [];

    public ?string $composeConfigError = null;

    /** @var list<string> the domains looked up, in order */
    public array $lookups = [];

    public function __construct()
    {
        $this->router = ['version' => '0.8.0', 'checksum' => get_router_checksum(), 'socket' => '/var/run/docker.sock', 'networks' => [get_router_name() . '_default', 'myproject_default']];
        $this->portContainers = [
            80 => ['name' => get_router_name(), 'project' => get_router_name()],
            443 => ['name' => get_router_name(), 'project' => get_router_name()],
        ];
        $this->hashes = [
            '/home/me/.local/share/mkcert/rootCA.pem' => 'mkcert-ca',
            get_router_certs_directory() . '/rootCA.pem' => 'mkcert-ca',
            get_router_certs_directory() . '/caddy/ca.caddy' => 'pki',
        ];
        $this->projectContainers = [$this->container('app', 'running', 'Up 5 minutes')];
    }

    /**
     * @return array{id: string, service: string, oneOff: bool, state: string, status: string, size: ?int, image: string}
     */
    public function container(string $service, string $state, string $status, bool $oneOff = false): array
    {
        return ['id' => md5($service), 'service' => $service, 'oneOff' => $oneOff, 'state' => $state, 'status' => $status, 'size' => null, 'image' => 'myproject-' . $service];
    }

    public function platform(): string
    {
        return $this->platform;
    }

    public function hostname(): string
    {
        return $this->hostname;
    }

    public function docker(): array
    {
        return $this->docker;
    }

    public function dockerInfo(): ?array
    {
        return $this->dockerInfo;
    }

    public function dockerEndpoint(): ?string
    {
        return $this->dockerEndpoint;
    }

    public function composeVersion(): ?string
    {
        return $this->composeVersion;
    }

    public function buildxVersion(): ?string
    {
        return $this->buildxVersion;
    }

    public function buildxDriver(): ?string
    {
        return $this->buildxDriver;
    }

    public function freeDiskSpace(string $path): ?int
    {
        return $this->freeDiskSpace;
    }

    public function routerSocketPath(): string
    {
        return $this->routerSocketPath;
    }

    public function router(): ?array
    {
        return $this->router;
    }

    public function routerAutostart(): bool
    {
        return $this->routerAutostart;
    }

    public function portContainer(int $port): ?array
    {
        return $this->portContainers[$port] ?? null;
    }

    public function portListener(int $port): ?string
    {
        return $this->portListeners[$port] ?? null;
    }

    public function mkcertCaRoot(): ?string
    {
        return $this->mkcertCaRoot;
    }

    public function isTrustedBySystem(string $certificate): ?bool
    {
        return $this->trustedBySystem;
    }

    public function isTrustedByWindows(string $certificate): ?bool
    {
        return $this->trustedByWindows;
    }

    public function resolve(string $domain): array
    {
        $this->lookups[] = $domain;

        return $this->resolved[$domain] ?? ['127.0.0.1'];
    }

    public function resolveOnWindows(array $domains): ?array
    {
        if (null === $this->resolvedOnWindows) {
            return null;
        }

        $resolved = [];

        foreach ($domains as $domain) {
            $resolved[$domain] = $this->resolvedOnWindows[$domain] ?? ['127.0.0.1'];
        }

        return $resolved;
    }

    public function currentUserId(): int
    {
        return $this->currentUserId;
    }

    public function fileOwner(string $path): ?int
    {
        return $this->owners[$path] ?? null;
    }

    public function isWritable(string $path): bool
    {
        return !\in_array($path, $this->unwritable, true);
    }

    public function fileHash(string $path): ?string
    {
        return $this->hashes[$path] ?? null;
    }

    public function realPath(string $path): ?string
    {
        return $this->realPaths[$path] ?? null;
    }

    public function projectUrls(): array
    {
        return $this->projectUrls;
    }

    public function projectPublishedPorts(): array
    {
        return $this->projectPublishedPorts;
    }

    public function projectBindMounts(): array
    {
        return $this->projectBindMounts;
    }

    public function projectContainers(): array
    {
        return $this->projectContainers;
    }

    public function composeConfigError(): ?string
    {
        return $this->composeConfigError;
    }
}
