<?php

declare(strict_types=1);

namespace Castor\Docker\Tests;

use Castor\Context;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

abstract class SnapshotTestCase extends TestCase
{
    /**
     * A context with fixed data so snapshots do not depend on the machine
     * running the tests.
     */
    protected function fixedContext(): Context
    {
        return new Context(
            data: [
                'project_name' => 'demo',
                'root_domain' => 'demo.test',
                'user_id' => 1000,
            ],
            workingDirectory: '/project',
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function assertMatchesYamlSnapshot(array $data): void
    {
        $yaml = Yaml::dump($data, 6, 4);
        // package paths (__DIR__-based resources) are machine-dependent
        $yaml = str_replace(\dirname(__DIR__), '%PACKAGE%', $yaml);

        $file = $this->snapshotFile();

        if (getenv('UPDATE_SNAPSHOTS') || !file_exists($file)) {
            if (!is_dir(\dirname($file))) {
                mkdir(\dirname($file), 0o777, true);
            }

            file_put_contents($file, $yaml);
        }

        static::assertStringEqualsFile($file, $yaml, \sprintf(
            'Snapshot "%s" does not match. Run "UPDATE_SNAPSHOTS=1 vendor/bin/phpunit" to regenerate it, then review the diff.',
            basename($file),
        ));
    }

    private function snapshotFile(): string
    {
        $class = substr(static::class, strrpos(static::class, '\\') + 1);

        return \sprintf('%s/snapshots/%s/%s.yaml', __DIR__, $class, $this->name());
    }
}
