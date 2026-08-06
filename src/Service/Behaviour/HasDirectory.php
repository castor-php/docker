<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Behaviour;

/**
 * For application services whose sources are mounted from a directory of the
 * project.
 *
 * What is mounted and where the commands run are two different things: in a
 * monorepo the mount is the repository root — so an application can read a
 * directory produced by another one — while the crate, module or application
 * the service is about sits somewhere below it. withWorkingDirectory() names
 * that sub-directory, relative to the mount.
 */
trait HasDirectory
{
    protected ?string $directory = null;

    protected string $workingDirectory = '.';

    public function withDirectory(string $directory): static
    {
        $this->directory = $directory;

        return $this;
    }

    public function getDirectory(): string
    {
        return $this->directory ?? '.';
    }

    /**
     * The directory the commands of this service run in, relative to the
     * mounted directory. Defaults to the mount itself.
     */
    public function withWorkingDirectory(string $workingDirectory): static
    {
        $this->workingDirectory = $workingDirectory;

        return $this;
    }

    public function getWorkingDirectory(): string
    {
        return $this->workingDirectory;
    }

    /**
     * Whether withDirectory() was called, so a service inheriting its mount
     * from somewhere else (a toolchain, a shared builder) can tell.
     */
    protected function isDirectoryDefined(): bool
    {
        return $this->directory !== null;
    }

    /**
     * The working directory as seen from the host: what the tasks running
     * outside of a container (the PHP QA tools, for instance) should use.
     */
    protected function getHostWorkingDirectory(): string
    {
        return $this->joinPath($this->getDirectory(), $this->workingDirectory);
    }

    /**
     * The working directory as seen from inside the container, below the given
     * mount point.
     */
    protected function getContainerWorkingDirectory(string $mountPoint): string
    {
        return $this->joinPath($mountPoint, $this->workingDirectory);
    }

    /**
     * Append a relative path to a base one, leaving the base untouched when the
     * relative path is "." — so the default keeps producing "/app" and not
     * "/app/.".
     */
    protected function joinPath(string $base, string $path): string
    {
        $path = trim($path, '/');

        if ('' === $path || '.' === $path) {
            return $base;
        }

        return rtrim($base, '/') . '/' . $path;
    }
}
