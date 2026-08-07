<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use Castor\Console\Output\VerbosityLevel;
use Castor\Context;
use PHPUnit\Framework\TestCase;

use function Castor\Docker\get_compose_progress;

/**
 * Every "docker compose run" announces the throwaway container it creates —
 * "Container app-builder-run-8c9d8bef Creating", then "Created" — in front of
 * the output of the command actually asked for.
 */
final class ComposeProgressTest extends TestCase
{
    private function context(VerbosityLevel $level): Context
    {
        return (new Context())->withVerbosityLevel($level);
    }

    public function testTheAnnouncementsAreSilencedAtNormalVerbosity(): void
    {
        static::assertSame('quiet', get_compose_progress($this->context(VerbosityLevel::NORMAL)));
    }

    public function testTheyStaySilencedBelowNormal(): void
    {
        static::assertSame('quiet', get_compose_progress($this->context(VerbosityLevel::QUIET)));
        static::assertSame('quiet', get_compose_progress($this->context(VerbosityLevel::SILENT)));
        static::assertSame('quiet', get_compose_progress($this->context(VerbosityLevel::NOT_CONFIGURED)));
    }

    /**
     * Asking for more output is asking for this too: at that point knowing
     * which container compose created is the point.
     */
    public function testTheyComeBackWhenMoreOutputIsAskedFor(): void
    {
        static::assertNull(get_compose_progress($this->context(VerbosityLevel::VERBOSE)));
        static::assertNull(get_compose_progress($this->context(VerbosityLevel::VERY_VERBOSE)));
        static::assertNull(get_compose_progress($this->context(VerbosityLevel::DEBUG)));
    }
}
