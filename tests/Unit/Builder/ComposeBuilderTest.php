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
}
