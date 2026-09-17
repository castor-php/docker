<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Installer;

use Castor\Docker\Installer\AbstractServiceInstaller;
use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Installer\Input;
use Castor\Docker\Installer\InputType;
use Castor\Docker\Installer\InstallerOptions;
use Castor\Docker\Installer\MariaDBInstaller;
use Castor\Docker\Installer\NodeInstaller;
use Castor\Docker\Installer\SymfonyInstaller;
use Castor\Docker\Service\ServiceInterface;
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
            '--with-symfony-version=SYMFONY-VERSION',
            '--with-database=DATABASE',
        ], InstallerOptions::usage(new SymfonyInstaller()));
    }

    /**
     * A choice an installer marks as multiple is answered with several of its
     * choices, so its option repeats where the others are written once.
     */
    public function testAMultipleChoiceRepeats(): void
    {
        $options = InstallerOptions::parse(new MultipleChoiceInstaller(), [
            'storage',
            '--with-buckets=media',
            '--with-buckets=backups',
        ]);

        static::assertSame(['buckets' => ['media', 'backups']], $options->answers);
    }

    /**
     * The prompt reads a multiple choice back from a comma-separated list, so
     * no choice may hold a comma — the command line may just as well take one.
     */
    public function testAMultipleChoiceTakesACommaSeparatedList(): void
    {
        $options = InstallerOptions::parse(new MultipleChoiceInstaller(), ['storage', '--with-buckets=media,backups']);

        static::assertSame(['buckets' => ['media', 'backups']], $options->answers);
    }

    public function testAMultipleChoiceIsNotAnsweredTwiceByTheSameChoice(): void
    {
        $options = InstallerOptions::parse(new MultipleChoiceInstaller(), ['storage', '--with-buckets=media,media']);

        static::assertSame(['buckets' => ['media']], $options->answers);
    }

    public function testAnEmptyMultipleChoicePicksNothing(): void
    {
        $options = InstallerOptions::parse(new MultipleChoiceInstaller(), ['storage', '--with-buckets=']);

        static::assertSame(['buckets' => []], $options->answers);
    }

    /**
     * Not passing it at all is not picking nothing: the question is still
     * asked, where an empty value has already answered it.
     */
    public function testAnUnansweredMultipleChoiceIsStillAsked(): void
    {
        $options = InstallerOptions::parse(new MultipleChoiceInstaller(), ['storage']);

        static::assertSame([], $options->answers);
    }

    public function testEveryChoiceOfAMultipleOneIsChecked(): void
    {
        $this->expectExceptionMessageMatches('/"--with-buckets" option expects one of "media", "backups"/');

        InstallerOptions::parse(new MultipleChoiceInstaller(), ['storage', '--with-buckets=media,logs']);
    }

    public function testTheUsageMarksAMultipleChoiceAsRepeatable(): void
    {
        static::assertSame(
            ['--with-buckets=media|backups...'],
            InstallerOptions::usage(new MultipleChoiceInstaller()),
        );
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

/**
 * No shipped installer asks a multiple choice yet, but a custom one may — the
 * command line has to answer it all the same.
 */
final class MultipleChoiceInstaller extends AbstractServiceInstaller
{
    public function getName(): string
    {
        return 'storage';
    }

    public function getDescription(): string
    {
        return 'Object storage';
    }

    public function getInputs(): array
    {
        return [
            new Input('buckets', 'Buckets to create', InputType::Choice, [], ['media', 'backups'], multiple: true),
        ];
    }

    public function buildStatements(ServiceStatementBuilder $builder, array $answers): void {}

    public function createInstance(array $answers): ServiceInterface
    {
        throw new \LogicException('Not needed to parse a command line.');
    }
}
