<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Transform;

use Castor\TwigDockerfile\ArrayTemplateSource;
use Castor\TwigDockerfile\DockerfileTransformer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Error\LoaderError;

/**
 * Golden-file tests: each directory in fixtures/ is one case.
 *
 *   input.Dockerfile      the Dockerfile as written by the user
 *   args.json             build args (optional)
 *   contexts/<name>/...   files served for the build context <name>
 *                         ("context" is the default/main build context)
 *   expected.Dockerfile   the transformed output
 *   expected_error.txt    alternatively, a substring of the expected error
 *
 * Regenerate expected files with UPDATE_SNAPSHOTS=1, then review the diff.
 */
final class DockerfileTransformerTest extends TestCase
{
    #[DataProvider('fixtureProvider')]
    public function testFixture(string $dir): void
    {
        $input = file_get_contents($dir . '/input.Dockerfile');
        $args = [];

        if (file_exists($dir . '/args.json')) {
            $args = json_decode(file_get_contents($dir . '/args.json'), true, flags: \JSON_THROW_ON_ERROR);
        }

        $transformer = new DockerfileTransformer(new ArrayTemplateSource(self::loadContexts($dir . '/contexts')));

        if (file_exists($dir . '/expected_error.txt')) {
            $this->expectException(LoaderError::class);
            $this->expectExceptionMessage(trim(file_get_contents($dir . '/expected_error.txt')));

            $transformer->transform($input, $args);

            return;
        }

        $actual = $transformer->transform($input, $args);
        $expectedFile = $dir . '/expected.Dockerfile';

        if (getenv('UPDATE_SNAPSHOTS') || !file_exists($expectedFile)) {
            file_put_contents($expectedFile, $actual);
        }

        self::assertStringEqualsFile($expectedFile, $actual, \sprintf(
            'Fixture "%s" does not match. Run "UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --testsuite transform" to regenerate, then review the diff.',
            basename($dir),
        ));
    }

    public static function fixtureProvider(): iterable
    {
        foreach (glob(__DIR__ . '/fixtures/*', \GLOB_ONLYDIR) as $dir) {
            yield basename($dir) => [$dir];
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function loadContexts(string $contextsDir): array
    {
        $contexts = [];

        foreach (glob($contextsDir . '/*', \GLOB_ONLYDIR) ?: [] as $contextDir) {
            $name = basename($contextDir);
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($contextDir, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                $relative = substr($file->getPathname(), \strlen($contextDir) + 1);
                $contexts[$name][$relative] = file_get_contents($file->getPathname());
            }
        }

        return $contexts;
    }

    public function testBuildArgsFromOptions(): void
    {
        $args = DockerfileTransformer::buildArgsFromOptions([
            'filename' => 'Dockerfile',
            'target' => 'frontend',
            'build-arg:name' => 'hello world',
            'build-arg:php_version' => '8.4',
            'build-arg:config' => '{"a": 1}',
        ]);

        self::assertSame('hello world', $args['name'], 'Non-JSON values are kept as strings.');
        self::assertSame(8.4, $args['php_version'], 'Numeric values are JSON-decoded (historical behavior).');
        self::assertSame(['a' => 1], $args['config'], 'JSON values are decoded to arrays.');
        self::assertArrayNotHasKey('filename', $args);
        self::assertArrayNotHasKey('target', $args);
    }

    public function testStripLeadingCommentsOnlyRemovesTheLeadingBlock(): void
    {
        $dockerfile = <<<'DOCKERFILE'
            # syntax=ghcr.io/castor-php/twig-dockerfile:latest
            # hadolint global ignore=DL3008
            FROM alpine:3

            # this comment must survive
            RUN echo ok
            DOCKERFILE;

        $stripped = DockerfileTransformer::stripLeadingComments($dockerfile);

        self::assertStringStartsWith('FROM alpine:3', $stripped);
        self::assertStringContainsString('# this comment must survive', $stripped);
        self::assertStringNotContainsString('# syntax=', $stripped);
        self::assertStringNotContainsString('hadolint', $stripped);
    }
}
