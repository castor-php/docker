<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Renders the Dockerfiles shipped by the plugin the way the twig-dockerfile
 * BuildKit frontend does, so a template error surfaces here instead of in a
 * user's build.
 *
 * The environment mirrors the frontend's: no autoescaping (a Dockerfile is not
 * HTML), and neither trim_blocks nor lstrip_blocks — a block tag therefore
 * leaves its newline behind, which is harmless in a Dockerfile but means the
 * templates must not rely on either being on.
 *
 * @see https://github.com/castor-php/twig-dockerfile
 */
final class DockerfileTemplateTest extends TestCase
{
    private const RESOURCES = __DIR__ . '/../../../src/Resources';

    /**
     * @param array<string, mixed> $args
     */
    private function render(string $path, array $args): string
    {
        $source = file_get_contents(self::RESOURCES . '/' . $path);
        \assert($source !== false);

        // The frontend strips the leading "# syntax=" comment block before
        // handing the template to Twig.
        $lines = explode("\n", $source);
        $offset = 0;

        while ($offset < \count($lines) && str_starts_with(trim($lines[$offset]), '#')) {
            ++$offset;
        }

        $twig = new Environment(new ArrayLoader(['Dockerfile' => implode("\n", \array_slice($lines, $offset))]), [
            'cache' => false,
            'autoescape' => false,
        ]);

        return $twig->render('Dockerfile', $args);
    }

    /**
     * The arguments RustService and RustBuilder pass, after the frontend has
     * JSON-decoded them.
     *
     * @return array<string, mixed>
     */
    private function rustArgs(): array
    {
        return [
            'rust_version' => '1.90',
            'rust_components' => ['clippy', 'rustfmt'],
            'rust_targets' => [],
            'rust_toolchains' => [],
        ];
    }

    public function testRustDefaults(): void
    {
        $dockerfile = $this->render('rust/Dockerfile', $this->rustArgs());

        // The version reaches FROM through the Docker ARG, never through Twig:
        // "1.90" JSON-decodes to the number 1.9 and would pull the wrong image.
        static::assertStringContainsString('ARG rust_version=1', $dockerfile);
        static::assertStringContainsString('FROM rust:${rust_version} AS rust-base', $dockerfile);
        static::assertStringContainsString('RUN rustup component add clippy rustfmt', $dockerfile);
        static::assertStringContainsString('WORKDIR /app', $dockerfile);
        static::assertStringContainsString('FROM rust-base AS runtime', $dockerfile);

        static::assertStringNotContainsString('rustup target add', $dockerfile);
        static::assertStringNotContainsString('rustup toolchain install', $dockerfile);
    }

    public function testRustTargetsAndToolchains(): void
    {
        $dockerfile = $this->render('rust/Dockerfile', [
            ...$this->rustArgs(),
            'rust_targets' => ['x86_64-unknown-linux-musl', 'wasm32-unknown-unknown'],
            'rust_toolchains' => [
                ['name' => 'nightly', 'components' => ['rustfmt', 'clippy']],
                ['name' => 'beta', 'components' => []],
            ],
        ]);

        static::assertStringContainsString('RUN rustup target add x86_64-unknown-linux-musl wasm32-unknown-unknown', $dockerfile);
        static::assertStringContainsString('RUN rustup toolchain install nightly --component rustfmt --component clippy', $dockerfile);
        static::assertStringContainsString('RUN rustup toolchain install beta', $dockerfile);

        // Each toolchain must be its own RUN: the templates must not depend on
        // trim_blocks, which would swallow the newline and merge them.
        static::assertStringContainsString("--component clippy\nRUN rustup toolchain install beta", $dockerfile);
    }

    /**
     * A project that overrides a block must keep the rest of the image.
     */
    public function testRustBlocksAreExtensible(): void
    {
        $source = file_get_contents(self::RESOURCES . '/rust/Dockerfile');
        \assert($source !== false);

        $twig = new Environment(new ArrayLoader([
            'Dockerfile' => preg_replace('/^#.*\n/m', '', $source),
            'project' => <<<'TWIG'
                {% extends 'Dockerfile' %}
                {% block rust_base %}
                {{ parent() }}
                RUN apt-get update && apt-get install -y --no-install-recommends musl-tools
                {% endblock %}
                TWIG,
        ]), ['cache' => false, 'autoescape' => false]);

        $dockerfile = $twig->render('project', $this->rustArgs());

        static::assertStringContainsString('RUN rustup component add clippy rustfmt', $dockerfile, 'parent() must keep the shipped content.');
        static::assertStringContainsString('musl-tools', $dockerfile);
        static::assertStringContainsString('FROM rust-base AS runtime', $dockerfile);
    }

    public function testGoDefaults(): void
    {
        $dockerfile = $this->render('go/Dockerfile', ['go_version' => '1.25']);

        static::assertStringContainsString('ARG go_version=1', $dockerfile);
        static::assertStringContainsString('FROM golang:${go_version} AS go-base', $dockerfile);
        static::assertStringContainsString('WORKDIR /app', $dockerfile);
        static::assertStringContainsString('FROM go-base AS runtime', $dockerfile);
    }

    /**
     * The document root of the PHP frontends is baked into the image, so it has
     * to follow withWorkingDirectory() — otherwise a monorepo application
     * mounting the repository root serves a /var/www/public that does not
     * exist. Absent, it must keep producing the historical value.
     */
    public function testPhpDocumentRootFollowsTheApplicationDirectory(): void
    {
        static::assertStringContainsString('root * /var/www/public', $this->render('php/frontend-frankenphp/Caddyfile.twig', []));
        static::assertStringContainsString('root /var/www/public;', $this->render('php/frontend/etc/nginx/nginx.conf.twig', []));

        static::assertStringContainsString(
            'root * /var/www/apps/backend/public',
            $this->render('php/frontend-frankenphp/Caddyfile.twig', ['app_root' => '/var/www/apps/backend']),
        );
        static::assertStringContainsString(
            'root /var/www/apps/backend/public;',
            $this->render('php/frontend/etc/nginx/nginx.conf.twig', ['app_root' => '/var/www/apps/backend']),
        );
    }

    /**
     * Undefined variables must not break the render: a project pointing the
     * service at its own Dockerfile may not pass every argument.
     */
    public function testTemplatesRenderWithoutArguments(): void
    {
        static::assertStringContainsString('FROM rust:${rust_version}', $this->render('rust/Dockerfile', []));
        static::assertStringContainsString('FROM golang:${go_version}', $this->render('go/Dockerfile', []));
    }
}
