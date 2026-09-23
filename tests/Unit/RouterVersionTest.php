<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Castor\Docker\compare_plugin_versions;
use function Castor\Docker\compare_router_configuration;
use function Castor\Docker\get_plugin_version;
use function Castor\Docker\get_router_checksum;
use function Castor\Docker\get_router_compose;
use function Castor\Docker\parse_router_labels;

/**
 * The router is global: every project on the machine starts it, writes its
 * compose file and relies on its configuration — each with the version of the
 * plugin it has installed. The newest configuration has to win, and an older
 * project must neither take it back nor ask for a restart it does not need.
 */
final class RouterVersionTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string, int}>
     */
    public static function provideVersions(): iterable
    {
        yield 'two releases' => ['0.8.0', '0.7.1', 1];
        yield 'the same release' => ['0.7.1', '0.7.1', 0];
        yield 'a v prefix' => ['v0.8.0', '0.7.1', 1];
        yield 'not alphabetically' => ['0.10.0', '0.9.0', 1];
        yield 'a branch is ahead of a release' => ['dev-main', '1.2.0', 1];
        yield 'an aliased branch too' => ['0.8.x-dev', '0.7.1', 1];
        yield 'two branches cannot be ordered' => ['dev-main', 'dev-feat/tunnel', 0];
        yield 'no version is older than anything' => [null, '0.1.0', -1];
    }

    #[DataProvider('provideVersions')]
    public function testVersionsAreOrdered(?string $a, ?string $b, int $expected): void
    {
        static::assertSame($expected, compare_plugin_versions($a, $b) <=> 0);
        static::assertSame(-$expected, compare_plugin_versions($b, $a) <=> 0);
    }

    /**
     * An upgrade of the plugin that ships the same router is no reason to
     * interrupt every project it serves.
     */
    public function testTheSameConfigurationIsUpToDateWhateverTheVersions(): void
    {
        static::assertSame(0, compare_router_configuration('0.7.1', 'abc', '0.9.0', 'abc'));
        static::assertSame(0, compare_router_configuration('0.9.0', 'abc', '0.7.1', 'abc'));
    }

    public function testAnOlderConfigurationGivesWay(): void
    {
        static::assertSame(-1, compare_router_configuration('0.7.1', 'old', '0.8.0', 'new'));
    }

    /**
     * A project lagging behind must not take the router back.
     */
    public function testANewerConfigurationIsKept(): void
    {
        static::assertSame(1, compare_router_configuration('0.8.0', 'new', '0.7.1', 'old'));
    }

    /**
     * Created before the plugin labelled its router: anything is newer.
     */
    public function testAnUnlabelledRouterGivesWay(): void
    {
        static::assertSame(-1, compare_router_configuration(null, null, '0.8.0', 'new'));
    }

    /**
     * A developer of the plugin switching branches expects to see theirs.
     */
    public function testBetweenTwoBranchesTheProjectWins(): void
    {
        static::assertSame(-1, compare_router_configuration('dev-main', 'main', 'dev-feat/tunnel', 'tunnel'));
    }

    public function testTheRouterCarriesItsVersionAndChecksum(): void
    {
        $labels = parse_router_labels(get_router_compose()['services']['router']['labels']);

        static::assertSame(['version' => get_plugin_version(), 'checksum' => get_router_checksum()], $labels);
    }

    /**
     * The compose file holds them as a map, and a router created before them
     * has none.
     */
    public function testLabelsAreReadInEitherFormAndMayBeMissing(): void
    {
        static::assertSame(['version' => '0.8.0', 'checksum' => 'abc'], parse_router_labels(['castor.router.version' => '0.8.0', 'castor.router.checksum' => 'abc']));
        static::assertSame(['version' => '0.8.0', 'checksum' => 'abc'], parse_router_labels(['castor.router.version=0.8.0', 'castor.router.checksum=abc']));
        static::assertSame(['version' => null, 'checksum' => null], parse_router_labels(['castor.router' => 'true']));
        static::assertSame(['version' => null, 'checksum' => null], parse_router_labels(null));
    }
}
