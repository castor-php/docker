<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\PhpMode;
use Castor\Docker\Service\PHPService;
use Castor\Docker\Tests\SnapshotTestCase;

/**
 * The two modes install extensions from different places — the sury packages
 * for PhpMode::Fpm, install-php-extensions for PhpMode::FrankenPhp — and a few
 * names disagree. Inside one application they must not: the CLI of the builder
 * and the PHP serving the requests are the same binary, so they get the same
 * list.
 */
final class PhpExtensionTest extends SnapshotTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function build(PHPService $app): array
    {
        return $app->updateCompose($this->fixedContext(), new ComposeBuilder())->toArray();
    }

    /**
     * @return list<string>
     */
    private function extensionsOf(PHPService $app, string $service): array
    {
        $json = $this->build($app)['services'][$service]['build']['args']['php_extensions'] ?? '[]';
        \assert(\is_string($json));

        /** @var list<string> */
        return json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * A Debian package shipping several modules is one install-php-extensions
     * name per module: "pgsql" alone left a FrankenPHP application without
     * pdo_pgsql, which is the driver Doctrine actually connects with.
     */
    public function testTheDebianNamesAreTranslatedForFrankenPhp(): void
    {
        $app = (new PHPService('app'))
            ->withMode(PhpMode::FrankenPhp)
            ->addExtension('mysql')
            ->addExtension('sqlite3')
        ;

        $extensions = $app->getExtensions();

        static::assertContains('pdo_pgsql', $extensions);
        static::assertContains('pdo_mysql', $extensions);
        static::assertContains('mysqli', $extensions);
        static::assertContains('pdo_sqlite', $extensions);
        static::assertNotContains('mysql', $extensions, 'There is no "mysql" extension upstream, only the Debian package.');
    }

    /**
     * The Debian names are the ones the packages have, so PhpMode::Fpm keeps
     * them untouched.
     */
    public function testTheDebianNamesAreKeptForFpm(): void
    {
        $app = (new PHPService('app'))->withMode(PhpMode::Fpm)->addExtension('mysql');

        static::assertContains('mysql', $app->getExtensions());
        static::assertNotContains('pdo_mysql', $app->getExtensions());
        static::assertNotContains('pdo_pgsql', $app->getExtensions());
    }

    public function testAnExtensionNamedTheSameOnBothSidesIsPassedThrough(): void
    {
        foreach ([PhpMode::FrankenPhp, PhpMode::Fpm] as $mode) {
            $app = (new PHPService('app'))->withMode($mode)->addExtension('redis');

            static::assertContains('redis', $app->getExtensions(), $mode->value);
        }
    }

    /**
     * The mode is what decides the spelling, and it can be set after the
     * extensions have been added.
     */
    public function testTheModeIsReadWhenTheListIsBuilt(): void
    {
        $app = (new PHPService('app'))->addExtension('mysql')->withMode(PhpMode::Fpm);

        static::assertNotContains('pdo_mysql', $app->getExtensions());
    }

    /**
     * One PHP per application: an extension missing from the container running
     * the tests, or from the one serving the pages, is the bug this guards.
     */
    public function testEveryContainerOfTheApplicationInstallsTheSameExtensions(): void
    {
        $app = (new PHPService('app'))
            ->addExtension('redis')
            ->addWorker('messenger', 'php bin/console messenger:consume')
        ;

        static::assertSame($app->getExtensions(), $this->extensionsOf($app, 'app'));
        static::assertSame($app->getExtensions(), $this->extensionsOf($app, 'app-builder'));
        static::assertSame($app->getExtensions(), $this->extensionsOf($app, 'app-worker-messenger'));
    }

    public function testTheListHoldsNoDuplicate(): void
    {
        $app = (new PHPService('app'))->addExtension('pdo_pgsql')->addExtension('redis')->addExtension('redis');

        static::assertSame(array_values(array_unique($app->getExtensions())), $app->getExtensions());
    }
}
