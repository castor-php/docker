<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Context;
use Castor\Docker\Service\PHPService;
use PHPUnit\Framework\TestCase;

/**
 * A path on the command line replaces the paths a QA tool reads from its
 * configuration file rather than narrowing them — PHPStan only falls back to
 * `parameters.paths` when the command line names none, PHP CS Fixer ignores its
 * finder unless asked to intersect with it, Rector does the same with
 * `withPaths()`.
 *
 * So the default arguments of the tasks may not name a path as soon as the
 * application configures the tool, or an application analysing `src` would end
 * up analysing `vendor/` and `var/` too, configuration file or not.
 */
final class QaToolDefaultArgumentsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'qa-tool');
        \assert($directory !== false);
        unlink($directory);
        mkdir($directory . '/my-project/server/backend', 0o777, true);

        $this->directory = $directory . '/my-project';
    }

    protected function tearDown(): void
    {
        $this->remove(\dirname($this->directory));
    }

    /**
     * Configuration files are dotfiles as often as not, which glob("*") leaves
     * behind.
     */
    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            unlink($path);

            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->remove($path . '/' . $entry);
        }

        rmdir($path);
    }

    /**
     * @param list<string> $command
     *
     * @return list<string>
     */
    private function arguments(string $tool, array $command, string $suffix = '', ?string $workingDirectory = null, ?string $directory = null): array
    {
        $service = new class ('app') extends PHPService {
            /**
             * @param list<string> $command
             *
             * @return list<string>
             */
            public function defaults(string $tool, Context $context, array $command, string $suffix): array
            {
                return $this->getDefaultQaToolArguments($tool, $context, $command, $suffix);
            }
        };

        if (null !== $directory) {
            $service->withDirectory($directory);
        }

        if (null !== $workingDirectory) {
            $service->withWorkingDirectory($workingDirectory);
        }

        return $service->defaults($tool, new Context(workingDirectory: $this->directory), $command, $suffix);
    }

    public function testPhpStanIsGivenNoPathWhenTheApplicationConfiguresIt(): void
    {
        touch($this->directory . '/phpstan.neon');

        static::assertSame(['analyze'], $this->arguments('phpstan', ['analyze']));
    }

    /**
     * Without a configuration file there are no paths to read, so PHPStan needs
     * one on the command line to have anything to analyse at all.
     */
    public function testPhpStanFallsBackToTheApplicationDirectory(): void
    {
        static::assertSame(['analyze', '/var/www'], $this->arguments('phpstan', ['analyze']));
    }

    /**
     * Any of the names PHPStan discovers on its own, since those are exactly the
     * ones it reads the paths from.
     */
    public function testPhpStanRecognisesItsDistributedConfigurationFiles(): void
    {
        touch($this->directory . '/phpstan.dist.neon');

        static::assertSame(['analyze'], $this->arguments('phpstan', ['analyze']));
    }

    /**
     * The tools run in the working directory of the application, so that is
     * where a configuration file counts — the repository root is only the mount.
     */
    public function testTheConfigurationIsLookedUpInTheApplicationDirectory(): void
    {
        touch($this->directory . '/phpstan.neon');

        static::assertSame(
            ['analyze', '/var/www/server/backend'],
            $this->arguments('phpstan', ['analyze'], workingDirectory: 'server/backend'),
        );

        touch($this->directory . '/server/backend/phpstan.neon');

        static::assertSame(
            ['analyze'],
            $this->arguments('phpstan', ['analyze'], workingDirectory: 'server/backend'),
        );
    }

    /**
     * withDirectory() is relative to the project when it is not an absolute
     * path, and only the context knows where the project is.
     */
    public function testARelativeMountIsResolvedAgainstTheProject(): void
    {
        touch($this->directory . '/server/backend/rector.php');

        static::assertSame(
            [],
            $this->arguments('rector', [], workingDirectory: 'backend', directory: 'server'),
        );
    }

    public function testPhpCsFixerIsGivenNoPathWhenTheApplicationConfiguresIt(): void
    {
        touch($this->directory . '/.php-cs-fixer.dist.php');

        static::assertSame(['fix'], $this->arguments('php-cs-fixer', ['fix'], '/src'));
    }

    /**
     * The fallback of the tools that write rather than report stays on "src":
     * pointing them at the application root would reformat "vendor/" and "var/".
     */
    public function testTheFixersFallBackToTheSourcesOnly(): void
    {
        static::assertSame(['fix', '/var/www/src'], $this->arguments('php-cs-fixer', ['fix'], '/src'));
        static::assertSame(['/var/www/src'], $this->arguments('rector', [], '/src'));
    }
}
