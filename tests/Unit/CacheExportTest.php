<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Castor\Docker\drop_unsupported_cache_export;

/**
 * On the "docker" driver without the containerd image store, a cache_to fails
 * the whole build: the GitHub Actions one added on CI broke every project
 * building with the default builder of the runners. Its GitHub Actions
 * cache_from logs an "unknown cache importer" error on every build.
 */
final class CacheExportTest extends TestCase
{
    private const COMPOSE = ['services' => ['app' => ['build' => [
        'cache_from' => ['type=registry,ref=/app:cache', 'type=gha,scope=app'],
        'cache_to' => ['type=gha,scope=app,mode=max,ignore-error=true'],
    ]]]];

    public function testTheDockerDriverOnlyKeepsTheRegistryCache(): void
    {
        $probe = new FakeSystemProbe();

        static::assertSame(
            ['services' => ['app' => ['build' => ['cache_from' => ['type=registry,ref=/app:cache']]]]],
            drop_unsupported_cache_export(self::COMPOSE, $probe),
        );
    }

    /**
     * With the export turned off, nothing is written but the import error would
     * still be logged.
     */
    public function testTheDockerDriverDropsAGitHubActionsCacheItOnlyReads(): void
    {
        $compose = ['services' => ['app' => ['build' => [
            'cache_from' => ['type=registry,ref=/app:cache', 'type=gha,scope=app'],
        ]]]];

        static::assertSame(
            ['services' => ['app' => ['build' => ['cache_from' => ['type=registry,ref=/app:cache']]]]],
            drop_unsupported_cache_export($compose, new FakeSystemProbe()),
        );
    }

    public function testAServiceLeftWithoutCacheHasNoCacheFrom(): void
    {
        $compose = ['services' => ['app' => ['build' => [
            'cache_from' => ['type=gha,scope=app'],
            'cache_to' => ['type=gha,scope=app,mode=max,ignore-error=true'],
        ]]]];

        static::assertSame(['services' => ['app' => ['build' => []]]], drop_unsupported_cache_export($compose, new FakeSystemProbe()));
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
