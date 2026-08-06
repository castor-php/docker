<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsRawTokens;
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
use function Castor\io;
use function Castor\watch;

/**
 * Runs a Cargo application from the source directory mounted in the container:
 * "cargo build" happens inside the container and the resulting debug binary is
 * used as the container command.
 *
 * The registry and git caches live in the shared home directory, so every Rust
 * service of the project downloads a given crate only once. Build artifacts
 * stay in the project's own target/ directory and therefore survive container
 * recreation too.
 *
 * One crate, one container: this is the single-application case. A monorepo
 * building several binaries from one toolchain wants RustBuilder and
 * BinaryRunService instead, which split the toolchain container from the
 * runtime ones.
 *
 * The paths still need not coincide: withDirectory() is what gets mounted,
 * withWorkingDirectory() is where cargo runs below it, and withBinaryPath() is
 * the binary the container starts.
 */
class RustService implements ServiceInterface
{
    use HasDirectory;
    use HasDockerfile;
    use HasHttpRouting;
    use HasSharedHomeDirectory;
    use HasVersion;

    protected const MOUNT_POINT = '/app';

    /** @var list<string> */
    protected array $rustupComponents = ['clippy', 'rustfmt'];

    /** @var list<string> */
    protected array $rustupTargets = [];

    /** @var list<array{name: string, components: list<string>}> */
    protected array $rustupToolchains = [];

    protected ?string $binaryPath = null;

