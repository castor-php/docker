<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

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
     * A Dockerfile without its leading "# syntax=" comment block, which is how
     * the frontend hands the file it builds to Twig. The ones it extends are
     * read the same way here, because a template that extends another cannot
     * hold content outside a block and "# syntax=" is content.
     */
    private function source(string $path): string
    {
        $source = file_get_contents($path);
        \assert($source !== false);

        $lines = explode("\n", $source);
        $offset = 0;

        while ($offset < \count($lines) && str_starts_with(trim($lines[$offset]), '#')) {
            ++$offset;
        }

        return implode("\n", \array_slice($lines, $offset));
    }

    /**
     * An environment loading what the build context of $directory holds, plus
     * the templates given — the entry Dockerfile, and whatever a project would
     * ship of its own.
     *
     * @param array<string, string> $templates
     */
    private function environment(string $directory, array $templates): Environment
    {
        // A Dockerfile extending another one resolves it in the build context,
        // and needs it stripped the same way; anything else — a configuration
        // template rendered with copy() — is read as it is.
        foreach (glob($directory . '/Dockerfile*') ?: [] as $file) {
            $templates[basename($file)] ??= $this->source($file);
        }

        $twig = new Environment(new ChainLoader([
            new ArrayLoader($templates),
            new FilesystemLoader($directory),
        ]), ['cache' => false, 'autoescape' => false]);

        $twig->addFunction(new TwigFunction('copy', static fn(string ...$a): string => 'COPY ' . implode(' ', $a)));

        return $twig;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function render(string $path, array $args): string
    {
        $directory = \dirname(self::RESOURCES . '/' . $path);

        return $this
            ->environment($directory, ['entry' => $this->source(self::RESOURCES . '/' . $path)])
            ->render('entry', $args)
        ;
    }

    /**
     * The arguments PHPService passes to a FrankenPHP application.
     *
     * @return array<string, mixed>
     */
    private function phpArgs(): array
    {
        return [
            'php_version' => '8.5',
            'php_extensions' => ['apcu', 'pgsql', 'pdo_pgsql'],
            'node_version' => '24.x',
            'package_manager' => 'npm',
        ];
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
     * A project that overrides a block must keep the rest of the image. The
     * shipped template is read as it is: it holds nothing a project extending
     * it would trip on.
     */
    public function testRustBlocksAreExtensible(): void
    {
        $dockerfile = $this
            ->environment(self::RESOURCES . '/rust', ['project' => <<<'TWIG'
                {% extends 'Dockerfile' %}
                {% block rust_base %}
                {{ parent() }}
                RUN apt-get update && apt-get install -y --no-install-recommends musl-tools
                {% endblock %}
                TWIG])
            ->render('project', $this->rustArgs())
        ;

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
     * The CLI of the builder and of the workers has to be the binary FrankenPHP
     * serves with: two PHP installations meant an extension, a version or an
     * ini file could be in one and missing from the other — which is invisible
     * until a command works and the page it prepares does not.
     */
    public function testFrankenPhpBuildsEveryStageOnItsOwnImage(): void
    {
        $dockerfile = $this->render('php/Dockerfile.frankenphp', $this->phpArgs());

        static::assertStringContainsString('FROM dunglas/frankenphp:php8.5 AS php-base', $dockerfile);
        static::assertStringContainsString('FROM php-base AS frontend', $dockerfile);
        static::assertStringContainsString('FROM php-base AS worker', $dockerfile);
        static::assertStringContainsString('FROM php-base AS builder', $dockerfile);

        // The Debian stack of PhpMode::Fpm would be a second PHP next to it.
        static::assertStringNotContainsString('packages.sury.org', $dockerfile);
        static::assertStringNotContainsString('php8.5-cli', $dockerfile);
        static::assertStringNotContainsString('php8.5-dev', $dockerfile);
        static::assertStringNotContainsString('RUN phpenmod', $dockerfile);
    }

    /**
     * Installed once, in the stage every other one is built on.
     */
    public function testFrankenPhpInstallsTheExtensionsOnlyInTheSharedStage(): void
    {
        $dockerfile = $this->render('php/Dockerfile.frankenphp', $this->phpArgs());

        static::assertSame(1, substr_count($dockerfile, 'install-php-extensions'));
        static::assertStringContainsString('RUN install-php-extensions apcu pgsql pdo_pgsql', $dockerfile);

        static::assertStringNotContainsString(
            'install-php-extensions',
            substr($dockerfile, (int) strpos($dockerfile, 'FROM php-base AS frontend')),
            'The extensions belong to php-base, not to a stage built on it.',
        );
    }

    /**
     * The image serves by default, and the containers built on it run commands:
     * "docker:up --profiles builder" would otherwise start a web server in the
     * builder instead of letting it exit.
     */
    public function testOnlyTheFrankenPhpFrontendServes(): void
    {
        $dockerfile = $this->render('php/Dockerfile.frankenphp', $this->phpArgs());

        static::assertStringContainsString("CMD [\"bash\"]", $dockerfile);
        static::assertStringContainsString('CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"', $dockerfile);
        static::assertTrue(
            strpos($dockerfile, 'CMD ["bash"]') < strpos($dockerfile, 'FROM php-base AS frontend'),
            'php-base has to be the one dropping the server command.',
        );
    }

    /**
     * The ini files the plugin ships are enabled with phpenmod on Debian, and
     * by their name in the conf.d of the official image. Landing in the wrong
     * place is silent: PHP simply never reads them.
     */
    public function testFrankenPhpEnablesTheShippedIniFilesTheWayItsImageDoes(): void
    {
        $dockerfile = $this->render('php/Dockerfile.frankenphp', $this->phpArgs());

        static::assertStringContainsString('/usr/local/etc/php/conf.d/30-app-default.ini', $dockerfile);
        static::assertStringContainsString('/usr/local/etc/php/conf.d/40-app-builder.ini', $dockerfile);
    }

    /**
     * The FPM mode keeps the Debian stack, extensions included.
     */
    public function testFpmStaysOnTheDebianPackages(): void
    {
        $dockerfile = $this->render('php/Dockerfile', [...$this->phpArgs(), 'php_extensions' => ['apcu', 'pgsql']]);

        static::assertStringContainsString('FROM debian:', $dockerfile);
        static::assertStringContainsString('"php8.5-pgsql" \\', $dockerfile);
        static::assertStringContainsString('"php8.5-dev" \\', $dockerfile);
        static::assertStringContainsString('RUN phpenmod app-default', $dockerfile);
        static::assertStringNotContainsString('install-php-extensions', $dockerfile);
    }

    /**
     * The builder installs node from the NodeSource repository in both modes,
     * and the FrankenPHP image carries no gnupg to dearmour a key with.
     */
    public function testTheNodeRepositoryKeyNeedsNoGnupg(): void
    {
        foreach (['php/Dockerfile', 'php/Dockerfile.frankenphp'] as $path) {
            $dockerfile = $this->render($path, $this->phpArgs());

            static::assertStringContainsString('nodesource.asc', $dockerfile, $path);
            static::assertStringContainsString('node_24.x nodistro', $dockerfile, $path);
            static::assertStringNotContainsString('gpg --dearmor', $dockerfile, $path);
        }
    }

    /**
     * A project extends the file of its mode and overrides one block. The
     * FrankenPHP one is itself a child of the Debian one, so a third level has
     * to keep working — including {{ parent() }} on a block that file overrides
     * and on one it does not.
     */
    public function testAProjectCanExtendTheFrankenPhpDockerfile(): void
    {
        $dockerfile = $this
            ->environment(self::RESOURCES . '/php', ['project' => <<<'TWIG'
                {% extends 'Dockerfile.frankenphp' %}
                {% block php_base %}
                {{ parent() }}
                RUN apt-get update && apt-get install -y --no-install-recommends poppler-utils
                {% endblock %}
                {% block builder %}
                {{ parent() }}
                RUN echo "custom builder step"
                {% endblock %}
                TWIG])
            ->render('project', $this->phpArgs())
        ;

        static::assertStringContainsString('FROM dunglas/frankenphp:php8.5 AS php-base', $dockerfile);
        static::assertStringContainsString('poppler-utils', $dockerfile);
        static::assertStringContainsString('RUN echo "custom builder step"', $dockerfile);

        // The inner blocks the FrankenPHP file overrides must survive a
        // {{ parent() }} on the block holding them.
        static::assertStringContainsString('COPY --from=composer/composer', $dockerfile);
        static::assertStringContainsString('/usr/local/etc/php/conf.d/40-app-builder.ini', $dockerfile);
        static::assertStringNotContainsString('php8.5-dev', $dockerfile);
    }

    /**
     * The templates shipped here carry no "# syntax=" directive: the frontend is
     * pinned by the BUILDKIT_SYNTAX build argument every generated service
     * passes, which BuildKit honours over the directive anyway.
     *
     * That line is text as far as Twig is concerned, and a template that
     * extends another one cannot hold text outside its blocks. Carrying it
     * therefore costs a template the ability to extend — and any project
     * extending such a template is rejected on line 1, which is the whole point
     * of shipping templates.
     */
    public function testNoShippedTemplateCarriesTheSyntaxDirective(): void
    {
        foreach (self::shippedTemplates() as $path => $source) {
            static::assertDoesNotMatchRegularExpression('/^#\s*syntax\s*=/m', $source, \sprintf(
                '"%s" pins the frontend itself; BUILDKIT_SYNTAX in the compose file is what does it.',
                $path,
            ));
        }
    }

    /**
     * The same rule seen from the other end: a template that extends must not
     * open with a comment, whichever one.
     */
    public function testNoShippedTemplateThatExtendsOpensWithAComment(): void
    {
        foreach (self::shippedTemplates() as $path => $source) {
            if (!str_contains($source, '{% extends')) {
                continue;
            }

            static::assertStringStartsNotWith('#', ltrim($source), \sprintf(
                '"%s" extends another template, so anything outside its blocks — a comment included — makes it unusable.',
                $path,
            ));
        }
    }

    /**
     * The Dockerfiles rendered by the frontend, which are the ones holding Twig
     * tags: the plain ones of the other services are built as they are.
     *
     * @return array<string, string> source indexed by path relative to Resources
     */
    private static function shippedTemplates(): array
    {
        $templates = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RESOURCES, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (!str_starts_with($file->getFilename(), 'Dockerfile')) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            \assert($source !== false);

            if (!str_contains($source, '{%')) {
                continue;
            }

            $templates[substr($file->getPathname(), \strlen(self::RESOURCES) + 1)] = $source;
        }

        static::assertNotEmpty($templates, 'No template found: the directory layout moved.');

        return $templates;
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
