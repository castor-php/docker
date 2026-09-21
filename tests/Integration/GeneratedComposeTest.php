<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Integration;

use Castor\Docker\Tests\SnapshotTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

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
final class GeneratedComposeTest extends SnapshotTestCase
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

        $process = new Process([$castor, 'docker:build', '--help', '--no-interaction'], $exampleDir, timeout: 120);
        $process->run();

        // if the plugin did not boot, the docker:build command does not even exist
        static::assertTrue($process->isSuccessful(), "castor docker:build --help failed:\n" . $process->getOutput() . $process->getErrorOutput());

        $compose = Yaml::parseFile($exampleDir . '/compose.generated.yaml');
        static::assertIsArray($compose);

        $this->assertMatchesYamlSnapshot(self::maskUserId($compose));
    }

    /**
     * The uid the compose file runs the containers as is the one of whoever
     * runs the tests.
     *
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function maskUserId(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = self::maskUserId($value);
            } elseif ('user' === $key) {
                $data[$key] = '%UID%';
            }
        }

        return $data;
    }
}
