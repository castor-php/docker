<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use Castor\Context;
use PHPUnit\Framework\TestCase;

use function Castor\Docker\drop_cache_export;
use function Castor\Docker\is_build_cache_export_enabled;

/**
 * Exporting a build cache can cost more than the build itself: a project may
 * only write it from its default branch, its pull requests reading it.
 */
final class BuildCacheExportTest extends TestCase
{
    private const VARIABLE = 'CASTOR_DOCKER_BUILD_CACHE_EXPORT';

    private mixed $backup = null;

    protected function setUp(): void
    {
        $this->backup = $_SERVER[self::VARIABLE] ?? null;

        unset($_SERVER[self::VARIABLE]);
    }

    protected function tearDown(): void
    {
        if (null === $this->backup) {
            unset($_SERVER[self::VARIABLE]);

            return;
        }

        $_SERVER[self::VARIABLE] = $this->backup;
    }

    private function context(?bool $export): Context
    {
        /** @var array{build_cache_export?: bool} $data */
        $data = null === $export ? [] : ['build_cache_export' => $export];

        return new Context($data);
    }

    public function testItIsOnByDefault(): void
    {
        static::assertTrue(is_build_cache_export_enabled($this->context(null)));
    }

    public function testTheContextTurnsItOff(): void
    {
        static::assertFalse(is_build_cache_export_enabled($this->context(false)));
        static::assertTrue(is_build_cache_export_enabled($this->context(true)));
    }

    /**
     * What a workflow sets from the branch it runs on.
     */
    public function testTheEnvironmentVariableWinsOverTheContext(): void
    {
        $_SERVER[self::VARIABLE] = 'false';
        static::assertFalse(is_build_cache_export_enabled($this->context(true)));

        $_SERVER[self::VARIABLE] = 'true';
        static::assertTrue(is_build_cache_export_enabled($this->context(false)));
    }

    public function testAnUnreadableValueFallsBackToTheContext(): void
    {
        $_SERVER[self::VARIABLE] = 'maybe';

        static::assertFalse(is_build_cache_export_enabled($this->context(false)));
    }

    public function testOffTheBuildsStillReadTheCaches(): void
    {
        $compose = ['services' => ['app' => ['build' => [
            'cache_from' => ['type=registry,ref=/app:cache', 'type=gha,scope=app'],
            'cache_to' => ['type=gha,scope=app,mode=max,ignore-error=true'],
        ]], 'db' => ['image' => 'mariadb']]];

        static::assertSame(
            ['services' => ['app' => ['build' => ['cache_from' => ['type=registry,ref=/app:cache', 'type=gha,scope=app']]], 'db' => ['image' => 'mariadb']]],
            drop_cache_export($compose),
        );
    }
}
