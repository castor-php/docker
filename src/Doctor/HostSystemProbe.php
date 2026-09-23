<?php

declare(strict_types=1);

namespace Castor\Docker\Doctor;

use Castor\Context;
use Symfony\Component\Process\ExecutableFinder;

use function Castor\Docker\docker_compose;
use function Castor\Docker\get_docker_socket_path;
use function Castor\Docker\get_project_bind_mounts;
use function Castor\Docker\get_project_containers;
use function Castor\Docker\get_project_published_ports;
use function Castor\Docker\get_project_urls;
use function Castor\Docker\get_running_router;
use function Castor\Docker\is_router_autostart_enabled;
use function Castor\run;

/**
 * The machine castor runs on, as "docker:doctor" sees it.
 *
 * Every command runs with a timeout and without failing: a question the machine
 * cannot answer is an answer too — no daemon, no mkcert, no Windows behind WSL.
 */
final class HostSystemProbe implements SystemProbe
{
    /** Where WSL finds the Windows commands when the PATH of Windows is not appended to its own. */
    private const WINDOWS_DIRECTORIES = ['/mnt/c/Windows/System32', '/mnt/c/Windows/System32/WindowsPowerShell/v1.0'];

    public function __construct(
        private readonly Context $context,
    ) {}

    public function platform(): string
    {
        return match (\PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Windows' => 'windows',
            default => isset($_SERVER['WSL_DISTRO_NAME']) || str_contains(strtolower((string) @file_get_contents('/proc/sys/kernel/osrelease')), 'microsoft') ? 'wsl' : 'linux',
        };
    }

    public function hostname(): string
    {
        return (string) gethostname();
    }

    public function docker(): array
    {
        if (null === $this->find('docker')) {
            return ['client' => null, 'server' => null, 'platform' => null, 'error' => null];
        }

        [, $output, $error] = $this->exec(['docker', 'version', '--format', '{{json .}}']);
        $version = json_decode(trim(explode("\n", trim($output))[0]), true);
        $version = \is_array($version) ? $version : [];

        $server = $version['Server']['Version'] ?? null;
        $platform = $version['Server']['Platform']['Name'] ?? null;

        return [
            'client' => \is_string($version['Client']['Version'] ?? null) ? $version['Client']['Version'] : 'unknown',
            'server' => \is_string($server) && '' !== $server ? $server : null,
            'platform' => \is_string($platform) && '' !== $platform ? $platform : null,
            'error' => \is_string($server) ? null : trim($error),
        ];
    }

    public function dockerInfo(): ?array
    {
        [$code, $output] = $this->exec(['docker', 'info', '--format', '{{.Name}}{{"\t"}}{{.OperatingSystem}}{{"\t"}}{{.DockerRootDir}}{{"\t"}}{{json .DriverStatus}}']);
        $fields = explode("\t", trim($output));

        if (0 !== $code || 4 !== \count($fields)) {
            return null;
        }

        return [
            'name' => $fields[0],
            'os' => $fields[1],
            'rootDir' => $fields[2],
            // The containerd image store reports its snapshotter as the driver type.
            'containerdStore' => str_contains($fields[3], 'io.containerd.snapshotter'),
        ];
    }

    public function dockerEndpoint(): ?string
    {
        [$code, $output] = $this->exec(['docker', 'context', 'inspect', '--format', '{{.Endpoints.docker.Host}}']);

        return 0 === $code && '' !== trim($output) ? trim($output) : null;
    }

    public function composeVersion(): ?string
    {
        [$code, $output] = $this->exec(['docker', 'compose', 'version', '--short']);

        return 0 === $code ? self::parseVersion($output) : null;
    }

    public function buildxVersion(): ?string
    {
        [$code, $output] = $this->exec(['docker', 'buildx', 'version']);

        // "github.com/docker/buildx v0.17.1 <commit>"
        return 0 === $code ? self::parseVersion(explode(' ', trim($output))[1] ?? '') : null;
    }

    public function buildxDriver(): ?string
    {
        [$code, $output] = $this->exec(['docker', 'buildx', 'inspect']);

        return 0 === $code && preg_match('{^Driver:\s+(\S+)}m', $output, $matches) ? $matches[1] : null;
    }

    public function freeDiskSpace(string $path): ?int
    {
        $free = @disk_free_space($path);

        return false === $free ? null : (int) $free;
    }

    public function routerSocketPath(): string
    {
        return get_docker_socket_path();
    }

    public function router(): ?array
    {
        return get_running_router();
    }

    public function routerAutostart(): bool
    {
        return is_router_autostart_enabled($this->context);
    }

