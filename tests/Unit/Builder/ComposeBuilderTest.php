<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Builder;

use Castor\Docker\Service\Builder\ComposeBuilder;
use PHPUnit\Framework\TestCase;

final class ComposeBuilderTest extends TestCase
{
    public function testEmptyCompose(): void
    {
        static::assertSame(['services' => [], 'volumes' => []], (new ComposeBuilder())->toArray());
    }

    public function testServiceIsReusedByName(): void
    {
        $builder = new ComposeBuilder();

        $first = $builder->service('app');
        $second = $builder->service('app');

        static::assertSame($first, $second, 'Requesting the same service name twice must return the same builder so services can be merged.');
    }

    public function testVolumes(): void
    {
        $builder = new ComposeBuilder();
        $builder->volume('data');
        $builder->volume('other', ['driver' => 'local']);

        static::assertSame(
            ['data' => [], 'other' => ['driver' => 'local']],
            $builder->toArray()['volumes'],
        );
    }

    public function testRoutedDomainsAreAggregatedAcrossServices(): void
    {
        $builder = new ComposeBuilder();

        $builder
            ->service('app')->withHttpRouting(['app.demo.test', 'demo.test'])->end()
            // Already routed by "app": the domain is only returned once.
            ->service('api')->withHttpRouting(['api.demo.test', 'demo.test'])->end()
            ->service('postgres')
        ;

        static::assertSame(
            ['app.demo.test', 'demo.test', 'api.demo.test'],
            $builder->getRoutedDomains(),
        );
    }

    public function testGetServicesReturnsEveryDeclaredService(): void
    {
        $builder = new ComposeBuilder();
        $builder->service('app');
        $builder->service('postgres');

        static::assertSame(['app', 'postgres'], array_keys($builder->getServices()));
    }

    /**
     * The host directories of the bind mounts are created before docker gets a
     * chance to create them as root, so they must be told apart from the named
     * volumes, which docker manages on its own.
     */
    public function testBindMountSourcesExcludeNamedVolumes(): void
    {
        $builder = new ComposeBuilder();
        $builder->volume('postgres-data');

        $builder
            ->service('app')
                ->volume('postgres-data', '/var/lib/postgresql/data')
                ->volume('.home', '/home/app', 'cached')
                ->volume('/project/app', '/var/www', 'cached')
            ->end()
            ->service('worker')
                // Already mounted by "app": the same directory is only returned once.
                ->volume('.home', '/home/app', 'cached')
                ->volume('/var/run/docker.sock', '/var/run/docker.sock')
            ->end()
        ;

        static::assertSame(
            ['.home', '/project/app', '/var/run/docker.sock'],
            $builder->getBindMountSources(),
        );
    }
}
