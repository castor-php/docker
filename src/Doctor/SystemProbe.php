<?php

declare(strict_types=1);

namespace Castor\Docker\Doctor;

/**
 * Everything "docker:doctor" asks the machine, and nothing it concludes.
 *
 * The Doctor only ever learns facts through this interface, so a test can hand
 * it a machine in any state — no daemon, an old Compose, a port taken by nginx
 * — without having one.
 */
interface SystemProbe
{
    /**
     * "linux", "wsl", "darwin" or "windows".
     */
    public function platform(): string;

    public function hostname(): string;

    /**
     * The docker CLI and the daemon it reaches. "client" is null when there is
     * no docker command, "server" is null when the daemon cannot be reached —
     * "error" then holds what the CLI said.
     *
     * @return array{client: ?string, server: ?string, platform: ?string, error: ?string}
     */
    public function docker(): array;

    /**
     * What the daemon says about itself, null when it cannot be reached.
     *
     * @return array{name: string, os: string, rootDir: string, containerdStore: bool}|null
     */
    public function dockerInfo(): ?array;

    /**
     * The endpoint the docker CLI talks to, as its current context names it —
     * "unix:///var/run/docker.sock".
     */
    public function dockerEndpoint(): ?string;

    public function composeVersion(): ?string;

    public function buildxVersion(): ?string;

    /**
     * The driver of the current buildx builder — "docker", "docker-container".
     */
    public function buildxDriver(): ?string;

    /**
     * The free space of the filesystem holding a path, in bytes, null when it
     * cannot be measured.
     */
    public function freeDiskSpace(string $path): ?int;

    /**
     * The socket the router is told to watch (see get_docker_socket_path()).
     */
    public function routerSocketPath(): string;

    /**
     * The router as it runs, null when it does not (see get_running_router()).
     *
     * @return array{version: ?string, checksum: ?string, socket: ?string, networks: list<string>}|null
     */
    public function router(): ?array;

    public function routerAutostart(): bool;

    /**
     * The container publishing a host port, null when none does.
     *
     * @return array{name: string, project: ?string}|null
     */
    public function portContainer(int $port): ?array;

    /**
     * The host process listening on a port — "nginx (pid 812)" — an empty
     * string when something listens but cannot be named, null when nothing
     * does.
     */
    public function portListener(int $port): ?string;

    /**
     * The CA directory of mkcert, null when mkcert is not installed.
     */
    public function mkcertCaRoot(): ?string;

    /**
     * Whether the trust store of the system holds a certificate, null when this
     * system cannot be asked.
     */
    public function isTrustedBySystem(string $certificate): ?bool;

    /**
     * Whether Windows trusts a certificate, from WSL; null when Windows cannot
     * be asked.
     */
    public function isTrustedByWindows(string $certificate): ?bool;

    /**
     * The addresses a host name resolves to on this machine.
     *
     * @return list<string>
     */
    public function resolve(string $domain): array;

    /**
     * The addresses host names resolve to on Windows, from WSL; null when
     * Windows cannot be asked.
     *
     * @param list<string> $domains
     *
     * @return array<string, list<string>>|null
     */
    public function resolveOnWindows(array $domains): ?array;

    public function currentUserId(): int;

    /**
     * The owner of a file, null when it does not exist.
     */
    public function fileOwner(string $path): ?int;

    public function isWritable(string $path): bool;

    /**
     * A hash of the content of a file, null when it does not exist.
     */
    public function fileHash(string $path): ?string;

    /**
     * The path a symbolic link leads to, null when it leads nowhere.
     */
    public function realPath(string $path): ?string;

    /**
     * @return array<string, list<string>> see get_project_urls()
     */
    public function projectUrls(): array;

    /**
     * @return array<string, list<string>> see get_project_published_ports()
     */
    public function projectPublishedPorts(): array;

    /**
     * @return list<string> see get_project_bind_mounts()
     */
    public function projectBindMounts(): array;

    /**
     * @return list<array{id: string, service: string, oneOff: bool, state: string, status: string, size: ?int, image: string}> see get_project_containers()
     */
    public function projectContainers(): array;

    /**
     * What "docker compose config" rejects in the compose files of the project,
     * null when it accepts them.
     */
    public function composeConfigError(): ?string;
}
