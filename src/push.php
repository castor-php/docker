<?php

declare(strict_types=1);

namespace Castor\Docker;

use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Process;

use function Castor\context;
use function Castor\io;
use function Castor\parallel;
use function Castor\run;
use function Castor\variable;

#[AsTask(description: 'Push the images and their build cache to the registry', namespace: 'docker', name: 'push', aliases: ['push'])]
function push(
    #[AsOption(description: 'Print the bake plan instead of building and pushing')]
    bool $dryRun = false,
    #[AsOption(description: 'The tag the images are published under')]
    string $tag = 'latest',
    #[AsOption(description: 'Build each target on its own, in parallel, so targets sharing a stage keep their build cache', mode: InputOption::VALUE_NONE | InputOption::VALUE_NEGATABLE)]
    ?bool $parallel = null,
): void {
    $registry = variable('registry');

    if (!$registry) {
        throw new \RuntimeException('You must define a registry to push images.');
    }

    // Only a service declaring a cache_from has somewhere to push its build
    // cache back to, and it goes back to every one of them.
    $targets = [];

    foreach (get_resolved_services(['*']) as $service => $config) {
        $cacheFrom = $config['build']['cache_from'] ?? [];

        if ($cacheFrom) {
            $targets[$service] = array_map(normalize_cache_entry(...), $cacheFrom);
        }
    }

    if (!$targets) {
        throw new \RuntimeException('No service declares a build cache, there is nothing to push.');
    }

    $source = get_source_url();

    // A cache manifest carries no label, so ghcr.io only learns where an image
    // comes from from the image pushed next to it. Without it the package is
    // an orphan only its creator may write to, locking the CI out of its cache.
    if (null === $source && str_starts_with($registry, 'ghcr.io/')) {
        throw new \RuntimeException('Could not tell which repository these images come from, and ghcr.io needs it to attach the packages to it. Add a "repository" variable to your context, holding either "org/project" or the full URL of the repository.');
    }

    $revision = get_source_revision();

    $c = context();

    // bake reads the compose file itself — "include:" and all — so everything
    // but the cache-to comes from there, already interpolated. Profiles do not
    // apply: bake sees every service that has a "build".
    $command = ['docker', 'buildx', 'bake', '-f', $c->workingDirectory . '/compose.yaml'];

    $options = [];

    foreach ($targets as $service => $cacheEntries) {
        $images = [];
        $options[$service] = [];

        foreach ($cacheEntries as $cacheTo) {
            $cacheRef = get_cache_reference($cacheTo);

            $options[$service][] = '--set';
            $options[$service][] = \sprintf('%s.cache-to=%s,mode=max', $service, $cacheTo);

            // "type=gha" or "type=local" names no repository to publish an
            // image to.
            if (null === $cacheRef) {
                continue;
            }

            $image = get_image_reference($cacheRef, $tag);

            if ($image === $cacheRef) {
                throw new \RuntimeException(\sprintf('Pushing "%s" under the tag "%s" would overwrite the build cache of "%s". Pick another --tag.', $image, $tag, $service));
            }

            $images[] = $image;
        }

        // A service whose caches all live outside of a registry pushes its
        // cache and nothing else.
        if (!$images) {
            continue;
        }

        foreach ($images as $image) {
            $options[$service][] = '--set';
            $options[$service][] = \sprintf('%s.tags=%s', $service, $image);
        }

        // Per target, rather than a global "--push": the services whose cache
        // is not a registry one must not be pushed anywhere.
        $options[$service][] = '--set';
        $options[$service][] = \sprintf('%s.output=type=registry', $service);
        if (null !== $source) {
            $options[$service][] = '--set';
            $options[$service][] = \sprintf('%s.labels.org.opencontainers.image.source=%s', $service, $source);
        }

        if (null !== $revision) {
            $options[$service][] = '--set';
            $options[$service][] = \sprintf('%s.labels.org.opencontainers.image.revision=%s', $service, $revision);
        }
    }

    $c = $c->withEnvironment([
        // bake does not go through docker_compose(), so the variables the
        // generated compose file interpolates have to be given to it here.
        'COMPOSE_PROJECT_NAME' => get_project_name($c),
        'PROJECT_NAME' => get_project_name($c),
        'REGISTRY' => $registry,
        // The build contexts live in the plugin, outside of the project
        // directory, which bake asks to confirm on every run otherwise.
        'BUILDX_BAKE_ENTITLEMENTS_FS' => '0',
    ]);

    // Naming the targets keeps out the services that build without a cache:
    // bake's default group is every buildable service of the project.
    if ($dryRun || !is_build_parallel($parallel)) {
        run([...$command, ...array_merge(...array_values($options)), ...($dryRun ? ['--print'] : []), ...array_keys($targets)], context: $c);

        return;
    }

    // One bake per target, for the reason build() gives.
    $builds = [];

    foreach (array_keys($targets) as $service) {
        $builds[$service] = static fn(): Process => run([...$command, ...$options[$service], '--progress', 'plain', $service], context: $c);
    }

    parallel(...$builds);
}

