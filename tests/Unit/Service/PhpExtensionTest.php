<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\ExtensionInstaller;
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

    /**
     * @return array<string, string>
     */
    private function argsOf(PHPService $app, string $service): array
    {
        /** @var array<string, string> */
        return $this->build($app)['services'][$service]['build']['args'] ?? [];
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

    /**
     * PIE builds an extension from its sources, so it is a separate list: the
     * installer of the mode never sees those names, and they are Composer
     * packages rather than modules.
     */
    public function testAPieExtensionIsNotHandedToTheInstallerOfTheMode(): void
    {
        $app = (new PHPService('app'))
            ->addExtension('redis')
            ->addExtension('xdebug/xdebug', installer: ExtensionInstaller::Pie)
        ;

        static::assertContains('redis', $app->getExtensions());
        static::assertNotContains('xdebug/xdebug', $app->getExtensions());
        static::assertSame([['name' => 'xdebug/xdebug', 'dependencies' => []]], $app->getPieExtensions());
    }

    /**
     * A version is part of the name, in whatever syntax the installer reads:
     * nothing here parses it.
     */
    public function testAVersionRidesAlongWithTheName(): void
    {
        $app = (new PHPService('app'))->addExtension('xdebug/xdebug:^3.5@alpha', installer: ExtensionInstaller::Pie);

        static::assertSame('xdebug/xdebug:^3.5@alpha', $app->getPieExtensions()[0]['name']);
    }

    /**
     * PIE installs a Composer package; a module name is what the installer of
     * the mode takes, and it would fail deep inside the build.
     */
    public function testAPieExtensionThatIsNotAPackageIsRefusedUpFront(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PHPService('app'))->addExtension('xdebug', installer: ExtensionInstaller::Pie);
    }

    /**
     * The packages an extension needs stay with the installer that needs them:
     * the PIE ones next to the extension they build, so each gets a layer of
     * its own, the others in the list the mode's own install reads.
     */
    public function testDependenciesFollowTheInstallerTheyBelongTo(): void
    {
        $app = (new PHPService('app'))
            ->addExtension('snmp', ['libsnmp-dev'])
            ->addExtension('php/kafka', ['librdkafka-dev'], ExtensionInstaller::Pie)
        ;

        static::assertSame(['libsnmp-dev'], $app->getExtensionDependencies());
        static::assertSame([['name' => 'php/kafka', 'dependencies' => ['librdkafka-dev']]], $app->getPieExtensions());
    }

    /**
     * One PHP per application, PIE included: the extension is compiled in the
     * stage the others are built on, so the build arguments carrying it have
     * to reach every one of them.
     */
    public function testEveryContainerOfTheApplicationGetsThePieExtensions(): void
    {
        $app = (new PHPService('app'))
            ->addExtension('xdebug/xdebug', ['libfoo-dev'], ExtensionInstaller::Pie)
            ->addWorker('messenger', 'php bin/console messenger:consume')
        ;

        $expected = json_encode($app->getPieExtensions(), \JSON_THROW_ON_ERROR);

        foreach (['app', 'app-builder', 'app-worker-messenger'] as $service) {
            static::assertSame($expected, $this->argsOf($app, $service)['pie_extensions'] ?? null, $service);
        }
    }

    /**
     * Asking for nothing sends nothing: the templates default both lists to
     * empty, and the whole toolchain a PIE build needs hangs off that test.
     */
    public function testAnApplicationWithoutThemSendsNeitherArgument(): void
    {
        $args = $this->argsOf(new PHPService('app'), 'app');

        static::assertArrayNotHasKey('pie_extensions', $args);
        static::assertArrayNotHasKey('php_extension_dependencies', $args);
    }

    /**
     * PIE is pinned, and the pin reaches the image: it both builds the
     * extensions and is the "pie" of the builder container.
     */
    public function testThePinnedPieReleaseReachesTheImage(): void
    {
        $app = (new PHPService('app'))->withPieVersion('1.2.3');

        static::assertSame('1.2.3', $this->argsOf($app, 'app')['pie_version'] ?? null);
    }
}
