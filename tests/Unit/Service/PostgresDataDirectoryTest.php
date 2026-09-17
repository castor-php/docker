<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\PostgresService;
use Castor\Docker\Tests\SnapshotTestCase;

/**
 * Postgres 18 moved PGDATA to "/var/lib/postgresql/18/docker" and declares its
 * volume on "/var/lib/postgresql". Mounting the data volume on the pre-18 path
 * leaves the database in the container layer, so it is lost on every recreate.
 */
final class PostgresDataDirectoryTest extends SnapshotTestCase
{
    private function mountOf(PostgresService $service): string
    {
        $compose = $service->updateCompose($this->fixedContext(), new ComposeBuilder())->toArray();

        return $compose['services'][$service->getName()]['volumes'][0];
    }

    public function testTheVolumeIsMountedOnTheDeclaredOneFrom18(): void
    {
        foreach (['18', '18.4', '18-alpine', '19.0'] as $version) {
            static::assertSame(
                'postgres_data:/var/lib/postgresql',
                $this->mountOf((new PostgresService())->withVersion($version)),
                $version,
            );
        }
    }

    public function testTheVolumeStaysOnTheDataDirectoryBefore18(): void
    {
        foreach (['17', '17.6', '16-bookworm', '9.6'] as $version) {
            static::assertSame(
                'postgres_data:/var/lib/postgresql/data',
                $this->mountOf((new PostgresService())->withVersion($version)),
                $version,
            );
        }
    }

    /**
     * A tag naming no version at all tracks the latest release, which is 18 or
     * newer — never an older one.
     */
    public function testATagNamingNoVersionIsTreatedAsRecent(): void
    {
        foreach (['latest', 'alpine', 'bookworm'] as $version) {
            static::assertSame(
                'postgres_data:/var/lib/postgresql',
                $this->mountOf((new PostgresService())->withVersion($version)),
                $version,
            );
        }
    }
}
