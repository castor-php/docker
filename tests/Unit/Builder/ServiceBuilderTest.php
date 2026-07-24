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
        self::assertSame([], $this->service()->toArray());
    }

    public function testVolumeWithAndWithoutMode(): void
    {
        $service = $this->service()
            ->volume('/host', '/container')
            ->volume('data', '/var/lib/data', 'cached')
        ;

        self::assertSame(['/host:/container', 'data:/var/lib/data:cached'], $service->toArray()['volumes']);
    }

    public function testUserWithGroup(): void
    {
        self::assertSame('1000:1000', $this->service()->user('1000', '1000')->toArray()['user']);
        self::assertSame('www-data', $this->service()->user('www-data')->toArray()['user']);
    }

    public function testBuildIsReused(): void
    {
        $service = $this->service();

        $build = $service->build('/context');
        $again = $service->build();

        self::assertSame($build, $again);
        self::assertSame(['context' => '/context'], $service->toArray()['build']);
    }

    public function testBuildFromAnotherBuilderIsCloned(): void
    {
        $compose = new ComposeBuilder();

        $original = $compose->service('app')->build('/context');
        $original->target('frontend')->arg('php_version', '8.4');

        $clone = $compose->service('app-builder')->build($original);
        $clone->target('builder');

        self::assertNotSame($original, $clone);
        self::assertSame('frontend', $compose->service('app')->toArray()['build']['target'], 'Mutating the clone must not affect the original build.');
        self::assertSame('builder', $compose->service('app-builder')->toArray()['build']['target']);
        self::assertSame(['php_version' => '8.4'], $compose->service('app-builder')->toArray()['build']['args'], 'Clone must inherit args from the original build.');
    }

    public function testTraefikRoutingHttpsOnly(): void
    {
        $labels = $this->service()
            ->withTraefikRouting('demo-app', 'app.demo.test', 80)
            ->toArray()['labels']
        ;

        self::assertSame([
            'traefik.enable=true',
            'traefik.http.routers.demo-app.rule=Host(`app.demo.test`)',
            'traefik.http.routers.demo-app.tls=true',
            'traefik.http.services.demo-app.loadbalancer.server.port=80',
            'traefik.http.routers.demo-app-unsecure.rule=Host(`app.demo.test`)',
            'traefik.http.routers.demo-app-unsecure.entrypoints=http',
            'traefik.http.routers.demo-app-unsecure.middlewares=redirect-to-https@file',
        ], $labels);
    }

    public function testTraefikRoutingMultipleDomains(): void
    {
        $labels = $this->service()
            ->withTraefikRouting('demo-app', ['app.demo.test', 'demo.test'])
            ->toArray()['labels']
        ;

        self::assertContains('traefik.http.routers.demo-app.rule=Host(`app.demo.test`) || Host(`demo.test`)', $labels);
    }

    public function testTraefikRoutingWithHttpAccess(): void
    {
        $labels = $this->service()
            ->withTraefikRouting('demo-app', 'app.demo.test', allowHttpAccess: true)
            ->toArray()['labels']
        ;

        self::assertContains('traefik.http.routers.demo-app.entrypoints=http,https', $labels);
        self::assertNotContains('traefik.http.routers.demo-app-unsecure.middlewares=redirect-to-https@file', $labels);
    }

    public function testEndReturnsComposeBuilder(): void
    {
        $compose = new ComposeBuilder();

        self::assertSame($compose, $compose->service('app')->end());
        self::assertSame($compose, $compose->service('app')->build('/ctx')->end()->end());
    }
}
