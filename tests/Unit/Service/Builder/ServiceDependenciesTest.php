<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service\Builder;

use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\Builder\ServiceBuilder;
use PHPUnit\Framework\TestCase;

/**
 * A dependency without a condition makes compose reject the whole file, so
 * every task of the project stops working.
 */
final class ServiceDependenciesTest extends TestCase
{
    private function service(): ServiceBuilder
    {
        return new ServiceBuilder('app', new ComposeBuilder());
    }

    public function testDependingOnAServiceWaitsForItToStartByDefault(): void
    {
        $service = $this->service()->dependsOn('mailpit');

        static::assertSame(
            ['mailpit' => ['condition' => 'service_started']],
            $service->toArray()['depends_on'],
        );
    }

    public function testAnExplicitConditionIsKept(): void
    {
        $service = $this->service()->dependsOn('postgres', ['condition' => 'service_healthy']);

        static::assertSame(
            ['postgres' => ['condition' => 'service_healthy']],
            $service->toArray()['depends_on'],
        );
    }

    public function testTheDefaultDoesNotDropTheRestOfTheConfiguration(): void
    {
        $service = $this->service()->dependsOn('postgres', ['restart' => true]);

        static::assertSame(
            ['postgres' => ['restart' => true, 'condition' => 'service_started']],
            $service->toArray()['depends_on'],
        );
    }

    public function testNoDependencyIsEmitedWithoutACondition(): void
    {
        $service = $this->service()
            ->dependsOn('mailpit')
            ->dependsOn('postgres', ['condition' => 'service_healthy'])
            ->dependsOn('redis', ['restart' => true])
        ;

        foreach ($service->toArray()['depends_on'] as $name => $config) {
            static::assertArrayHasKey('condition', $config, \sprintf('Dependency on "%s" has no condition.', $name));
        }
    }
}
