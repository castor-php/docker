<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Behaviour;

/**
 * For services mounting the project's shared home directory, where the caches
 * shared by every service live (composer, cargo, mkcert CA, …).
 */
trait HasSharedHomeDirectory
{
    private string $sharedHomeDirectory = '.home';

    public function withSharedHomeDirectory(string $directory): static
    {
        $this->sharedHomeDirectory = $directory;

        return $this;
    }

    public function getSharedHomeDirectory(): string
    {
        return $this->sharedHomeDirectory;
    }
}
