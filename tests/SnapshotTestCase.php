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
        // package paths (__DIR__-based resources) are machine-dependent
        $yaml = self::dumpYaml(self::maskPackagePath($data));

        $file = $this->snapshotFile();

        if (getenv('UPDATE_SNAPSHOTS') || !file_exists($file)) {
            if (!is_dir(\dirname($file))) {
                mkdir(\dirname($file), 0o777, true);
            }

            file_put_contents($file, $yaml);
        }

        $snapshot = Yaml::parseFile($file);
        static::assertIsArray($snapshot, \sprintf('Snapshot "%s" is not a valid YAML mapping.', basename($file)));

        static::assertSame(self::dumpYaml($snapshot), $yaml, \sprintf(
            'Snapshot "%s" does not match. Run "UPDATE_SNAPSHOTS=1 vendor/bin/phpunit" to regenerate it, then review the diff.',
            basename($file),
        ));
    }

    /**
     * Replaces the absolute path of the package by a placeholder, on the data
     * rather than on the dumped YAML: the dumper then quotes the values, while
     * a raw "%" at the beginning of a plain scalar is a reserved indicator that
     * the parser rejects.
     *
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function maskPackagePath(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = self::maskPackagePath($value);
            } elseif (\is_string($value)) {
                $data[$key] = str_replace(\dirname(__DIR__), '%PACKAGE%', $value);
            }
        }

        return $data;
    }

    /**
     * Snapshots are compared through the dumper instead of byte by byte: this
     * repository does not commit a composer.lock, so CI always runs against
     * the latest symfony/yaml, and its exact formatting changes between
     * releases (v8.1.5 for instance stopped packing "- key: value" on a single
     * line when a custom indentation is used). Re-dumping the stored snapshot
     * keeps the assertion about the data we generate.
     *
     * @param array<mixed> $data
     */
    private static function dumpYaml(array $data): string
    {
        return Yaml::dump($data, 6, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    private function snapshotFile(): string
    {
        $class = substr(static::class, strrpos(static::class, '\\') + 1);

        return \sprintf('%s/snapshots/%s/%s.yaml', __DIR__, $class, $this->name());
    }
}
