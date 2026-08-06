<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Behaviour;

/**
 * For services built from a Dockerfile shipped by the plugin, which the project
 * may replace with its own.
 *
 * The default is resolved lazily, so it can depend on other options set after
 * the service was created.
 */
trait HasDockerfile
{
    protected ?string $dockerFile = null;

    public function withDockerfile(string $path): static
    {
        $this->dockerFile = $path;

        return $this;
    }

    public function getDockerfile(): string
    {
        return $this->dockerFile ?? $this->getDefaultDockerfile();
    }

    abstract protected function getDefaultDockerfile(): string;
}
