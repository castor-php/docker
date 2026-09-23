<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use Castor\Context;
use PHPUnit\Framework\TestCase;

use function Castor\Docker\get_tunnel_command;
use function Castor\Docker\get_tunnel_domains;
use function Castor\Docker\parse_tunnel_url;

/**
 * "docker:tunnel:start" opens one cloudflared quick tunnel per domain of the
 * project, each going through the global router the way a browser would.
 */
final class TunnelTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'compose');
        \assert($directory !== false);
        unlink($directory);
        mkdir($directory);

        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    private function write(string $file, string $content): void
    {
        file_put_contents($this->directory . '/' . $file, $content);
    }

    private function context(): Context
    {
        return new Context(workingDirectory: $this->directory);
    }

    /**
     * Every routed domain gets a tunnel, once: the plain HTTP site
     * withHttpAccess() adds is the same domain, and a tunnel always reaches the
     * router over HTTPS.
     */
    public function testEveryDomainIsTunnelledOnce(): void
    {
        $this->write('compose.generated.yaml', <<<'YAML'
            services:
                app:
                    labels:
                        - 'caddy=app.project.test project.test'
                        - 'caddy_1=http://app.project.test http://project.test'
                adminer:
                    labels:
                        - caddy=adminer.project.test
            YAML);
        $this->write('compose.override.yaml', <<<'YAML'
            services:
                mailpit:
                    labels:
                        caddy: mail.project.test
            YAML);

        static::assertSame([
            'adminer.project.test' => 'adminer',
            'app.project.test' => 'app',
            'project.test' => 'app',
            'mail.project.test' => 'mailpit',
        ], get_tunnel_domains($this->context()));
    }

    /**
     * A wildcard is a family of names: a tunnel has to rewrite the Host to a
     * single one.
     */
    public function testAWildcardIsNotTunnelled(): void
    {
        $this->write('compose.generated.yaml', "services:\n    app:\n        labels:\n            - 'caddy=app.project.test *.project.test'\n");

        static::assertSame(['app.project.test' => 'app'], get_tunnel_domains($this->context()));
    }

    public function testAProjectRoutingNothingHasNothingToTunnel(): void
    {
        static::assertSame([], get_tunnel_domains($this->context()));
    }

    /**
     * The tunnel reaches the router on its own network, over HTTPS, and hands
     * it the local domain both as the Host it routes on and as the name it mints
     * its certificate for.
     */
    public function testTheTunnelGoesThroughTheRouter(): void
    {
        $command = get_tunnel_command('myproject', 'app.project.test');

        static::assertSame(['docker', 'run', '--detach'], \array_slice($command, 0, 3));
        static::assertContains('myproject-tunnel-app.project.test', $command);
        static::assertSame('castor-docker-router_default', $command[array_search('--network', $command, true) + 1]);
        static::assertSame('https://castor-docker-router:443', $command[array_search('--url', $command, true) + 1]);
        static::assertSame('app.project.test', $command[array_search('--http-host-header', $command, true) + 1]);
        static::assertSame('app.project.test', $command[array_search('--origin-server-name', $command, true) + 1]);
        static::assertContains('--no-tls-verify', $command);
    }

    /**
     * Not the compose labels: "docker:up" removes the orphans of the project,
     * and a quick tunnel never comes back on the same URL.
     */
    public function testTheTunnelIsNoComposeOrphan(): void
    {
        $command = implode(' ', get_tunnel_command('myproject', 'app.project.test'));

        static::assertStringNotContainsString('com.docker.compose', $command);
        static::assertStringContainsString('castor.tunnel.project=myproject', $command);
        static::assertStringContainsString('castor.tunnel.domain=app.project.test', $command);
    }

    public function testTheUrlIsReadFromTheBanner(): void
    {
        $logs = <<<'LOGS'
            2026-09-23T10:00:00Z INF Thank you for trying Cloudflare Tunnel. [...] (https://www.cloudflare.com/website-terms/) [...] https://developers.cloudflare.com/cloudflare-one/connections/connect-apps
            2026-09-23T10:00:00Z INF Requesting new quick Tunnel on trycloudflare.com...
            2026-09-23T10:00:01Z INF +--------------------------------------------------------------------------------------------+
            2026-09-23T10:00:01Z INF |  Your quick Tunnel has been created! Visit it at (it may take some time to be reachable):  |
            2026-09-23T10:00:01Z INF |  https://calm-river-sample-words.trycloudflare.com                                         |
            2026-09-23T10:00:01Z INF +--------------------------------------------------------------------------------------------+
            LOGS;

        static::assertSame('https://calm-river-sample-words.trycloudflare.com', parse_tunnel_url($logs));
    }

    /**
     * Where the URL is asked for shows up in the logs when asking fails, and is
     * no URL of the tunnel.
     */
    public function testTheApiIsNoTunnelUrl(): void
    {
        $logs = 'failed to request quick Tunnel: Post "https://api.trycloudflare.com/tunnel": dial tcp: lookup api.trycloudflare.com: no such host';

        static::assertNull(parse_tunnel_url($logs));
    }

    public function testTheLastUrlWins(): void
    {
        $logs = "INF |  https://first-sample-words.trycloudflare.com  |\nINF |  https://second-sample-words.trycloudflare.com  |\n";

        static::assertSame('https://second-sample-words.trycloudflare.com', parse_tunnel_url($logs));
    }
}
