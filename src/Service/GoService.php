<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;

/**
 * Runs a Go application from the source directory mounted in the container:
 * "go build" runs inside it and the binary becomes the container command.
 *
 * One module, one container. A monorepo building several binaries from one
 * toolchain wants GoBuilder and BinaryRunService instead.
 *
 * withDirectory() is what gets mounted, withWorkingDirectory() is where go runs
 * below it, and withBinaryPath() is the binary the container starts.
 */
class GoService extends AbstractSourceService
{
    protected ?string $binaryPath = null;

    protected function getDefaultVersion(): string
    {
        return '1';
    }

    protected function getDefaultDockerfile(): string
    {
        return __DIR__ . '/../Resources/go/Dockerfile';
    }

    protected function getDefaultPort(): int
    {
        return 8080;
    }

    /**
     * The binary the container runs, relative to the mounted directory.
     * Defaults to the service name.
     */
    public function withBinaryPath(string $binaryPath): static
    {
        $this->binaryPath = $binaryPath;

        return $this;
    }

    public function getBinaryPath(): string
    {
        return $this->binaryPath ?? $this->name;
    }

    /**
     * "go build -o <binary path>" unless withBuildCommand() replaces it.
     */
    public function getBuildCommand(): string|array
    {
        return $this->buildCommand ?? ['go', 'build', '-o', $this->getBinaryPath()];
    }

    public function getTasks(): iterable
    {
        yield from parent::getTasks();
        yield $this->testTask();
        yield $this->toolTask('go');
    }

    protected function getDefaultCommand(): string
    {
        return $this->joinPath(static::MOUNT_POINT, $this->getBinaryPath());
    }

    protected function getBuildArgs(): array
    {
        return ['go_version' => $this->getVersion()];
    }

    protected function isWatched(string $file): bool
    {
        return str_ends_with($file, '.go') || str_ends_with($file, '/go.mod') || str_ends_with($file, '/go.sum');
    }

    /**
     * Every package of the module unless told which.
     *
     * @return array{task: AsTask, function: \Closure}
     */
    protected function testTask(): array
    {
        return [
            'task' => new AsTask('test', $this->name, 'Run the test suite of the ' . $this->name . ' application'),
            'function' => function (#[AsRawTokens] array $args): void {
                $this->runInBuilder(['go', 'test', ...($args ?: ['./...'])]);
            },
        ];
    }
}
