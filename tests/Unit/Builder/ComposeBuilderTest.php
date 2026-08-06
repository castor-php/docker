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

    /**
     * Compose interpolates the file it reads, the content of the configs
     * included: an nginx configuration would reach the container stripped of
     * every $host, $uri and $document_root, with only a "variable is not set"
     * warning to show for it.
     */
    public function testConfigContentIsEscapedAgainstInterpolation(): void
    {
        $builder = new ComposeBuilder();
        $builder->config('nginx', "location / {\n    proxy_set_header Host \$host\$uri;\n}");

        static::assertSame(
            "location / {\n    proxy_set_header Host \$\$host\$\$uri;\n}",
            $builder->toArray()['configs']['nginx']['content'],
        );
    }

    public function testAConfigCanOptBackIntoInterpolation(): void
    {
        $builder = new ComposeBuilder();
        $builder->config('app', 'project = ${PROJECT_NAME}', interpolate: true);

        static::assertSame('project = ${PROJECT_NAME}', $builder->toArray()['configs']['app']['content']);
    }

    /**
     * The digest is computed on what the user passed, not on the escaped form.
     */
    public function testConfigContentIsReadableUnescaped(): void
    {
        $builder = new ComposeBuilder();
        $builder->config('nginx', 'root $document_root;');

        static::assertSame('root $document_root;', $builder->getConfigContent('nginx'));
        static::assertNull($builder->getConfigContent('absent'));
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
