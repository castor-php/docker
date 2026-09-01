<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\PhpIniScope;
use Castor\Docker\Service\PhpMode;
use Castor\Docker\Service\PHPService;
use Castor\Docker\Tests\SnapshotTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Custom ini directives are mounted into the containers rather than built into
 * the image, so changing one does not rebuild anything.
 */
final class PhpIniTest extends SnapshotTestCase
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
    private function targetsOf(PHPService $app, string $service): array
    {
        $compose = $this->build($app);

        return array_column($compose['services'][$service]['configs'] ?? [], 'target');
    }

    public function testNothingIsMountedUntilADirectiveIsGiven(): void
    {
        $compose = $this->build(new PHPService('app'));

        static::assertEmpty($compose['configs'] ?? []);
        static::assertArrayNotHasKey('configs', $compose['services']['app']);
    }

    public function testTheDirectivesReachTheGeneratedFile(): void
    {
        $app = (new PHPService('app'))->withPhpIni(['memory_limit' => '1G', 'opcache.enable' => 1]);

        $content = $this->build($app)['configs']['app-php-ini-web']['content'];

        static::assertStringContainsString('memory_limit = 1G', $content);
        static::assertStringContainsString('opcache.enable = 1', $content);
    }

    /**
     * php.ini has no notion of "true": a bool written as it comes out of PHP
     * would be an unknown value, and an "off" written as "" would silently be
     * an empty string.
     */
    public function testBooleansAreWrittenTheWayPhpReadsThem(): void
    {
        $app = (new PHPService('app'))->withPhpIni(['display_errors' => true, 'expose_php' => false]);

        $content = $this->build($app)['configs']['app-php-ini-web']['content'];

        static::assertStringContainsString('display_errors = On', $content);
        static::assertStringContainsString('expose_php = Off', $content);
    }

    public function testAScopeOnlyReachesItsOwnContainers(): void
    {
        $app = (new PHPService('app'))
            ->addWorker('messenger', 'php bin/console messenger:consume')
            ->withPhpIni(['memory_limit' => '4G'], PhpIniScope::Cli)
        ;

        static::assertSame([], $this->targetsOf($app, 'app'));
        static::assertNotSame([], $this->targetsOf($app, 'app-builder'));
        static::assertNotSame([], $this->targetsOf($app, 'app-worker-messenger'));
    }

    /**
     * A worker runs commands, so it follows the PHP that runs commands.
     */
    public function testWorkersFollowTheCommandLineScope(): void
    {
        $app = (new PHPService('app'))
            ->addWorker('messenger', 'php bin/console messenger:consume')
            ->withPhpIni(['memory_limit' => '4G'], PhpIniScope::Cli)
        ;

        static::assertSame(
            $this->targetsOf($app, 'app-builder'),
            $this->targetsOf($app, 'app-worker-messenger'),
        );
    }

    /**
     * FrankenPHP is built on the official PHP image and reads one conf.d for
     * everything; the FPM image is Debian and reads one per SAPI. Putting the
     * file in the wrong one is silent — PHP simply never reads it.
     */
    public function testTheFileLandsWhereTheServerActuallyReadsIt(): void
    {
        $franken = (new PHPService('app'))->withMode(PhpMode::FrankenPhp)->withPhpIni(['memory_limit' => '1G']);
        $fpm = (new PHPService('app'))->withMode(PhpMode::Fpm)->withVersion('8.4')->withPhpIni(['memory_limit' => '1G']);

        static::assertSame(['/usr/local/etc/php/conf.d/99-castor.ini'], $this->targetsOf($franken, 'app'));
        static::assertSame(['/etc/php/8.4/fpm/conf.d/99-castor.ini'], $this->targetsOf($fpm, 'app'));
    }

    /**
     * Every container of a FrankenPHP application runs the PHP of that image,
     * builder and workers included, so the CLI file goes to the same conf.d as
     * the web one — the containers are what keeps the two scopes apart.
     */
    public function testTheCliFileFollowsTheImageTheCommandsRunOn(): void
    {
        $franken = (new PHPService('app'))
            ->withMode(PhpMode::FrankenPhp)
            ->withPhpIni(['memory_limit' => '4G'], PhpIniScope::Cli)
        ;
        $fpm = (new PHPService('app'))
            ->withMode(PhpMode::Fpm)
            ->withVersion('8.4')
            ->withPhpIni(['memory_limit' => '4G'], PhpIniScope::Cli)
        ;

        static::assertSame(['/usr/local/etc/php/conf.d/99-castor.ini'], $this->targetsOf($franken, 'app-builder'));
        static::assertSame(['/etc/php/8.4/cli/conf.d/99-castor.ini'], $this->targetsOf($fpm, 'app-builder'));
    }

    /**
     * 99 is after everything the image ships, so the project has the last word
     * over the defaults this plugin installs at 30 and 40.
     */
    public function testTheProjectHasTheLastWordOverTheShippedDefaults(): void
    {
        $app = (new PHPService('app'))->withPhpIni(['memory_limit' => '1G']);

        foreach ($this->targetsOf($app, 'app') as $target) {
            static::assertStringContainsString('/99-', $target);
        }
    }

    public function testTheLastValueGivenForADirectiveWins(): void
    {
        $app = (new PHPService('app'))
            ->withPhpIni(['memory_limit' => '1G'])
            ->withPhpIni(['memory_limit' => '2G'])
        ;

        static::assertSame(['memory_limit' => '2G'], $app->getPhpIni(PhpIniScope::Web));
    }

    /**
     * The mounted file is a plain ini file, so it has to parse as one.
     */
    public function testTheGeneratedFileParsesAsIni(): void
    {
        $app = (new PHPService('app'))->withPhpIni([
            'memory_limit' => '1G',
            'error_reporting' => 'E_ALL',
            'date.timezone' => 'Europe/Paris',
        ]);

        // RAW: the file is compared as written, not as PHP would evaluate it —
        // parse_ini_string() would otherwise turn E_ALL into its numeric value
        $parsed = parse_ini_string(
            $this->build($app)['configs']['app-php-ini-web']['content'],
            scanner_mode: \INI_SCANNER_RAW,
        );

        static::assertSame(
            ['memory_limit' => '1G', 'error_reporting' => 'E_ALL', 'date.timezone' => 'Europe/Paris'],
            $parsed,
        );
    }

    /**
     * The containers have to be recreated when a directive changes, or the
     * change sits in the compose file without ever reaching PHP.
     */
    public function testChangingADirectiveRecreatesTheContainers(): void
    {
        $before = $this->build((new PHPService('app'))->withPhpIni(['memory_limit' => '1G']));
        $after = $this->build((new PHPService('app'))->withPhpIni(['memory_limit' => '2G']));

        $label = static fn(array $c): string => Yaml::dump($c['services']['app']['labels'] ?? []);

        static::assertNotSame($label($before), $label($after), 'Nothing tells compose the configuration changed.');
    }
}
