<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Builder;

use Castor\Docker\Service\Builder\ComposeBuilder;
use PHPUnit\Framework\TestCase;

final class BuildBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('GITHUB_ACTIONS=');
    }

    /**
     * A second cache describing the same stages makes BuildKit race them and
     * lose steps (moby/buildkit#6418): GitHub Actions gets no cache of its own.
     */
    public function testTheRegistryCacheIsTheOnlyCacheEvenOnGithubActions(): void
    {
        putenv('GITHUB_ACTIONS=true');

        $builder = new ComposeBuilder();
        $builder->service('app')->build()->withRegistryCache('app');

        static::assertSame(
            ['cache_from' => ['type=registry,ref=${REGISTRY:-}/app:cache']],
            $builder->toArray()['services']['app']['build'],
        );
    }
}
