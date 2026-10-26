<?php

declare(strict_types=1);

namespace docs;

use Castor\Attribute\AsTask;
use Symfony\Component\Process\Process;

use function Castor\context;
use function Castor\fs;
use function Castor\http_download;
use function Castor\io;
use function Castor\run;

const IMAGE_NAME = 'castor-docker-mkdocs';

#[AsTask(description: 'Build the MkDocs Docker image', namespace: 'docs', name: 'image')]
function image(bool $force = false): void
{
    if (!$force && has_image()) {
        io()->note(\sprintf('Image "%s" already exists, use --force to rebuild it.', IMAGE_NAME));

        return;
    }

    io()->title('Building the MkDocs Docker image');

    run(['docker', 'build', '-t', IMAGE_NAME, __DIR__]);
}

#[AsTask(description: 'Fetch the JoliCode theme assets used by the documentation', namespace: 'docs', name: 'assets')]
function assets(): void
{
    io()->title('Fetching external assets for the MkDocs documentation');

    http_download(
        'https://raw.githubusercontent.com/jolicode/oss-theme/refs/heads/main/MkDocs/extra.css',
        root() . '/doc/assets/stylesheets/jolicode.css',
    );
    http_download(
        'https://raw.githubusercontent.com/jolicode/oss-theme/refs/heads/main/snippet-joli-footer.html',
        __DIR__ . '/overrides/partials/jolicode-footer.html',
    );

    $subtitle = <<<'HTML'
        The Castor Docker plugin is licensed under
        <a href="https://github.com/castor-php/docker/blob/main/LICENSE" target="_blank" rel="noreferrer noopener" class="jf-link">
          MIT license
        </a>
        HTML;

    $footer = file_get_contents(__DIR__ . '/overrides/partials/jolicode-footer.html');
    \assert($footer !== false);

    $footer = str_replace('#GITHUB_REPO', 'castor-php/docker', $footer);
    $footer = str_replace('<!-- #SUBTITLE -->', $subtitle, $footer);

    file_put_contents(__DIR__ . '/overrides/partials/jolicode-footer.html', $footer);
}

#[AsTask(description: 'Build the documentation website', namespace: 'docs', name: 'build')]
function build(): void
{
    assets();

    io()->title('Building the MkDocs documentation');

    do_run(['mkdocs', 'build']);

    io()->success(\sprintf('Documentation built in %s/site.', __DIR__));
}

#[AsTask(description: 'Serve the documentation and rebuild it on every change', namespace: 'docs', name: 'serve')]
function serve(int $port = 8000): void
{
    assets();

    io()->title('Serving the MkDocs documentation');
    io()->writeln(\sprintf('  <info>http://127.0.0.1:%d</info>', $port));

    do_run(
        ['mkdocs', 'serve', '--livereload', '--dev-addr', '0.0.0.0:8000'],
        ports: [\sprintf('%d:8000', $port)],
    );
}

function root(): string
{
    $root = realpath(__DIR__ . '/../..');
    \assert($root !== false);

    return $root;
}

function has_image(): bool
{
    return run(
        ['docker', 'image', 'inspect', IMAGE_NAME],
        context: context()->withAllowFailure()->withQuiet(),
    )->isSuccessful();
}

/**
 * @param list<string> $command
 * @param list<string> $ports
 */
function do_run(array $command, array $ports = []): Process
{
    if (!has_image()) {
        image();
    }

    $userId = getmyuid();
    $groupId = getmygid();

    $arguments = [
        'docker', 'run', '--rm', '--init',
        '--user', "{$userId}:{$groupId}",
        // The image installs MkDocs system-wide, but the social plugin wants a
        // writable home for its font cache.
        '--env', 'HOME=/tmp',
        '--volume', \sprintf('%s:/mkdocs:cached', __DIR__),
        // Mounted next to the docs so pages can include them, see the
        // "include-markdown" directives in doc/.
        '--volume', \sprintf('%s:/mkdocs/doc:cached', root() . '/doc'),
        '--volume', \sprintf('%s:/mkdocs/README.md:cached', root() . '/README.md'),
        '--volume', \sprintf('%s:/mkdocs/CHANGELOG.md:cached', root() . '/CHANGELOG.md'),
        '--volume', \sprintf('%s:/mkdocs/example:cached', root() . '/example'),
    ];

    foreach ($ports as $port) {
        $arguments[] = '--publish';
        $arguments[] = $port;
    }

    if (context()->tty) {
        $arguments[] = '--tty';
    }

    $arguments[] = IMAGE_NAME;

    return run([...$arguments, ...$command], context: context()->withTimeout(null));
}
