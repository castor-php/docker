<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasDirectory;
use Castor\Docker\Service\Behaviour\HasEnvironment;
use Castor\Docker\Service\Behaviour\HasHttpRouting;
use Castor\Docker\Service\Behaviour\HasLinks;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\context;
use function Castor\Docker\docker_compose;
use function Castor\watch;

/**
 * Runs one already-compiled binary from the mounted sources — a Rust binary, a
 * Go one, or anything else. The runtime half of the monorepo model, where the
 * compiler lives in a RustBuilder or a GoBuilder:
 *
 *     $agent = (new BinaryRunService('agent', 'agent/target/x86_64-unknown-linux-musl/debug/agent-application'))
 *         ->withBuilder($rustBuilder)
 *         ->withRunCommand(['--listen', '0.0.0.0:18089'])
 *         ->withDomain('agent.project.test');
 *
 * Attaching a builder is what makes "<name>:build" and "<name>:watch" possible,
 * and it settles the image: a binary compiled against the glibc of the builder
 * image will not start in an unrelated slim one. Without a builder, pass your
 * own withImage() and you only get "<name>:restart".
 */
class BinaryRunService implements ServiceInterface
{
    use HasDirectory;
    use HasEnvironment;
    use HasHttpRouting;
    use HasLinks;

    /**
     * Sensible only for a statically linked binary (a musl target, or
     * CGO_ENABLED=0): anything dynamically linked wants the builder image.
     */
    public const DEFAULT_IMAGE = 'debian:13-slim';

    protected ?AbstractBuilderService $builder = null;

    protected ?string $image = null;

    /** The compose restart policy, left to docker's default when null. */
    protected ?string $restart = null;

    /** @var list<string> */
    protected array $runCommand = [];

    /** The application of the builder this binary comes from, for the build task. */
    protected ?string $appName = null;

    /**
     * @param string $binaryPath the binary to run, relative to the mounted directory
     */
    public function __construct(
        protected readonly string $name,
        protected readonly string $binaryPath,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    protected function getDefaultPort(): int
    {
        return 8080;
    }

    /**
     * Run the image of the given builder, mount what it mounts, and rebuild
     * through it.
     *
     * $app names the application of the builder this binary comes from, by name
     * or by directory. It defaults to the service name and only matters for
     * "<name>:build" and "<name>:watch".
     */
    public function withBuilder(AbstractBuilderService $builder, ?string $app = null): static
    {
        $this->builder = $builder;
        $this->appName = $app;

        return $this;
    }

    /**
     * "no", "on-failure", "on-failure:10", "always" or "unless-stopped".
     *
     * Nothing watches the container, so a binary that exits — a dependency not
     * up yet, a panic — stays down until someone notices. "on-failure" brings
     * it back without fighting a deliberate "docker:stop", which "always" does.
     */
    public function withRestart(string $policy = 'on-failure'): static
    {
        $this->restart = $policy;

        return $this;
    }

    /**
     * A slim image, for a statically linked binary that needs nothing from the
     * toolchain.
     */
    public function withImage(string $image): static
    {
        $this->image = $image;

        return $this;
    }

    /**
     * The arguments passed to the binary.
     *
     * @param list<string>|string $arguments
     */
    public function withRunCommand(array|string $arguments): static
    {
        $this->runCommand = \is_string($arguments) ? [$arguments] : $arguments;

        return $this;
    }

    public function getBinaryPath(): string
    {
        return $this->binaryPath;
    }

    public function getImage(): string
    {
        if ($this->image !== null) {
            return $this->image;
        }

        // Compose names what it builds "<project>-<service>", so the builder
        // needs no explicit "image" of its own.
        if ($this->builder !== null) {
            return '${PROJECT_NAME}-' . $this->builder->getName();
        }

        return static::DEFAULT_IMAGE;
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $userId = $context->data['user_id'] ?? 1000;
        $mountPoint = $this->getMountPoint();

        $service = $builder
            ->service($this->name)
                ->image($this->getImage())
                ->user("{$userId}:{$userId}")
                ->volume($this->getMountedDirectory(), $mountPoint, 'cached')
                ->profile('default')
                ->workingDir($this->getContainerWorkingDirectory($mountPoint))
                ->command([$this->joinPath($mountPoint, $this->binaryPath), ...$this->runCommand])
        ;

        if (null !== $this->restart) {
            $service->restart($this->restart);
        }

        $this->applyLinks($context, $service);
        $this->applyEnvironment($service);
        $this->applyHttpRouting($service);

        return $builder;
    }

    public function getTasks(): iterable
    {
        yield $this->restartTask();

        // Rebuilding needs a compiler, and without a builder there is nowhere
        // to run it.
        if ($this->builder !== null) {
            yield $this->buildTask();
            yield $this->watchTask();
        }
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
    protected function buildTask(): array
    {
        return [
            'task' => new AsTask('build', $this->name, 'Build the ' . $this->name . ' application'),
            'function' => function (): void {
                $this->build();
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
                $directory = $this->getWatchedDirectory();
                $watchDirectory = str_starts_with($directory, '/') ? $directory : context()['root_dir'] . '/' . $directory;

                watch($watchDirectory, function ($file, $event): void {
                    // Build scripts generate sources under target/, so each
                    // build would trigger the next one.
                    if (str_contains($file, '/target/')) {
                        return;
                    }

                    if (!$this->isWatched($file)) {
                        return;
                    }

                    $this->build();
                    docker_compose(['restart', $this->name]);
                });
            },
        ];
    }

    /**
     * Runs the "build" task the builder declares for its application.
     */
    protected function build(): void
    {
        if ($this->builder === null) {
            throw new \LogicException(\sprintf('The "%s" service has no builder to rebuild from: attach one with withBuilder().', $this->name));
        }

        $this->builder->buildApp($this->getAppName());
    }

    /**
     * Anything the builder could compile; a subclass narrows it.
     */
    protected function isWatched(string $file): bool
    {
        foreach (['.rs', '.go', 'Cargo.toml', 'Cargo.lock', 'go.mod', 'go.sum'] as $suffix) {
            if (str_ends_with($file, $suffix)) {
                return true;
            }
        }

        return false;
    }

    protected function getAppName(): string
    {
        return $this->appName ?? $this->name;
    }

    /**
     * The mount point of the builder, so the binary path resolves the same way
     * on both sides.
     */
    protected function getMountPoint(): string
    {
        return AbstractBuilderService::MOUNT_POINT;
    }

    /**
     * The builder's one when this service defines none of its own.
     */
    protected function getMountedDirectory(): string
    {
        if (!$this->isDirectoryDefined() && $this->builder !== null) {
            return $this->builder->getDirectory();
        }

        return $this->getDirectory();
    }

    protected function getWatchedDirectory(): string
    {
        $directory = $this->getMountedDirectory();
        $app = $this->builder?->getAppDirectory($this->getAppName());

        // The whole mount would be the repository root of a monorepo, so narrow
        // it to the application this binary is built from when we can.
        return $app === null ? $directory : $this->joinPath($directory, $app);
    }
}
