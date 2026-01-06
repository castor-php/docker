<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Builder;

final class BuildBuilder
{
    private ?string $context = null;
    private ?string $dockerfile = null;
    private ?string $target = null;
    /** @var array<string, string> */
    private array $args = [];
    /** @var array<string, string> */
    private array $additionalContexts = [];
    /** @var array<string> */
    private array $cacheFrom = [];

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

    public function arg(string $key, string $value): self
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

        return $build;
    }
}
