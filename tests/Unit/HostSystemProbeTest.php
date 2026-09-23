<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use Castor\Docker\Doctor\HostSystemProbe;
use PHPUnit\Framework\TestCase;

/**
 * What "docker:doctor" reads out of the tools it asks, as they print it.
 */
final class HostSystemProbeTest extends TestCase
{
    public function testVersionsAreReadHoweverTheToolsSpellThem(): void
    {
        static::assertSame('2.39.1', HostSystemProbe::parseVersion("v2.39.1\n"));
        static::assertSame('2.20.2', HostSystemProbe::parseVersion('2.20.2-desktop.1'));
        static::assertSame('0.17.1', HostSystemProbe::parseVersion('v0.17.1-desktop.1'));
        static::assertNull(HostSystemProbe::parseVersion(''));
    }

    public function testSsNamesTheProcessItMaySee(): void
    {
        static::assertSame('nginx (pid 812)', HostSystemProbe::parseSsListener(
            'LISTEN 0      511          0.0.0.0:80        0.0.0.0:*    users:(("nginx",pid=812,fd=6),("nginx",pid=811,fd=6))' . "\n",
        ));
    }

    /**
     * A process of another user is listed, but not named, unless ss runs as
     * root.
     */
    public function testSsListsTheProcessesOfOtherUsersWithoutTheirName(): void
    {
        static::assertSame('', HostSystemProbe::parseSsListener("LISTEN 0      4096   *:80  *:*\n"));
    }

    public function testNothingListening(): void
    {
        static::assertNull(HostSystemProbe::parseSsListener(''));
        static::assertNull(HostSystemProbe::parseLsofListener(''));
    }

    public function testLsofNamesTheProcess(): void
    {
        static::assertSame('httpd (pid 4242)', HostSystemProbe::parseLsofListener("p4242\nchttpd\nf5\n"));
    }

    /**
     * getent lists every address once per socket type.
     */
    public function testGetentAddressesAreListedOnce(): void
    {
        static::assertSame(['::1', '127.0.0.1'], HostSystemProbe::parseGetentAddresses(<<<'TXT'
            ::1             STREAM app.myproject.test
            ::1             DGRAM
            ::1             RAW
            127.0.0.1       STREAM
            127.0.0.1       DGRAM
            127.0.0.1       RAW
            TXT));
    }
}
