<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasDirectory;
use Castor\Docker\Service\Behaviour\HasDockerfile;
use Castor\Docker\Service\Behaviour\HasEnvironment;
use Castor\Docker\Service\Behaviour\HasHttpRouting;
use Castor\Docker\Service\Behaviour\HasLinks;
use Castor\Docker\Service\Behaviour\HasSharedHomeDirectory;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\Builder\ServiceBuilder;

use function Castor\context;
use function Castor\Docker\docker_compose;
use function Castor\Docker\docker_compose_run;
use function Castor\Docker\interactive_context;
use function Castor\watch;

/**
 * An application run from its source directory, mounted in a container built
 * with the toolchain of its language: the container both builds it and runs it.
 *
 * withDirectory() is what gets mounted, withWorkingDirectory() is where the
 * toolchain runs below it.
 */
abstract class AbstractSourceService implements ServiceInterface
{
    use HasDirectory;
    use HasDockerfile;
    use HasEnvironment;
    use HasHttpRouting;
    use HasLinks;
    use HasSharedHomeDirectory;
    use HasVersion;

    protected const MOUNT_POINT = '/app';

    protected ?string $buildCommand = null;

    /** @var null|list<string>|string */
    protected array|string|null $runCommand = null;

    public function __construct(
        protected readonly string $name,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function withBuildCommand(string $buildCommand): static
    {
        $this->buildCommand = $buildCommand;

        return $this;
    }

    /**
     * A list appends its arguments to the default command:
     *
     *     ->withRunCommand(['--listen', '0.0.0.0:8080'])
     *
     * A string replaces the container command outright.
     *
     * @param list<string>|string $runCommand
     */
    public function withRunCommand(array|string $runCommand): static
    {
        $this->runCommand = $runCommand;

        return $this;
    }

    /**
     * Tokens unless the project gave its own command, which may use a shell.
     *
     * @return string|list<string>
     */
    abstract public function getBuildCommand(): string|array;

    /**
     * What the container runs when withRunCommand() does not replace it.
     *
     * @return list<string>|string
     */
    abstract protected function getDefaultCommand(): array|string;

    /**
     * The arguments of the Dockerfile, the version of the toolchain among them.
     *
     * @return array<string, string>
     */
    abstract protected function getBuildArgs(): array;

    /**
     * Whether a change to this file calls for a reload.
     */
    abstract protected function isWatched(string $file): bool;

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $userId = $context->data['user_id'] ?? 1000;

        $service = $builder
            ->service($this->name)
                ->user("{$userId}:{$userId}")
                ->volume($this->getDirectory(), static::MOUNT_POINT, 'cached')
                ->volume($this->getSharedHomeDirectory($context), '/home/app', 'cached')
                ->profile('default')
                ->workingDir($this->getContainerWorkingDirectory(static::MOUNT_POINT))
                ->command($this->getContainerCommand())
                ->environment('HOME', '/home/app')
        ;

        $this->configureService($service);
        $this->applyLinks($context, $service);

        // Last, so a project overriding one of the above gets its value.
        $this->applyEnvironment($service);

        $this->applyBuild($service, $context);
        $this->applyHttpRouting($service);

        return $builder;
    }

    public function getTasks(): iterable
    {
        yield $this->buildTask();
        yield $this->restartTask();
        yield $this->watchTask();
        yield $this->bashTask();
    }

    /**
     * What the language adds to the container: its own variables, mostly.
     */
    protected function configureService(ServiceBuilder $service): void {}

    /**
     * Extra Debian packages are deliberately not modelled: extend the base
     * block of the Dockerfile instead.
     */
    protected function applyBuild(ServiceBuilder $service, Context $context): void
    {
        $build = $service
            ->build(\dirname($this->getDefaultDockerfile()))
                ->useTwigFrontend($context)
                ->dockerfile($this->getDockerfile())
                ->target('runtime')
                ->withRegistryCache($this->name)
        ;

        foreach ($this->getBuildArgs() as $name => $value) {
            $build->arg($name, $value);
        }
    }

    /**
     * @return list<string>|string
     */
    protected function getContainerCommand(): array|string
    {
        if (\is_string($this->runCommand)) {
            return $this->runCommand;
        }

        if (\is_array($this->runCommand)) {
            return [...(array) $this->getDefaultCommand(), ...$this->runCommand];
        }

        return $this->getDefaultCommand();
    }

    /**
     * Rebuilds, then restarts the container on the new binary.
     */
    protected function reload(): void
    {
        $this->runInBuilder($this->getBuildCommand());
        docker_compose(['restart', $this->name]);
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
            'task' => new AsTask('watch', $this->name, 'Watch for changes and reload the ' . $this->name . ' application'),
            'function' => function (): void {
                watch($this->getAbsoluteHostDirectory(context()), function (string $file): void {
                    if ($this->isWatched($file)) {
                        $this->reload();
                    }
                });
            },
        ];
    }

    /**
     * @return array{task: AsTask, function: \Closure}
     */
    protected function bashTask(): array
    {
        return [
            'task' => new AsTask('bash', $this->name, 'Open a bash shell in the container of the ' . $this->name . ' application'),
            'function' => function (): void {
                $this->runInBuilder(['bash'], interactive_context());
            },
        ];
    }

    /**
     * A task running a tool of the toolchain, "cargo" or "go", with the
     * arguments it is given.
     *
     * @return array{task: AsTask, function: \Closure}
     */
    protected function toolTask(string $tool): array
    {
        return [
            'task' => new AsTask($tool, $this->name, 'Run ' . $tool . ' for this service'),
            'function' => function (#[AsRawTokens] array $args) use ($tool): void {
                $this->runInBuilder([$tool, ...$args]);
            },
        ];
    }

    /**
     * @param string|array<int, string> $command
     */
    protected function runInBuilder(string|array $command, ?Context $c = null): void
    {
        docker_compose_run(
            $command,
            service: $this->name,
            c: $c,
            workDir: $this->getTaskWorkingDirectory(),
        );
    }

    /**
     * Null leaves the working directory of the container alone, which is what a
     * single-module service wants and keeps a compose.override.yaml effective.
     */
    protected function getTaskWorkingDirectory(): ?string
    {
        if ('.' === $this->workingDirectory) {
            return null;
        }

        return $this->getContainerWorkingDirectory(static::MOUNT_POINT);
    }
}
