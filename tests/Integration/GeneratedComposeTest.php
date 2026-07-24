<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Boots the real castor binary in example/ (which regenerates
 * compose.generated.yaml) and checks the result matches the committed file.
 * The committed compose.generated.yaml in example/ IS the snapshot: when this
 * test fails, review and commit the new file.
 *
 * Requires a castor binary (CASTOR_BINARY env var, or "castor" in PATH).
 * Note: vendor/bin/castor does not work here, it does not act as a project
 * runner when castor is installed as a dependency of this repository.
 */
final class GeneratedComposeTest extends TestCase
{
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

        $generatedFile = $exampleDir . '/compose.generated.yaml';
        $committed = file_get_contents($generatedFile);

        $process = new Process([$castor, 'list', '--no-interaction'], $exampleDir, timeout: 120);
        $process->run();

        static::assertTrue($process->isSuccessful(), "castor list failed:\n" . $process->getOutput() . $process->getErrorOutput());

        // guard against a vacuous pass: if the plugin did not boot, nothing was regenerated
        static::assertStringContainsString('docker:build', $process->getOutput(), 'The docker plugin tasks are missing: the example project did not boot correctly.');

        $fresh = file_get_contents($generatedFile);

        static::assertSame(
            $this->normalize($committed, $root),
            $this->normalize($fresh, $root),
            'example/compose.generated.yaml is out of date: the code now generates a different compose file. Review the change and commit the regenerated file.',
        );
    }

    private function normalize(string $yaml, string $root): string
    {
        // absolute package paths and the current uid vary by machine
        $yaml = str_replace($root, '%ROOT%', $yaml);

        return preg_replace('/^(\s*user:\s*).+$/m', '$1%UID%', $yaml);
    }
}
