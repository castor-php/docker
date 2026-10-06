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

    public function testARegistryCacheIsTheOnlyCacheOutsideOfGithubActions(): void
    {
        static::assertSame(
            ['cache_from' => ['type=registry,ref=${REGISTRY:-}/app:cache']],
            $this->buildWithRegistryCache(),
        );
    }

    public function testOnGithubActionsEveryBuildReadsAndWritesTheGithubCache(): void
    {
        putenv('GITHUB_ACTIONS=true');

        static::assertSame(
            [
                'cache_from' => ['type=registry,ref=${REGISTRY:-}/app:cache', 'type=gha,scope=app'],
                'cache_to' => ['type=gha,scope=app,mode=max,ignore-error=true'],
            ],
            $this->buildWithRegistryCache(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWithRegistryCache(): array
    {
        $builder = new ComposeBuilder();
        $builder->service('app')->build()->withRegistryCache('app');

        return $builder->toArray()['services']['app']['build'];
    }
}
