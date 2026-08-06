<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Builder;

use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\Builder\ServiceBuilder;
use PHPUnit\Framework\TestCase;

final class ServiceBuilderTest extends TestCase
{
    private function service(string $name = 'app'): ServiceBuilder
    {
        return (new ComposeBuilder())->service($name);
    }

    public function testEmptyServiceProducesNoKeys(): void
    {
        static::assertSame([], $this->service()->toArray());
    }

    public function testVolumeWithAndWithoutMode(): void
    {
        $service = $this->service()
            ->volume('/host', '/container')
            ->volume('data', '/var/lib/data', 'cached')
        ;

        static::assertSame(['/host:/container', 'data:/var/lib/data:cached'], $service->toArray()['volumes']);
    }

    public function testUserWithGroup(): void
    {
        static::assertSame('1000:1000', $this->service()->user('1000', '1000')->toArray()['user']);
        static::assertSame('www-data', $this->service()->user('www-data')->toArray()['user']);
    }

    public function testBuildIsReused(): void
    {
        $service = $this->service();

        $build = $service->build('/context');
        $again = $service->build();

        static::assertSame($build, $again);
        static::assertSame(['context' => '/context'], $service->toArray()['build']);
    }

    public function testBuildFromAnotherBuilderIsCloned(): void
    {
        $compose = new ComposeBuilder();

        $original = $compose->service('app')->build('/context');
        $original->target('frontend')->arg('php_version', '8.4');

        $clone = $compose->service('app-builder')->build($original);
        $clone->target('builder');

        static::assertNotSame($original, $clone);
        static::assertSame('frontend', $compose->service('app')->toArray()['build']['target'], 'Mutating the clone must not affect the original build.');
        static::assertSame('builder', $compose->service('app-builder')->toArray()['build']['target']);
        static::assertSame(['php_version' => '8.4'], $compose->service('app-builder')->toArray()['build']['args'], 'Clone must inherit args from the original build.');
    }

    public function testHttpRoutingHttpsOnly(): void
    {
        $labels = $this->service()
            ->withHttpRouting('app.demo.test', 80)
            ->toArray()['labels']
        ;

        static::assertSame([
            'caddy=app.demo.test',
            'caddy.reverse_proxy={{upstreams 80}}',
            'caddy.tls=internal',
            'caddy.tls.on_demand=',
        ], $labels);
    }

    public function testHttpRoutingMultipleDomains(): void
    {
        $labels = $this->service()
            ->withHttpRouting(['app.demo.test', 'demo.test'])
            ->toArray()['labels']
        ;

        static::assertContains('caddy=app.demo.test demo.test', $labels);
        static::assertContains('caddy.reverse_proxy={{upstreams}}', $labels);
    }

    public function testHttpRoutingWithHttpAccess(): void
    {
        $labels = $this->service()
            ->withHttpRouting('app.demo.test', 80, allowHttpAccess: true)
            ->toArray()['labels']
        ;

        static::assertContains('caddy_1=http://app.demo.test', $labels);
        static::assertContains('caddy_1.reverse_proxy={{upstreams 80}}', $labels);
    }

    /**
     * "KEY: null" is the compose syntax passing the variable through from the
     * environment castor runs in, so a value that changes between invocations
     * stays out of the generated file.
     */
    public function testEnvironmentWithoutValueIsPassedThrough(): void
    {
        $environment = $this->service()
            ->environment('APP_ENV', 'dev')
            ->environment('GIT_WORKTREE')
            ->toArray()['environment']
        ;

        static::assertSame(['APP_ENV' => 'dev', 'GIT_WORKTREE' => null], $environment);
    }

    public function testRestartPolicy(): void
    {
        static::assertSame('on-failure:10', $this->service()->restart('on-failure:10')->toArray()['restart']);
    }

    public function testUlimits(): void
    {
        $ulimits = $this->service()
            ->ulimits('nofile', ['soft' => 262144, 'hard' => 262144])
            ->ulimits('nproc', 65535)
            ->toArray()['ulimits']
        ;

        static::assertSame([
            'nofile' => ['soft' => 262144, 'hard' => 262144],
            'nproc' => 65535,
        ], $ulimits);
    }

    public function testDnsIsVariadicAndDeduplicated(): void
    {
        $dns = $this->service()
            ->dns('1.1.1.1', '8.8.8.8')
            ->dns('1.1.1.1')
            ->toArray()['dns']
        ;

        static::assertSame(['1.1.1.1', '8.8.8.8'], $dns);
    }

    public function testExtraHostsAreDeduplicated(): void
    {
        $extraHosts = $this->service()
            ->extraHost('host.docker.internal', 'host-gateway')
            ->extraHost('api.demo.test', 'host-gateway')
            ->extraHost('api.demo.test', 'host-gateway')
            ->toArray()['extra_hosts']
        ;

        static::assertSame(['host.docker.internal:host-gateway', 'api.demo.test:host-gateway'], $extraHosts);
    }

    public function testDeployIsMergedRecursively(): void
    {
        $deploy = $this->service()
            ->deploy(['resources' => ['limits' => ['memory' => '1g']]])
            ->deploy(['resources' => ['reservations' => ['devices' => [['capabilities' => ['gpu']]]]]])
            ->toArray()['deploy']
        ;

        static::assertSame([
            'resources' => [
                'limits' => ['memory' => '1g'],
                'reservations' => ['devices' => [['capabilities' => ['gpu']]]],
            ],
        ], $deploy);
    }

    /**
     * The generator needs the domains back to make them resolvable from inside
     * the containers, and the labels are not a practical source.
     */
    public function testRoutedDomainsAreRemembered(): void
    {
        $service = $this->service()
            ->withHttpRouting(['app.demo.test', 'demo.test'])
            ->withHttpRouting('app.demo.test')
        ;

        static::assertSame(['app.demo.test', 'demo.test'], $service->getRoutedDomains());
    }

    public function testServiceWithoutRoutingHasNoDomain(): void
    {
        static::assertSame([], $this->service()->getRoutedDomains());
    }

    /**
     * Compose does not recreate a container when only the content of an inline
     * config changed, so a server reading its configuration at boot would keep
     * running with the old one. The digest puts the change in the container
     * definition, where compose does look.
     */
    public function testConfigChecksumLabelTracksTheContent(): void
    {
        $compose = new ComposeBuilder();
        $compose->config('agent', 'first');
        $compose->service('app')->config('agent', '/etc/agent.yml', recreateOnChange: true);

        $labels = $compose->service('app')->toArray()['labels'];
        static::assertCount(1, $labels);
        static::assertStringStartsWith('castor.config.agent=', $labels[0]);

        $compose->config('agent', 'second');

        static::assertNotSame(
            $labels[0],
            $compose->service('app')->toArray()['labels'][0],
            'A different content must produce a different digest.',
        );
    }

    public function testConfigWithoutRecreateOnChangeEmitsNoLabel(): void
    {
        $compose = new ComposeBuilder();
        $compose->config('agent', 'content');
        $compose->service('app')->config('agent', '/etc/agent.yml');

        static::assertArrayNotHasKey('labels', $compose->service('app')->toArray());
    }

    public function testEndReturnsComposeBuilder(): void
    {
        $compose = new ComposeBuilder();

        static::assertSame($compose, $compose->service('app')->end());
        static::assertSame($compose, $compose->service('app')->build('/ctx')->end()->end());
    }
}
