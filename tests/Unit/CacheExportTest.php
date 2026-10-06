<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Castor\Docker\drop_unsupported_cache_export;

/**
 * On the "docker" driver without the containerd image store, a cache_to fails
 * the whole build: the GitHub Actions one added on CI broke every project
 * building with the default builder of the runners.
 */
final class CacheExportTest extends TestCase
{
    private const COMPOSE = ['services' => ['app' => ['build' => [
        'cache_from' => ['type=gha,scope=app'],
        'cache_to' => ['type=gha,scope=app,mode=max,ignore-error=true'],
    ]]]];

    public function testTheDockerDriverDropsTheCacheExport(): void
    {
        $probe = new FakeSystemProbe();

        static::assertSame(
            ['services' => ['app' => ['build' => ['cache_from' => ['type=gha,scope=app']]]]],
            drop_unsupported_cache_export(self::COMPOSE, $probe),
        );
    }

    public function testTheDockerDriverOnTheContainerdStoreKeepsIt(): void
    {
        $probe = new FakeSystemProbe();
        $probe->dockerInfo = ['containerdStore' => true] + (array) $probe->dockerInfo;

        static::assertSame(self::COMPOSE, drop_unsupported_cache_export(self::COMPOSE, $probe));
    }

    public function testAContainerBuilderKeepsIt(): void
    {
        $probe = new FakeSystemProbe();
        $probe->buildxDriver = 'docker-container';

        static::assertSame(self::COMPOSE, drop_unsupported_cache_export(self::COMPOSE, $probe));
    }

    public function testWithoutBuildxItIsDropped(): void
    {
        $probe = new FakeSystemProbe();
        $probe->buildxDriver = null;

        static::assertArrayNotHasKey('cache_to', drop_unsupported_cache_export(self::COMPOSE, $probe)['services']['app']['build']);
    }
}
