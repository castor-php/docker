<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;
use PHPUnit\Framework\TestCase;

use function Castor\Docker\add_project_extra_hosts;

/**
 * The router joins the project network from the outside and without a DNS
 * alias, so nothing inside a project resolves its own public domains. The
 * generator points each of them at the host gateway, where the router's
 * published ports 80 and 443 answer.
 */
final class ProjectExtraHostsTest extends TestCase
{
    private function context(mixed $resolve = null): Context
    {
        return new Context(
            data: $resolve === null ? [] : ['resolve_domains_via_host' => $resolve],
            workingDirectory: '/project',
        );
    }

    public function testEveryServiceResolvesEveryProjectDomain(): void
    {
        $builder = new ComposeBuilder();
        $builder
            ->service('app')->withHttpRouting(['app.demo.test', 'demo.test'])->end()
            ->service('api')->withHttpRouting('api.demo.test')->end()
            // Not routed itself, but a worker calling the public API needs the
            // domains just as much.
            ->service('worker')
        ;

        add_project_extra_hosts($this->context(), $builder);

        $expected = [
            'app.demo.test:host-gateway',
            'demo.test:host-gateway',
            'api.demo.test:host-gateway',
        ];

        foreach (['app', 'api', 'worker'] as $service) {
            static::assertSame($expected, $builder->toArray()['services'][$service]['extra_hosts'], $service);
        }
    }

    public function testExistingExtraHostsAreKept(): void
    {
        $builder = new ComposeBuilder();
        $builder
            ->service('app')
                ->extraHost('host.docker.internal', 'host-gateway')
                ->withHttpRouting('app.demo.test')
            ->end()
        ;

        add_project_extra_hosts($this->context(), $builder);

        static::assertSame(
            ['host.docker.internal:host-gateway', 'app.demo.test:host-gateway'],
            $builder->toArray()['services']['app']['extra_hosts'],
        );
    }

    /**
     * "localhost" must keep pointing at 127.0.0.1, and a bare label must keep
     * resolving to the container of that name on the project network.
     */
    public function testDotlessNamesAreLeftAlone(): void
    {
        $builder = new ComposeBuilder();
        $builder
            ->service('app')->withHttpRouting(['app.demo.test', 'localhost', 'app'])->end()
        ;

        add_project_extra_hosts($this->context(), $builder);

        static::assertSame(['app.demo.test:host-gateway'], $builder->toArray()['services']['app']['extra_hosts']);
    }

    public function testNothingIsAddedWhenEveryDomainIsDotless(): void
    {
        $builder = new ComposeBuilder();
        $builder->service('app')->withHttpRouting('localhost')->end();

        add_project_extra_hosts($this->context(), $builder);

        static::assertArrayNotHasKey('extra_hosts', $builder->toArray()['services']['app']);
    }

    public function testNothingIsAddedWithoutAnyRoutedDomain(): void
    {
        $builder = new ComposeBuilder();
        $builder->service('postgres')->image('postgres:18');

        add_project_extra_hosts($this->context(), $builder);

        static::assertArrayNotHasKey('extra_hosts', $builder->toArray()['services']['postgres']);
    }

    public function testItCanBeTurnedOffFromTheContext(): void
    {
        $builder = new ComposeBuilder();
        $builder->service('app')->withHttpRouting('app.demo.test')->end();

        add_project_extra_hosts($this->context(false), $builder);

        static::assertArrayNotHasKey('extra_hosts', $builder->toArray()['services']['app']);
    }

    public function testItIsOnByDefault(): void
    {
        $builder = new ComposeBuilder();
        $builder->service('app')->withHttpRouting('app.demo.test')->end();

        add_project_extra_hosts($this->context(true), $builder);

        static::assertSame(['app.demo.test:host-gateway'], $builder->toArray()['services']['app']['extra_hosts']);
    }
}
