<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\PhpMode;
use Castor\Docker\Service\PHPService;
use Castor\Docker\Tests\SnapshotTestCase;

/**
 * An extension is named the way the mode installs it — the Debian packages of
 * sury for PhpMode::Fpm, the install-php-extensions catalogue for
 * PhpMode::FrankenPhp — and reaches the image as written. Inside one
 * application there is one PHP, so there is one list.
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

    public function testAnExtensionReachesTheImageAsWritten(): void
    {
        foreach ([PhpMode::FrankenPhp, PhpMode::Fpm] as $mode) {
            $app = (new PHPService('app'))->withMode($mode)->addExtension('redis');

            static::assertContains('redis', $app->getExtensions(), $mode->value);
        }
    }

    /**
     * The default list is the same set of capabilities in both modes, spelled
     * the way each installs it: a Debian "pgsql" is pdo_pgsql too, and upstream
     * that driver is a name of its own — without it an application talking to
     * the PostgreSQL of this plugin has nothing to connect with.
     */
    public function testTheDefaultsAreSpelledTheWayTheModeInstallsThem(): void
    {
        $franken = (new PHPService('app'))->withMode(PhpMode::FrankenPhp)->getExtensions();
        $fpm = (new PHPService('app'))->withMode(PhpMode::Fpm)->getExtensions();

        static::assertContains('pdo_pgsql', $franken);
        static::assertContains('pgsql', $franken);

        static::assertContains('pgsql', $fpm);
        static::assertNotContains('pdo_pgsql', $fpm, 'There is no "php{version}-pdo_pgsql" package to install.');
    }

    /**
     * The mode is read when the list is built, not when the service is
     * constructed: withMode() comes after the constructor.
     */
    public function testTheDefaultsFollowAModeSetAfterwards(): void
    {
        $app = (new PHPService('app'))->addExtension('redis')->withMode(PhpMode::Fpm);

        static::assertNotContains('pdo_pgsql', $app->getExtensions());
        static::assertContains('redis', $app->getExtensions());
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
        $app = (new PHPService('app'))->addExtension('pgsql')->addExtension('redis')->addExtension('redis');

        static::assertSame(array_values(array_unique($app->getExtensions())), $app->getExtensions());
    }
}
