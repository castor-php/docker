<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Docker\Service\Builder\ComposeBuilder;
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

    public function testDebugIsOffByDefault(): void
    {
        $configuration = $this->configuration(new RedirectionioAgentService());

        static::assertArrayNotHasKey('log', $configuration);
    }

    public function testDebugRaisesTheLogLevel(): void
    {
        $configuration = $this->configuration((new RedirectionioAgentService())->withDebug());

        static::assertSame(['level' => 'debug'], $configuration['log']);
        static::assertArrayNotHasKey('api', $configuration, 'No API host, nothing to relax.');
    }

    /**
     * A self-hosted API served by the local router presents a certificate the
     * agent image cannot verify: it carries the public bundle only, and runs on
     * "scratch" so there is nowhere to add one.
     */
    public function testDebugAcceptsAnUnverifiableApiCertificate(): void
    {
        $configuration = $this->configuration(
            (new RedirectionioAgentService())
                ->withApiHost('https://api.demo.test')
                ->withApiTimeout(120)
                ->withDebug()
        );

        static::assertSame(
            ['host' => 'https://api.demo.test', 'timeout' => 120, 'insecure' => true],
            $configuration['api'],
        );
    }

    public function testTheApiCertificateIsVerifiedWithoutDebug(): void
    {
        $configuration = $this->configuration(
            (new RedirectionioAgentService())->withApiHost('https://api.demo.test')
        );

        static::assertSame(['host' => 'https://api.demo.test'], $configuration['api']);
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
}
