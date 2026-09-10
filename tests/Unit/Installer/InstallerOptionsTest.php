<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Installer;

use Castor\Docker\Installer\InstallerOptions;
use Castor\Docker\Installer\MariaDBInstaller;
use Castor\Docker\Installer\NodeInstaller;
use Castor\Docker\Installer\SymfonyInstaller;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputOption;

use function Castor\Docker\find_installer_name;

/**
 * The questions an installer asks are also options of the install command, so
 * a service can be installed by a script rather than by hand.
 */
final class InstallerOptionsTest extends TestCase
{
    public function testInputsAreAnsweredByOptions(): void
    {
        $options = InstallerOptions::parse(new SymfonyInstaller(), [
            'symfony',
            '--with-name=blog',
            '--with-version=8.4',
            '--with-mode=fpm',
            '--with-symfony-version=7.3.*',
        ]);

        static::assertSame([
            'name' => 'blog',
            'version' => '8.4',
            'mode' => 'fpm',
            'symfony_version' => '7.3.*',
        ], $options->answers);
    }

    /**
     * What is not passed is not answered, so the question is still asked — or
     * its default taken under "--no-interaction".
     */
    public function testUnansweredInputsAreLeftOut(): void
    {
        $options = InstallerOptions::parse(new MariaDBInstaller(), ['mariadb']);

        static::assertSame([], $options->answers);
        static::assertNull($options->database);
        static::assertNull($options->file);
    }

    public function testAnEmptyValueIsAnAnswer(): void
    {
        $options = InstallerOptions::parse(new SymfonyInstaller(), ['symfony', '--with-domain=']);

        static::assertSame(['domain' => ''], $options->answers);
    }

    public function testAnIntegerInputIsCast(): void
    {
        $options = InstallerOptions::parse(new NodeInstaller(), ['node', '--with-port=8080']);

        static::assertSame(['port' => 8080], $options->answers);
    }

    public function testASnakeCasedInputIsKebabCased(): void
    {
        $options = InstallerOptions::parse(new NodeInstaller(), ['node', '--with-package-manager=pnpm']);

        static::assertSame(['package_manager' => 'pnpm'], $options->answers);
    }

    public function testTheFileAndTheDatabaseAreReadBack(): void
    {
        $options = InstallerOptions::parse(new SymfonyInstaller(), [
            'symfony',
            '--file=/tmp/listener.php',
            '--with-database=none',
        ]);

        static::assertSame('/tmp/listener.php', $options->file);
        static::assertSame('none', $options->database);
    }

    public function testAServiceLinkingToNoDatabaseHasNoDatabaseOption(): void
    {
        $this->expectException(\RuntimeException::class);

        InstallerOptions::parse(new MariaDBInstaller(), ['mariadb', '--with-database=postgres']);
    }

    public function testAnUnknownOptionListsTheOnesTheServiceTakes(): void
    {
        $this->expectExceptionMessageMatches('/--with-version=VERSION/');

        InstallerOptions::parse(new MariaDBInstaller(), ['mariadb', '--with-releases=11.4']);
    }

    public function testANonNumericIntegerIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/"--with-port" option expects a number/');

        InstallerOptions::parse(new NodeInstaller(), ['node', '--with-port=soon']);
    }

    public function testAValueOutsideTheChoicesIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/"--with-mode" option expects one of "frankenphp", "fpm"/');

        InstallerOptions::parse(new SymfonyInstaller(), ['symfony', '--with-mode=apache']);
    }

    /**
     * The global options of castor may sit anywhere on the command line, and
     * are none of the installer's business.
     */
    public function testTheApplicationOptionsAreTolerated(): void
    {
        $options = InstallerOptions::parse(
            new MariaDBInstaller(),
            ['mariadb', '--no-interaction', '--with-version=11.4'],
            [new InputOption('no-interaction', 'n', InputOption::VALUE_NONE)],
        );

        static::assertSame(['version' => '11.4'], $options->answers);
    }

    public function testTheUsageOfAnInstaller(): void
    {
        static::assertSame([
            '--with-name=NAME',
            '--with-directory=DIRECTORY',
            '--with-version=VERSION',
            '--with-mode=frankenphp|fpm',
            '--with-domain=DOMAIN',
            '--with-symfony-version=SYMFONY_VERSION',
            '--with-database=DATABASE',
        ], InstallerOptions::usage(new SymfonyInstaller()));
    }

    /**
     * An option castor knows nothing about swallows the argument holding the
     * service name, which is then read back from the command line.
     */
    public function testTheServiceIsFoundBackAmongTheTokens(): void
    {
        $installers = ['mariadb' => new MariaDBInstaller(), 'node' => new NodeInstaller()];

        static::assertSame('mariadb', find_installer_name(['--with-version=11.4', 'mariadb'], $installers));
        static::assertSame('node', find_installer_name(['--with-name', 'mariadb', 'node'], $installers));
        static::assertNull(find_installer_name(['--with-version=11.4'], $installers));
    }
}
