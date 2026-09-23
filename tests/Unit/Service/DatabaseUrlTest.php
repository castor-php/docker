<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Docker\Service\DatabaseServiceInterface;
use Castor\Docker\Service\MariaDBService;
use Castor\Docker\Service\MySQLService;
use Castor\Docker\Service\PostgresService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * serverVersion must match the running server, or be omitted.
 */
final class DatabaseUrlTest extends TestCase
{
    #[DataProvider('provideServices')]
    public function testTheDefaultVersionIsTheOneAnnounced(PostgresService|MySQLService|MariaDBService $service): void
    {
        static::assertMatchesRegularExpression(
            '/[?&]serverVersion=(mariadb-)?' . preg_quote($service->getVersion(), '/') . '&/',
            $service->getDatabaseURL(),
        );
    }

    /**
     * @return iterable<string, array{PostgresService|MySQLService|MariaDBService}>
     */
    public static function provideServices(): iterable
    {
        yield 'postgres' => [new PostgresService()];
        yield 'mysql' => [new MySQLService()];
        yield 'mariadb' => [new MariaDBService()];
    }

    #[DataProvider('provideVersions')]
    public function testTheServerVersionFollowsTheTag(DatabaseServiceInterface $service, string $url): void
    {
        static::assertSame($url, $service->getDatabaseURL());
    }

    /**
     * @return iterable<string, array{DatabaseServiceInterface, string}>
     */
    public static function provideVersions(): iterable
    {
        yield 'postgres major' => [(new PostgresService())->withVersion('17'), 'postgresql://app:app@postgres:5432/app?serverVersion=17&charset=utf8'];
        yield 'postgres variant' => [(new PostgresService())->withVersion('16.4-alpine'), 'postgresql://app:app@postgres:5432/app?serverVersion=16.4&charset=utf8'];
        yield 'postgres latest' => [(new PostgresService())->withVersion('latest'), 'postgresql://app:app@postgres:5432/app?charset=utf8'];
        yield 'postgres distribution' => [(new PostgresService())->withVersion('bookworm'), 'postgresql://app:app@postgres:5432/app?charset=utf8'];

        yield 'mysql minor' => [(new MySQLService())->withVersion('8.4'), 'mysql://root:root@mysql:3306/app?serverVersion=8.4&charset=utf8mb4'];
        yield 'mysql variant' => [(new MySQLService())->withVersion('9.7.2-oracle'), 'mysql://root:root@mysql:3306/app?serverVersion=9.7.2&charset=utf8mb4'];
        yield 'mysql lts' => [(new MySQLService())->withVersion('lts'), 'mysql://root:root@mysql:3306/app?charset=utf8mb4'];

        yield 'mariadb patch' => [(new MariaDBService())->withVersion('11.8.9'), 'mysql://root:root@mariadb:3306/app?serverVersion=mariadb-11.8.9&charset=utf8mb4'];
        yield 'mariadb variant' => [(new MariaDBService())->withVersion('12.3.3-ubi'), 'mysql://root:root@mariadb:3306/app?serverVersion=mariadb-12.3.3&charset=utf8mb4'];
        // Doctrine rejects an incomplete MariaDB version.
        yield 'mariadb minor' => [(new MariaDBService())->withVersion('11.8'), 'mysql://root:root@mariadb:3306/app?charset=utf8mb4'];
        yield 'mariadb latest' => [(new MariaDBService())->withVersion('latest'), 'mysql://root:root@mariadb:3306/app?charset=utf8mb4'];
    }
}
