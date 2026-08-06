<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasDirectory;
use Castor\Docker\Service\Behaviour\HasDockerfile;
use Castor\Docker\Service\Behaviour\HasHttpRouting;
use Castor\Docker\Service\Behaviour\HasSharedHomeDirectory;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\Builder\ServiceBuilder;

use function Castor\context;
use function Castor\Docker\docker_compose;
use function Castor\Docker\docker_compose_run;
use function Castor\watch;

/**
 * Runs a Go application from the source directory mounted in the container:
 * "go build" happens inside the container and the resulting binary is used as
 * the container command.
 *
 * One module, one container: this is the single-application case. A monorepo
 * building several binaries from one toolchain wants GoBuilder and
 * BinaryRunService instead, which split the toolchain container from the
 * runtime ones.
 *
 * The paths still need not coincide: withDirectory() is what gets mounted,
 * withWorkingDirectory() is where go runs below it, and withBinaryPath() is the
 * binary the container starts.
 */
class GoService implements ServiceInterface
{
    use HasDirectory;
    use HasDockerfile;
    use HasHttpRouting;
    use HasSharedHomeDirectory;
    use HasVersion;

    protected const MOUNT_POINT = '/app';

    protected ?string $binaryPath = null;

    protected ?string $buildCommand = null;

    /** @var null|list<string>|string */
    protected array|string|null $runCommand = null;

    public function __construct(
        protected readonly string $name,
    ) {}

    protected function getDefaultVersion(): string
    {
        return '1';
    }

    protected function getDefaultDockerfile(): string
    {
        return __DIR__ . '/../Resources/go/Dockerfile';
    }

    public function getName(): string
    {
        return $this->name;
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

    /**
     * Replace the build command, "go build -o <binary path>" by default.
     */
    public function withBuildCommand(string $buildCommand): static
    {
        $this->buildCommand = $buildCommand;

        return $this;
    }

    /**
     * What the container runs. Given a list, the arguments are appended to the
     * binary; given a string, it replaces the container command outright.
     *
     * @param list<string>|string $runCommand
     */
    public function withRunCommand(array|string $runCommand): static
    {
        $this->runCommand = $runCommand;

        return $this;
    }

    public function getBinaryPath(): string
    {
        return $this->binaryPath ?? $this->name;
    }

    public function getBuildCommand(): string
    {
        return $this->buildCommand ?? 'go build -o ' . $this->getBinaryPath();
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $userId = $context->data['user_id'] ?? 1000;

        $appService = $builder
            ->service($this->name)
                ->user("{$userId}:{$userId}")
                ->volume($this->getDirectory(), static::MOUNT_POINT, 'cached')
                ->profile('default')
                ->workingDir($this->getContainerWorkingDirectory(static::MOUNT_POINT))
                ->command($this->getContainerCommand())
                ->volume($this->getSharedHomeDirectory(), '/home/app', 'cached')
                ->environment('HOME', '/home/app')
        ;

        $this->applyBuild($appService, $context);
        $this->applyHttpRouting($appService);

        return $builder;
    }

    public function getTasks(): iterable
    {
        yield $this->buildTask();
        yield $this->restartTask();
        yield $this->watchTask();
    }

    /**
     * @return array{task: AsTask, function: \Closure}
     */
    protected function buildTask(): array
    {
        return [
            'task' => new AsTask('build', $this->name, 'Build the ' . $this->name . ' application'),
            'function' => function (): void {
                $this->runInBuilder($this->getBuildCommand());
            },
        ];
    }

    /**
     * @return array{task: AsTask, function: \Closure}
     */
    protected function restartTask(): array
    {
        return [
            'task' => new AsTask('restart', $this->name, 'Restart the ' . $this->name . ' service'),
            'function' => function (): void {
                docker_compose(['restart', $this->name]);
            },
        ];
    }

    /**
     * @return array{task: AsTask, function: \Closure}
     */
    protected function watchTask(): array
    {
        return [
            'task' => new AsTask('watch', $this->name, 'Watch for changes and rebuild then restart the ' . $this->name . ' application'),
            'function' => function (): void {
                $directory = $this->getDirectory();
                $watchDirectory = str_starts_with($directory, '/') ? $directory : context()['root_dir'] . '/' . $directory;

                watch($watchDirectory, function ($file, $event): void {
                    if (!str_ends_with($file, '.go')) {
                        return;
                    }

                    $this->runInBuilder($this->getBuildCommand());
                    docker_compose(['restart', $this->name]);
                });
            },
        ];
    }

    /**
     * Declare the build producing the Go image.
     *
     * Extra Debian packages are deliberately not modelled: extend the "go_base"
     * block of the Dockerfile instead.
     */
    protected function applyBuild(ServiceBuilder $service, Context $context): void
    {
        $service
            ->build(__DIR__ . '/../Resources/go')
                ->useTwigFrontend($context)
                ->dockerfile($this->getDockerfile())
                ->target('runtime')
                ->withRegistryCache($this->name)
                ->arg('go_version', $this->getVersion())
        ;
    }

    protected function runInBuilder(string $command, ?Context $c = null): void
    {
        docker_compose_run(
            $command,
            service: $this->name,
            c: $c,
            workDir: $this->getTaskWorkingDirectory(),
        );
    }

    /**
     * The directory to run the tasks in, or null to leave the working directory
     * of the container alone — which is what a single-module service wants, and
     * keeps an override from compose.override.yaml effective.
     */
    protected function getTaskWorkingDirectory(): ?string
    {
        if ('.' === $this->workingDirectory) {
            return null;
        }

        return $this->getContainerWorkingDirectory(static::MOUNT_POINT);
    }

    /**
     * @return list<string>|string
     */
    protected function getContainerCommand(): array|string
    {
        $binary = $this->joinPath(static::MOUNT_POINT, $this->getBinaryPath());

        if (\is_string($this->runCommand)) {
            return $this->runCommand;
        }

        if (\is_array($this->runCommand)) {
            return [$binary, ...$this->runCommand];
        }

        return $binary;
    }
}
