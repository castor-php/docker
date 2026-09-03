<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Castor\Docker\format_bytes;
use function Castor\Docker\parse_docker_labels;
use function Castor\Docker\parse_docker_percent;
use function Castor\Docker\parse_docker_size;

/**
 * "docker:stats" adds up numbers the docker CLI only gives as human strings —
 * "1.26GB" of image, "254.1MiB" of memory — so reading them back has to be
 * exact, and has to say so when a field holds no number at all.
 */
final class DockerSizeTest extends TestCase
{
    /**
     * Disk sizes come in SI units, memory in binary ones, and both scales show
     * up in a single stats table.
     */
    public function testBothUnitScalesAreRead(): void
    {
        static::assertSame(0, parse_docker_size('0B'));
        static::assertSame(49200, parse_docker_size('49.2kB'));
        static::assertSame(1260000000, parse_docker_size('1.26GB'));
        static::assertSame(266443162, parse_docker_size('254.1MiB'));
        static::assertSame(1024, parse_docker_size('1KiB'));
        static::assertSame(126, parse_docker_size('126'));
    }

    public function testAFieldHoldingNoSizeIsNotZero(): void
    {
        static::assertNull(parse_docker_size(''));
        static::assertNull(parse_docker_size('N/A'));
        static::assertNull(parse_docker_size('--'));
        static::assertNull(parse_docker_size('12 apples'));
    }

    public function testPercentages(): void
    {
        static::assertSame(99.27, parse_docker_percent('99.27%'));
        static::assertSame(0.0, parse_docker_percent('0.00%'));
        static::assertNull(parse_docker_percent('--'));
        static::assertNull(parse_docker_percent('99.27'));
    }

    /**
     * The totals are printed next to sizes docker printed itself, so they are
     * rendered the same way it renders them.
     */
    public function testBytesAreRenderedLikeDockerRendersThem(): void
    {
        static::assertSame('0B', format_bytes(0));
        static::assertSame('999B', format_bytes(999));
        static::assertSame('1kB', format_bytes(1000));
        static::assertSame('49.2kB', format_bytes(49200));
        static::assertSame('157.1MB', format_bytes(157100000));
        static::assertSame('1.26GB', format_bytes(1260000000));
    }

    public function testRoundTrippingASizeKeepsIt(): void
    {
        foreach (['0B', '999B', '49.2kB', '157.1MB', '1.26GB', '2.5TB'] as $size) {
            static::assertSame($size, format_bytes((int) parse_docker_size($size)));
        }
    }

    public function testLabelsAreParsed(): void
    {
        static::assertSame(
            ['com.docker.compose.project' => 'rio', 'com.docker.compose.volume' => 'db-data'],
            parse_docker_labels('com.docker.compose.project=rio,com.docker.compose.volume=db-data'),
        );
        static::assertSame([], parse_docker_labels(''));
    }
}