    protected ?string $target = null;

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
        return __DIR__ . '/../Resources/rust/Dockerfile';
    }

    protected function getDefaultPort(): int
    {
        return 8080;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Install a compilation target, e.g. "x86_64-unknown-linux-musl".
     */
    public function addRustupTarget(string ...$targets): static
    {
        foreach ($targets as $target) {
            if (!\in_array($target, $this->rustupTargets, true)) {
                $this->rustupTargets[] = $target;
            }
        }

        return $this;
    }

    /**
     * Install a rustup component on the default toolchain. "clippy" and
     * "rustfmt" are there by default, so the QA tasks work out of the box.
     */
    public function addRustupComponent(string ...$components): static
    {
        foreach ($components as $component) {
            if (!\in_array($component, $this->rustupComponents, true)) {
                $this->rustupComponents[] = $component;
            }
        }

        return $this;
    }

    /**
     * Install an additional toolchain, e.g. "nightly" for a lint or a formatter
     * that is not stable yet.
     *
     * @param list<string> $components
     */
    public function addRustupToolchain(string $name, array $components = []): static
    {
        $this->rustupToolchains[] = ['name' => $name, 'components' => $components];

        return $this;
    }

    /**
     * The compilation target triple. It is added to the build command and moves
     * the default binary path to target/<triple>/debug/<name> — forgetting the
     * second half is the classic musl pitfall.
     */
    public function withTarget(string $target): static
    {
        $this->target = $target;

        return $this;
    }

    /**
     * The binary the container runs, relative to the mounted directory.
     * Defaults to target/debug/<name>, or target/<triple>/debug/<name> when a
     * target is set.
     */
    public function withBinaryPath(string $binaryPath): static
    {
        $this->binaryPath = $binaryPath;

        return $this;
    }

    /**
     * Replace the build command, "cargo build" by default (plus "--target
     * <triple>" when withTarget() is used).
     */
    public function withBuildCommand(string $buildCommand): static
    {
        $this->buildCommand = $buildCommand;

        return $this;
    }

    /**
     * What the container runs. Given a list, the arguments are appended to the
     * binary — which is what you want to pass flags to your application:
     *
     *     ->withRunCommand(['--listen', '0.0.0.0:18089'])
     *
     * Given a string, it replaces the container command outright, binary
     * included.
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
        if ($this->binaryPath !== null) {
            return $this->binaryPath;
        }

        return $this->target !== null
            ? "target/{$this->target}/debug/{$this->name}"
            : "target/debug/{$this->name}";
    }

    public function getBuildCommand(): string
    {
        if ($this->buildCommand !== null) {
            return $this->buildCommand;
        }

        return $this->target !== null ? "cargo build --target {$this->target}" : 'cargo build';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $userId = $context->data['user_id'] ?? 1000;

        $appService = $builder
            ->service($this->name)
                ->user("{$userId}:{$userId}")
                ->volume($this->getDirectory(), static::MOUNT_POINT, 'cached')
                ->volume($this->getSharedHomeDirectory(), '/home/app', 'cached')
                ->profile('default')
                ->workingDir($this->getContainerWorkingDirectory(static::MOUNT_POINT))
                ->command($this->getContainerCommand())
                ->environment('HOME', '/home/app')
                // Keep the crate registry inside the shared home directory
                // instead of the image, so it is reused across rebuilds and
                // shared by every Rust service of the project.
                ->environment('CARGO_HOME', '/home/app/.cargo')
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
        yield $this->testTask();
        yield $this->cargoTask();
        yield $this->bashTask();
        yield $this->clippyTask();
        yield $this->fmtTask();
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
                    // Build scripts generate Rust sources under target/, watching
                    // them would make each build trigger the next one.
                    if (str_contains($file, '/target/')) {
                        return;
                    }

                    if (!str_ends_with($file, '.rs') && !str_ends_with($file, 'Cargo.toml') && !str_ends_with($file, 'Cargo.lock')) {
                        return;
                    }

                    $this->runInBuilder($this->getBuildCommand());
                    docker_compose(['restart', $this->name]);
                });
            },
        ];
    }

    /**
     * @return array{task: AsTask, function: \Closure}
     */
    protected function testTask(): array
    {
        return [
            'task' => new AsTask('test', $this->name, 'Run the test suite of the ' . $this->name . ' application'),
            'function' => function (#[AsRawTokens] array $args): void {
                $this->runInBuilder(trim('cargo test ' . implode(' ', $args)));
            },
        ];
    }

    /**
     * @return array{task: AsTask, function: \Closure}
     */
    protected function cargoTask(): array
    {
        return [
            'task' => new AsTask('cargo', $this->name, 'Run cargo for this service'),
            'function' => function (#[AsRawTokens] array $args): void {
                $this->runInBuilder(trim('cargo ' . implode(' ', $args)));
            },
        ];
    }

    /**
     * @return array{task: AsTask, function: \Closure}
     */
    protected function bashTask(): array
    {
        return [
            'task' => new AsTask('bash', $this->name, 'Run a bash shell inside the Rust container'),
            'function' => function (): void {
                $this->runInBuilder('bash', context()->toInteractive());
            },
        ];
    }

    /**
     * @return array{task: AsTask, function: \Closure}
     */
    protected function clippyTask(): array
    {
        return [
            'task' => new AsTask('clippy', $this->name . ':qa', 'Runs Clippy'),
            'function' => function (#[AsRawTokens] array $args): void {
                io()->section('Running Clippy...');

                $this->runInBuilder(trim('cargo clippy --all-targets ' . implode(' ', $args)));
            },
        ];
    }

    /**
     * @return array{task: AsTask, function: \Closure}
     */
    protected function fmtTask(): array
    {
        return [
            'task' => new AsTask('fmt', $this->name . ':qa', 'Fixes Coding Style'),
            'function' => function (#[AsRawTokens] array $args): void {
                io()->section('Running rustfmt...');

                $this->runInBuilder(trim('cargo fmt ' . implode(' ', $args)));
            },
        ];
    }

    /**
     * Declare the build producing the Rust image.
     *
     * Extra Debian packages are deliberately not modelled: extend the
     * "rust_base" block of the Dockerfile instead.
     */
    protected function applyBuild(ServiceBuilder $service, Context $context): void
    {
        $service
            ->build(__DIR__ . '/../Resources/rust')
                ->useTwigFrontend($context)
                ->dockerfile($this->getDockerfile())
                ->target('runtime')
                ->withRegistryCache($this->name)
                ->arg('rust_version', $this->getVersion())
                ->arg('rust_components', json_encode($this->rustupComponents, \JSON_THROW_ON_ERROR))
                ->arg('rust_targets', json_encode($this->rustupTargets, \JSON_THROW_ON_ERROR))
                ->arg('rust_toolchains', json_encode($this->rustupToolchains, \JSON_THROW_ON_ERROR))
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
     * of the container alone — which is what a single-crate service wants, and
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
