<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Castor\Docker\get_docker_socket_path;

/**
 * The router builds its routes from the "caddy.*" labels it reads on the Docker
 * socket, so it has to watch the socket of the daemon the projects run on.
 *
 * /var/run/docker.sock is not always that one — a CI job installing a daemon of
 * its own, a rootless daemon and Colima all put theirs elsewhere — and watching
 * the wrong one is silent: the router comes up, sees no label and serves
 * nothing, which reads as "connection refused" on 443 from the caller's side.
 */
final class RouterDockerSocketTest extends TestCase
{
    private const VARIABLES = ['DOCKER_SOCKET_PATH', 'DOCKER_HOST'];

    /** @var array<string, mixed> */
    private array $backup = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->backup[$name] = $_SERVER[$name] ?? null;
            unset($_SERVER[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->backup as $name => $value) {
            if (null === $value) {
                unset($_SERVER[$name]);

                continue;
            }

            $_SERVER[$name] = $value;
        }
    }

    public function testTheDefaultIsTheUsualSocket(): void
    {
        static::assertSame('/var/run/docker.sock', get_docker_socket_path());
    }

    /**
     * What a CI job installing its own daemon exports.
     */
    public function testDockerSocketPathWins(): void
    {
        $_SERVER['DOCKER_SOCKET_PATH'] = '/opt/hostedtoolcache/docker/docker.sock';

        static::assertSame('/opt/hostedtoolcache/docker/docker.sock', get_docker_socket_path());
    }

    /**
     * What the Docker CLI itself reads, and what a rootless daemon sets.
     */
    public function testAUnixDockerHostIsUsed(): void
    {
        $_SERVER['DOCKER_HOST'] = 'unix:///run/user/1000/docker.sock';

        static::assertSame('/run/user/1000/docker.sock', get_docker_socket_path());
    }

    public function testDockerSocketPathWinsOverDockerHost(): void
    {
        $_SERVER['DOCKER_SOCKET_PATH'] = '/tmp/explicit.sock';
        $_SERVER['DOCKER_HOST'] = 'unix:///run/user/1000/docker.sock';

        static::assertSame('/tmp/explicit.sock', get_docker_socket_path());
    }

    /**
     * A daemon reached over TCP has no socket to bind-mount: the default is the
     * only thing left to try, and router:enable warns when it is not there.
     */
    public function testATcpDockerHostFallsBackToTheDefault(): void
    {
        $_SERVER['DOCKER_HOST'] = 'tcp://127.0.0.1:2375';

        static::assertSame('/var/run/docker.sock', get_docker_socket_path());
    }

    public function testAnEmptySocketPathIsIgnored(): void
    {
        $_SERVER['DOCKER_SOCKET_PATH'] = '';

        static::assertSame('/var/run/docker.sock', get_docker_socket_path());
    }
}