/**
 * A compose "cache_from" accepts a bare image reference, but
 * "--set <target>.cache-to=" only understands a "type=...,ref=..." entry.
 */
function normalize_cache_entry(string $cacheFrom): string
{
    foreach (explode(',', $cacheFrom) as $field) {
        if (str_starts_with($field, 'type=')) {
            return $cacheFrom;
        }
    }

    return 'type=registry,ref=' . $cacheFrom;
}

/**
 * Null for a cache that lives outside of a registry.
 */
function get_cache_reference(string $cacheEntry): ?string
{
    $ref = null;

    foreach (explode(',', $cacheEntry) as $field) {
        if ('type=registry' === $field) {
            continue;
        }

        if (str_starts_with($field, 'type=')) {
            return null;
        }

        if (str_starts_with($field, 'ref=')) {
            $ref = substr($field, 4);
        }
    }

    return '' === $ref ? null : $ref;
}

/**
 * The same repository as the cache, under another tag: one package holds them
 * both, and linking that package is what the image is pushed for.
 */
function get_image_reference(string $cacheRef, string $tag): string
{
    $repository = explode('@', $cacheRef)[0];
    $colon = strrpos($repository, ':');

    // The colon of a "registry:5000/image" is the port, not a tag.
    if (false !== $colon && $colon > (strrpos($repository, '/') ?: 0)) {
        $repository = substr($repository, 0, $colon);
    }

    return $repository . ':' . $tag;
}

/**
 * Where the images come from, as "org.opencontainers.image.source" spells it.
 *
 * GitHub attaches the package to the repository this names, and the package
 * inherits its permissions: everyone who may push to the repository may push
 * its images, not only whoever pushed them first.
 */
function get_source_url(): ?string
{
    $repository = variable('repository', '');

    if ('' !== $repository) {
        return normalize_source_url($repository);
    }

    $repository = getenv('GITHUB_REPOSITORY');

    if (\is_string($repository) && '' !== $repository) {
        $server = getenv('GITHUB_SERVER_URL') ?: 'https://github.com';

        return rtrim($server, '/') . '/' . $repository;
    }

    $remote = capture_git(['git', 'remote', 'get-url', 'origin']);

    return null === $remote ? null : normalize_source_url($remote);
}

function get_source_revision(): ?string
{
    $revision = getenv('GITHUB_SHA');

    if (\is_string($revision) && '' !== $revision) {
        return $revision;
    }

    return capture_git(['git', 'rev-parse', 'HEAD']);
}

/**
 * Turn any remote form — scp-like, ssh://, https://, "org/project" — into the
 * browsable URL GitHub expects.
 */
function normalize_source_url(string $remote): ?string
{
    $remote = trim($remote);

    if ('' === $remote) {
        return null;
    }

    if (preg_match('#^ssh://(?:[^@/]+@)?(.+)$#', $remote, $matches)) {
        $remote = 'https://' . $matches[1];
    } elseif (preg_match('#^(?:[\w.-]+@)?([\w.-]+):(?!//)(.+)$#', $remote, $matches)) {
        $remote = 'https://' . $matches[1] . '/' . ltrim($matches[2], '/');
    } elseif (preg_match('#^[\w.-]+/[\w.-]+$#', $remote)) {
        $remote = 'https://github.com/' . $remote;
    }

    return preg_replace('#\.git$#', '', $remote);
}

/**
 * @param list<string> $command
 */
function capture_git(array $command): ?string
{
    $output = quiet_capture($command);

    return '' === $output ? null : $output;
}
