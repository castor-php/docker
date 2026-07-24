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

    public function testEndReturnsComposeBuilder(): void
    {
        $compose = new ComposeBuilder();

        static::assertSame($compose, $compose->service('app')->end());
        static::assertSame($compose, $compose->service('app')->build('/ctx')->end()->end());
    }
}
