<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use Castor\Context;
use PHPUnit\Framework\TestCase;

use function Castor\Docker\assert_compose_generation_enabled;
use function Castor\Docker\is_compose_generation_enabled;

final class ComposeGenerationTest extends TestCase
{
    public function testItIsOnByDefault(): void
    {
        static::assertTrue(is_compose_generation_enabled(new Context([], workingDirectory: __DIR__)));
    }

    public function testTheContextTurnsItOff(): void
    {
        static::assertFalse(is_compose_generation_enabled(new Context(['generate_compose_files' => false], workingDirectory: __DIR__)));
    }

    public function testATaskNeedingTheFilesStopsWhenItIsOff(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"generate_compose_files" is false');

        assert_compose_generation_enabled(new Context(['generate_compose_files' => false], workingDirectory: __DIR__));
    }

    public function testATaskNeedingTheFilesRunsByDefault(): void
    {
        $this->expectNotToPerformAssertions();

        assert_compose_generation_enabled(new Context([], workingDirectory: __DIR__));
    }
}
