<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use Castor\Context;
use PHPUnit\Framework\TestCase;

use function Castor\Docker\get_terminal_size_environment;

/**
 * The pty castor runs docker behind is born 0x0, so a container gets a tty
 * whose size says nothing and every console command in it falls back to 80
 * columns.
 */
final class TerminalSizeTest extends TestCase
{
    public function testTheSizeIsHandedToTheContainer(): void
    {
        static::assertSame(
            ['COLUMNS' => '203', 'LINES' => '51'],
            get_terminal_size_environment(new Context(), [203, 51]),
        );
    }

    /**
     * A real terminal is passed through as it is, and docker keeps its size in
     * sync — including the resizes a fixed COLUMNS would hide.
     */
    public function testATerminalPassedThroughIsLeftAlone(): void
    {
        static::assertSame([], get_terminal_size_environment((new Context())->withTty(), [203, 51]));
    }

    /**
     * Piped output has no width to speak of, and 80 columns is then as good an
     * answer as any.
     */
    public function testNothingIsHandedOverWithoutATerminal(): void
    {
        if (stream_isatty(\STDOUT)) {
            static::markTestSkipped('The size is read from the terminal this test suite is itself writing to.');
        }

        static::assertSame([], get_terminal_size_environment(new Context(), null));
    }
}