    public function portContainer(int $port): ?array
    {
        [, $output] = $this->exec(['docker', 'ps', '--filter', 'publish=' . $port, '--format', '{{.Names}}{{"\t"}}{{.Label "com.docker.compose.project"}}']);
        $line = trim(explode("\n", trim($output))[0]);

        if ('' === $line) {
            return null;
        }

        [$name, $project] = array_pad(explode("\t", $line, 2), 2, '');

        return ['name' => $name, 'project' => '' === $project ? null : $project];
    }

    public function portListener(int $port): ?string
    {
        if (null !== $this->find('ss')) {
            [$code, $output] = $this->exec(['ss', '-Hltnp', \sprintf('( sport = :%d )', $port)]);

            if (0 === $code) {
                return self::parseSsListener($output);
            }
        }

        if (null !== $this->find('lsof')) {
            [, $output] = $this->exec(['lsof', '-nP', '-iTCP:' . $port, '-sTCP:LISTEN', '-Fpc']);

            if (null !== ($listener = self::parseLsofListener($output))) {
                return $listener;
            }
        }

        // Neither can name it, or lsof only sees the processes of this user:
        // knocking on the port still tells whether something is behind it.
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);

        if (false === $socket) {
            return null;
        }

        fclose($socket);

        return '';
    }

    public function mkcertCaRoot(): ?string
    {
        if (null === $this->find('mkcert')) {
            return null;
        }

        [$code, $output] = $this->exec(['mkcert', '-CAROOT']);

        return 0 === $code && '' !== trim($output) ? trim($output) : null;
    }

    public function isTrustedBySystem(string $certificate): ?bool
    {
        if (!is_file($certificate)) {
            return null;
        }

        if ('darwin' === $this->platform()) {
            [$code] = $this->exec(['security', 'verify-cert', '-c', $certificate]);

            return 0 === $code;
        }

        if (!\function_exists('openssl_x509_checkpurpose')) {
            return null;
        }

        // A static PHP knows the store it was built against, which may not be
        // where this distribution keeps its own.
        $locations = openssl_get_cert_locations();
        $stores = array_values(array_filter(
            array_unique([
                $locations['default_cert_file'] ?? '',
                $locations['default_cert_dir'] ?? '',
                '/etc/ssl/certs/ca-certificates.crt',
                '/etc/pki/tls/certs/ca-bundle.crt',
                '/etc/ssl/cert.pem',
                '/etc/ssl/certs',
            ]),
            static fn(string $store): bool => '' !== $store && file_exists($store),
        ));

        if (!$stores || false === ($pem = file_get_contents($certificate))) {
            return null;
        }

        $trusted = @openssl_x509_checkpurpose($pem, \X509_PURPOSE_ANY, $stores);

        return \is_bool($trusted) ? $trusted : null;
    }

    public function isTrustedByWindows(string $certificate): ?bool
    {
        $certutil = $this->find('certutil.exe', self::WINDOWS_DIRECTORIES);

        if (null === $certutil || !\function_exists('openssl_x509_fingerprint') || false === ($pem = @file_get_contents($certificate))) {
            return null;
        }

        $fingerprint = @openssl_x509_fingerprint($pem, 'sha1');

        if (false === $fingerprint) {
            return null;
        }

        // certutil answers in the language of Windows, and succeeds whether it
        // found the certificate or not: only its hash in the output tells.
        foreach ([['-user', '-store', 'Root'], ['-store', 'Root']] as $store) {
            [, $output] = $this->exec([$certutil, ...$store, $fingerprint]);

            if (str_contains(strtolower((string) preg_replace('/[\s:]/', '', $output)), strtolower($fingerprint))) {
                return true;
            }
        }

        return false;
    }

    public function resolve(string $domain): array
    {
        // getent goes through the resolver of the system — /etc/hosts, mDNS,
        // DNS — IPv6 included, which gethostbynamel() does not.
        if (null !== $this->find('getent')) {
            [$code, $output] = $this->exec(['getent', 'ahosts', $domain], 5);

            return 0 === $code ? self::parseGetentAddresses($output) : [];
        }

        return array_values(array_unique(gethostbynamel($domain) ?: []));
    }

    public function resolveOnWindows(array $domains): ?array
    {
        $powershell = $this->find('powershell.exe', self::WINDOWS_DIRECTORIES);

        if (null === $powershell) {
            return null;
        }

        // The domains come out of get_project_urls(), which only lets host
        // names through: they are safe between single quotes.
        $script = \sprintf(
            'foreach ($d in @(%s)) { try { $a = ([System.Net.Dns]::GetHostAddresses($d) | ForEach-Object { $_.IPAddressToString }) -join "," } catch { $a = "" }; Write-Output ($d + "|" + $a) }',
            implode(',', array_map(static fn(string $domain): string => "'" . $domain . "'", $domains)),
        );

        [$code, $output] = $this->exec([$powershell, '-NoProfile', '-NonInteractive', '-Command', $script], 20);

        if (0 !== $code) {
            return null;
        }

        $resolved = [];

        foreach (explode("\n", trim($output)) as $line) {
            [$domain, $addresses] = array_pad(explode('|', trim($line), 2), 2, '');

            if ('' !== $domain) {
                $resolved[$domain] = array_values(array_filter(explode(',', $addresses)));
            }
        }

        return $resolved;
    }

    public function currentUserId(): int
    {
        return \function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid();
    }

    public function fileOwner(string $path): ?int
    {
        clearstatcache(true, $path);

        if (!file_exists($path)) {
            return null;
        }

        $owner = @fileowner($path);

        return false === $owner ? null : $owner;
    }

    public function isWritable(string $path): bool
    {
        return is_writable($path);
    }

    public function fileHash(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $hash = @hash_file('sha256', $path);

        return false === $hash ? null : $hash;
    }

    public function realPath(string $path): ?string
    {
        $real = realpath($path);

        return false === $real ? null : $real;
    }

    public function projectUrls(): array
    {
        return get_project_urls($this->context);
    }

    public function projectPublishedPorts(): array
    {
        return get_project_published_ports($this->context);
    }

    public function projectBindMounts(): array
    {
        return get_project_bind_mounts($this->context);
    }

    public function projectContainers(): array
    {
        return get_project_containers(c: $this->context);
    }

    public function composeConfigError(): ?string
    {
        try {
            $process = docker_compose(['config', '--quiet'], c: $this->context->withQuiet()->withAllowFailure()->withPty(false));
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        if ($process->isSuccessful()) {
            return null;
        }

        $error = trim(explode("\n", trim($process->getErrorOutput() ?: $process->getOutput()))[0]);

        // Compose names the files by their absolute path.
        return str_replace($this->context->workingDirectory . '/', '', $error) ?: 'no reason given';
    }

    /**
     * The "x.y.z" of a version as the docker tools print it — "v2.39.1",
     * "2.20.2-desktop.1", "v0.17.1".
     */
    public static function parseVersion(string $version): ?string
    {
        return preg_match('{(\d+\.\d+(?:\.\d+)?)}', $version, $matches) ? $matches[1] : null;
    }

    /**
     * The process "ss -Hltnp" says listens: "nginx (pid 812)", an empty string
     * when it may not tell — the process belongs to another user — and null
     * when nothing listens.
     */
    public static function parseSsListener(string $output): ?string
    {
        $output = trim($output);

        if ('' === $output) {
            return null;
        }

        if (preg_match('{users:\(\("(?<name>[^"]+)",pid=(?<pid>\d+)}', $output, $matches)) {
            return \sprintf('%s (pid %s)', $matches['name'], $matches['pid']);
        }

        return '';
    }

    /**
     * The process "lsof -Fpc" names, null when it names none.
     */
    public static function parseLsofListener(string $output): ?string
    {
        if (!preg_match('{^p(?<pid>\d+)$}m', $output, $pid) || !preg_match('{^c(?<name>.+)$}m', $output, $name)) {
            return null;
        }

        return \sprintf('%s (pid %s)', trim($name['name']), $pid['pid']);
    }

    /**
     * The addresses of "getent ahosts", which lists each one once per socket
     * type.
     *
     * @return list<string>
     */
    public static function parseGetentAddresses(string $output): array
    {
        $addresses = [];

        foreach (explode("\n", trim($output)) as $line) {
            $address = preg_split('/\s+/', trim($line))[0] ?? '';

            if ('' !== $address) {
                $addresses[$address] = true;
            }
        }

        return array_keys($addresses);
    }

    /**
     * @param list<string> $directories
     */
    private function find(string $command, array $directories = []): ?string
    {
        return (new ExecutableFinder())->find($command, null, $directories);
    }

    /**
     * Run a command, and never fail: a missing binary, a daemon that does not
     * answer or a timeout all come back as a failed exit code.
     *
     * @param list<string> $command
     *
     * @return array{int, string, string} the exit code, the output and the error output
     */
    private function exec(array $command, float $timeout = 10): array
    {
        try {
            // No pseudo-terminal, or the error output would end up in the output.
            $process = run($command, context: $this->context->withQuiet()->withAllowFailure()->withPty(false)->withTimeout($timeout));
        } catch (\Throwable $e) {
            return [1, '', $e->getMessage()];
        }

        return [$process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput()];
    }
}
