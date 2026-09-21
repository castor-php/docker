<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Behaviour;

use Castor\Context;

use function Castor\Docker\shared_home_directory;

/**
 * For services mounting the project's shared home directory, where the caches
 * shared by every service live (composer, cargo, mkcert CA, …).
 */
trait HasSharedHomeDirectory
{
    protected string $sharedHomeDirectory = '.home';

    public function withSharedHomeDirectory(string $directory): static
    {
        $this->sharedHomeDirectory = $directory;

        return $this;
    }

    /**
     * Pass the context the compose file is generated with to get the directory
     * as it should be mounted: a git worktree shares the one of the main
     * checkout, so its caches are not cold (see shared_home_directory()).
     */
    public function getSharedHomeDirectory(?Context $c = null): string
    {
        return null === $c ? $this->sharedHomeDirectory : shared_home_directory($this->sharedHomeDirectory, $c);
    }
}
