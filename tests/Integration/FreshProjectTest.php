<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A project that declares no #[AsContext] function never goes through
 * ContextCreatedEvent, because castor boots it on a bare context. Everything
 * the plugin sets up there — compose.yaml, the project name, the user id — has
 * to happen anyway, otherwise every docker task fails on a fresh project.
 *
 * Requires a castor binary (CASTOR_BINARY env var, or "castor" in PATH).
 */
final class FreshProjectTest extends TestCase
{
    // Outside tests/Integration on purpose: composer symlinks the plugin back
    // to the repository root, and PHPUnit would follow it while collecting
    // tests, walking the tree forever.
    private const PROJECT = __DIR__ . '/../fixtures/fresh-project';

    public function testAProjectWithoutContextIsInitialized(): void
    {
        $castor = getenv('CASTOR_BINARY') ?: (new ExecutableFinder())->find('castor');

        if (!$castor) {
            static::markTestSkipped('No castor binary found: install castor or set CASTOR_BINARY.');
        }

        if (!is_dir(self::PROJECT . '/.castor/vendor')) {
            $install = new Process([$castor, 'composer', 'install'], self::PROJECT, timeout: 300);
            $install->run();

            if (!$install->isSuccessful()) {
                static::markTestSkipped('Could not install the fixture dependencies: ' . $install->getErrorOutput());
            }
        }

        foreach (['compose.yaml', 'compose.generated.yaml', 'compose.override.yaml'] as $file) {
            @unlink(self::PROJECT . '/' . $file);
        }

        foreach (['.home', 'api'] as $directory) {
            @rmdir(self::PROJECT . '/' . $directory);
        }

        // "--help" is enough: the plugin initializes the project on boot, and
        // nothing here should need a running Docker daemon.
        $process = new Process([$castor, 'docker:ps', '--help', '--no-interaction'], self::PROJECT, timeout: 120);
        $process->run();

        static::assertTrue($process->isSuccessful(), "castor docker:ps --help failed:\n" . $process->getOutput() . $process->getErrorOutput());

        static::assertFileExists(self::PROJECT . '/compose.yaml', 'compose.yaml must be created even without an #[AsContext] function.');
        static::assertFileExists(self::PROJECT . '/compose.generated.yaml');
        static::assertFileExists(self::PROJECT . '/compose.override.yaml');

        $compose = file_get_contents(self::PROJECT . '/compose.yaml');
        \assert($compose !== false);

        // --no-interaction keeps the default, which is the directory name.
        static::assertStringContainsString('name: fresh-project', $compose);
        static::assertStringContainsString('compose.generated.yaml', $compose);

        $generated = file_get_contents(self::PROJECT . '/compose.generated.yaml');
        \assert($generated !== false);

        static::assertStringContainsString('postgres', $generated);

        // Docker would create the missing bind mount sources itself, as root:
        // they have to exist, and belong to the user, before it runs.
        foreach (['.home', 'api'] as $directory) {
            $path = self::PROJECT . '/' . $directory;

            static::assertDirectoryExists($path, \sprintf('"%s" is bind-mounted, it must be created before docker does it as root.', $directory));
            static::assertDirectoryIsWritable($path);
        }
    }
}
