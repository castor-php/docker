<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Builder;

use Castor\Context;

final class BuildBuilder
{
    /**
     * Pinned to the MAJOR.MINOR this release is tested against: the frontend
     * runs inside the build, so an unpinned reference would let a change in
     * https://github.com/castor-php/twig-dockerfile alter existing projects.
     *
     * Override it per project with the "twig_dockerfile_frontend" context data.
     */
    public const TWIG_DOCKERFILE_FRONTEND = 'ghcr.io/castor-php/twig-dockerfile:0.1';

    private ?string $context = null;
    private ?string $dockerfile = null;
    private ?string $target = null;
    /** @var array<string, ?string> */
    private array $args = [];
    /** @var array<string, string> */
    private array $additionalContexts = [];
    /** @var array<string> */
    private array $cacheFrom = [];
    /** @var array<string> */
    private array $cacheTo = [];

    public function __construct(private readonly ServiceBuilder $serviceBuilder) {}

    public function clone(ServiceBuilder $serviceBuilder): self
    {
        $new = new self($serviceBuilder);
        $new->context = $this->context;
        $new->dockerfile = $this->dockerfile;
        $new->target = $this->target;
        $new->args = $this->args;
        $new->additionalContexts = $this->additionalContexts;
        $new->cacheFrom = $this->cacheFrom;
        $new->cacheTo = $this->cacheTo;

        return $new;
    }

    public function context(string $context): self
    {
        $this->context = $context;

        return $this;
    }

    public function dockerfile(string $dockerfile): self
    {
        $this->dockerfile = $dockerfile;

        return $this;
    }

    public function target(string $target): self
    {
        $this->target = $target;

        return $this;
    }

    /**
     * BuildKit honours BUILDKIT_SYNTAX over the "# syntax=" directive of the
     * file, so a custom Dockerfile written by the project is pinned too.
     */
    public function useTwigFrontend(Context $context): self
    {
        $this->args['BUILDKIT_SYNTAX'] = $context->data['twig_dockerfile_frontend'] ?? self::TWIG_DOCKERFILE_FRONTEND;

        return $this;
    }

    public function arg(string $key, ?string $value): self
    {
        $this->args[$key] = $value;

        return $this;
    }

    public function additionalContext(string $name, string $path): self
    {
        $this->additionalContexts[$name] = $path;

        return $this;
    }

    public function cacheFrom(string $image): self
    {
        $this->cacheFrom[] = $image;

        return $this;
    }

    public function noCacheFrom(): self
    {
        $this->cacheFrom = [];
        $this->cacheTo = [];

        return $this;
    }

    public function withRegistryCache(string $image): self
    {
        $this->cacheFrom = ['type=registry,ref=${REGISTRY:-}/' . $image . ':cache'];
        $this->cacheTo = [];

        // BuildKit silently misses layers of a registry cache on CI
        // (jolicode/docker-starter#430), the GitHub Actions one backs it up.
        // Every build writes it: GitHub keeps what a pull request writes to
        // that pull request, whose next runs reuse it.
        if ('true' === getenv('GITHUB_ACTIONS')) {
            $this->cacheFrom[] = 'type=gha,scope=' . $image;
            $this->cacheTo = ['type=gha,scope=' . $image . ',mode=max,ignore-error=true'];
        }

        return $this;
    }

    public function end(): ServiceBuilder
    {
        return $this->serviceBuilder;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $build = [];

        if ($this->context !== null) {
            $build['context'] = $this->context;
        }

        if ($this->dockerfile !== null) {
            $build['dockerfile'] = $this->dockerfile;
        }

        if ($this->target !== null) {
            $build['target'] = $this->target;
        }

        if (!empty($this->args)) {
            $build['args'] = $this->args;
        }

        if (!empty($this->additionalContexts)) {
            $build['additional_contexts'] = $this->additionalContexts;
        }

        if (!empty($this->cacheFrom)) {
            $build['cache_from'] = $this->cacheFrom;
        }

        if (!empty($this->cacheTo)) {
            $build['cache_to'] = $this->cacheTo;
        }

        return $build;
    }
}
