<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Behaviour;

/**
 * For application services whose sources are mounted from a directory of the
 * project.
 */
trait HasDirectory
{
    private string $directory = '.';

    public function withDirectory(string $directory): static
    {
        $this->directory = $directory;

        return $this;
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }
}
