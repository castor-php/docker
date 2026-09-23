<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Behaviour;

/**
 * For services whose image (or toolchain) is versioned. Each service declares
 * the version it defaults to.
 */
trait HasVersion
{
    protected ?string $version = null;

    public function withVersion(string $version): static
    {
        $this->version = $version;

        return $this;
    }

    public function getVersion(): string
    {
        return $this->version ?? $this->getDefaultVersion();
    }

    /**
     * "18.4" for "18.4-alpine", null for "latest".
     */
    protected function getVersionNumber(): ?string
    {
        return preg_match('/^\d+(?:\.\d+)*/', $this->getVersion(), $matches) ? $matches[0] : null;
    }

    abstract protected function getDefaultVersion(): string;
}
