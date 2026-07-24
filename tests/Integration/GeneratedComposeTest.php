<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Boots the real castor binary in example/ (which regenerates
 * compose.generated.yaml) and checks the result against a committed,
 * normalized snapshot. Regenerate with UPDATE_SNAPSHOTS=1, then review the
 * diff.
 *
 * Requires a castor binary (CASTOR_BINARY env var, or "castor" in PATH).
 * Notes:
 *  - vendor/bin/castor does not work here, it does not act as a project
 *    runner when castor is installed as a dependency of this repository;
 *  - the boot must go through a real task command ("docker:build --help"):
 *    for "castor list" castor sets up a bare context without dispatching
 *    ContextCreatedEvent, so the generated file would lose the project name.
 */
final class GeneratedComposeTest extends TestCase
{
    private const SNAPSHOT = __DIR__ . '/../snapshots/example-compose.generated.yaml';

    public function testExampleGeneratedComposeIsUpToDate(): void
    {
        $root = \dirname(__DIR__, 2);
        $exampleDir = $root . '/example';
        $castor = getenv('CASTOR_BINARY') ?: (new ExecutableFinder())->find('castor');

        if (!$castor) {
            static::markTestSkipped('No castor binary found: install castor or set CASTOR_BINARY.');
        }

        if (!is_dir($exampleDir . '/.castor/vendor')) {
            $install = new Process([$castor, 'composer', 'install'], $exampleDir, timeout: 300);
            $install->run();

            if (!$install->isSuccessful()) {
                static::markTestSkipped('Could not install the example castor dependencies: ' . $install->getErrorOutput());
            }
        }

        $process = new Process([$castor, 'docker:build', '--help', '--no-interaction'], $exampleDir, timeout: 120);
        $process->run();

        // if the plugin did not boot, the docker:build command does not even exist
        static::assertTrue($process->isSuccessful(), "castor docker:build --help failed:\n" . $process->getOutput() . $process->getErrorOutput());

        $fresh = $this->normalize(file_get_contents($exampleDir . '/compose.generated.yaml'), $root);

        if (getenv('UPDATE_SNAPSHOTS') || !file_exists(self::SNAPSHOT)) {
            file_put_contents(self::SNAPSHOT, $fresh);
        }

        static::assertStringEqualsFile(
            self::SNAPSHOT,
            $fresh,
            'The example project now generates a different compose file. Review the diff, then regenerate the snapshot with "UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --testsuite integration".',
        );
    }

    private function normalize(string $yaml, string $root): string
    {
        // absolute package paths and the current uid vary by machine
        $yaml = str_replace($root, '%ROOT%', $yaml);

        return preg_replace('/^(\s*user:\s*).+$/m', '$1%UID%', $yaml);
    }
}
