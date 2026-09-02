<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\NodeService;
use Castor\Docker\Service\RedirectionioAgentService;
use Castor\Docker\Tests\SnapshotTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The generated agent.yml, parsed back — the snapshot freezes its text, this
 * checks what it means.
 */
final class RedirectionioAgentTest extends SnapshotTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function configuration(RedirectionioAgentService $agent): array
    {
        $compose = $agent->updateCompose($this->fixedContext(), new ComposeBuilder())->toArray();
        $parsed = Yaml::parse($compose['configs'][$agent->getName()]['content']);

        \assert(\is_array($parsed));

        return $parsed;
    }

    /**
     * The agent derives the forwarded Host from the forward address: an IP
     * keeps the original, a host name replaces it. Every target here is a
     * compose service reached by name, so the application would receive
     * "Host: app" — which Symfony rejects as an untrusted host, and which makes
     * every absolute URL it generates wrong.
     */
    public function testTheOriginalHostIsForwardedByDefault(): void
    {
        $configuration = $this->configuration(
            (new RedirectionioAgentService())->addReverseProxy('app.demo.test', 'app')
        );

        static::assertTrue($configuration['reverse_proxy']['virtual_hosts'][0]['forward']['preserve_host']);
    }

    /**
     * A service listening somewhere else than on 80 — a Node dev server, a Rust
     * binary — used to need the port repeated here, and forwarding to 80
     * answered nothing at all. A service *name* still defaults to 80: it
     * carries nothing to read.
     */
    public function testThePortIsReadFromTheTargetService(): void
    {
        $configuration = $this->configuration(
            (new RedirectionioAgentService())
                ->addReverseProxy('front.demo.test', (new NodeService('front'))->withPort(5173))
                ->addReverseProxy('legacy.demo.test', 'legacy')
        );

        static::assertSame('front:5173', $configuration['reverse_proxy']['virtual_hosts'][0]['forward']['address']);
        static::assertSame('legacy:80', $configuration['reverse_proxy']['virtual_hosts'][1]['forward']['address']);
    }

    public function testAnExplicitPortStillWins(): void
    {
        $configuration = $this->configuration(
            (new RedirectionioAgentService())
                ->addReverseProxy('front.demo.test', (new NodeService('front'))->withPort(5173), port: 8080)
        );

        static::assertSame('front:8080', $configuration['reverse_proxy']['virtual_hosts'][0]['forward']['address']);
    }

    public function testPreserveHostCanBeTurnedOffGlobally(): void
    {
        $configuration = $this->configuration(
            (new RedirectionioAgentService())
                ->withPreserveHost(false)
                ->addReverseProxy('app.demo.test', 'app')
        );

        static::assertFalse($configuration['reverse_proxy']['virtual_hosts'][0]['forward']['preserve_host']);
    }

    public function testPreserveHostCanBeOverriddenPerDomain(): void
    {
        $configuration = $this->configuration(
            (new RedirectionioAgentService())
                ->addReverseProxy('app.demo.test', 'app')
                ->addReverseProxy('legacy.demo.test', 'legacy', preserveHost: false)
        );

        $hosts = $configuration['reverse_proxy']['virtual_hosts'];

        static::assertTrue($hosts[0]['forward']['preserve_host']);
        static::assertFalse($hosts[1]['forward']['preserve_host']);
    }

    /**
     * Debug is a flag of the agent binary, not something in agent.yml: the
     * default command of the image is overridden to add it.
     */
    public function testDebugIsOffByDefault(): void
    {
        $compose = (new RedirectionioAgentService())
            ->updateCompose($this->fixedContext(), new ComposeBuilder())
            ->toArray()
        ;

        // No command at all: the one baked into the image is what runs.
        static::assertArrayNotHasKey('command', $compose['services']['redirectionio-agent']);
    }

    public function testDebugAddsTheFlagToTheAgentCommand(): void
    {
        $compose = (new RedirectionioAgentService())
            ->withDebug()
            ->updateCompose($this->fixedContext(), new ComposeBuilder())
            ->toArray()
        ;

        static::assertSame(
            ['/usr/local/bin/redirectionio-agent', '--config', '/etc/redirectionio/agent.yml', '--debug'],
            $compose['services']['redirectionio-agent']['command'],
        );
    }

    /**
     * agent.yml describes the reverse proxy, not how the agent is run: the
     * debug flag must leave it alone.
     */
    public function testDebugDoesNotTouchTheConfiguration(): void
    {
        $agent = (new RedirectionioAgentService())->withApiHost('https://api.demo.test')->withApiTimeout(120);

        $without = $this->configuration(clone $agent);
        $with = $this->configuration($agent->withDebug());

        static::assertSame($without, $with);
        static::assertSame(['host' => 'https://api.demo.test', 'timeout' => 120], $with['api']);
    }

    /**
     * The agent reads agent.yml once, on boot, so compose has to be told that
     * the container is stale when the configuration changes.
     */
    public function testTheConfigurationIsDigestedIntoALabel(): void
    {
        $compose = (new RedirectionioAgentService())
            ->addReverseProxy('app.demo.test', 'app')
            ->updateCompose($this->fixedContext(), new ComposeBuilder())
            ->toArray()
        ;

        static::assertNotEmpty(array_filter(
            $compose['services']['redirectionio-agent']['labels'],
            static fn(string $label): bool => str_starts_with($label, 'castor.config.redirectionio-agent='),
        ));
    }

    public function testTestModeAndLogging(): void
    {
        $agent = $this->configuration((new RedirectionioAgentService())->withLogging(false)->withTestMode());

        static::assertSame($agent['instance']['test_mode'], true);
        static::assertSame($agent['instance']['logging'], false);
    }
}
