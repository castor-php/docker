<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;
use Castor\Docker\Service\Builder\ServiceBuilder;

use function Castor\io;

/**
 * Runs a Cargo application from the source directory mounted in the container:
 * "cargo build" runs inside it and the debug binary becomes the container
 * command.
 *
 * The registry and git caches live in the shared home directory, so a crate is
 * downloaded once for the whole project, and the artifacts stay in the
 * project's own target/ directory.
 *
 * One crate, one container. A monorepo building several binaries from one
 * toolchain wants RustBuilder and BinaryRunService instead.
 *
 * withDirectory() is what gets mounted, withWorkingDirectory() is where cargo
 * runs below it, and withBinaryPath() is the binary the container starts.
 */
class RustService extends AbstractSourceService
{
    /** @var list<string> */
    protected array $rustupComponents = ['clippy', 'rustfmt'];

    /** @var list<string> */
    protected array $rustupTargets = [];

    /** @var list<array{name: string, components: list<string>}> */
    protected array $rustupToolchains = [];

    protected ?string $binaryPath = null;

    protected ?string $target = null;

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
     * "clippy" and "rustfmt" are there by default, so the QA tasks work out of
     * the box.
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
     * E.g. "nightly", for a lint or a formatter that is not stable yet.
     *
     * @param list<string> $components
     */
    public function addRustupToolchain(string $name, array $components = []): static
    {
        $this->rustupToolchains[] = ['name' => $name, 'components' => $components];

        return $this;
    }

    /**
     * Added to the build command, and moves the default binary path to
     * target/<triple>/debug/<name> — forgetting that second half is the classic
     * musl pitfall.
     */
    public function withTarget(string $target): static
    {
        $this->target = $target;

        return $this;
    }

    /**
     * Relative to the mounted directory. Defaults to target/debug/<name>, or
     * target/<triple>/debug/<name> when a target is set.
     */
    public function withBinaryPath(string $binaryPath): static
    {
        $this->binaryPath = $binaryPath;

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

    /**
     * "cargo build" by default, plus "--target <triple>" with withTarget(),
     * unless withBuildCommand() replaces it.
     */
    public function getBuildCommand(): string|array
    {
        if ($this->buildCommand !== null) {
            return $this->buildCommand;
        }

        return $this->target !== null
            ? ['cargo', 'build', '--target', $this->target]
            : ['cargo', 'build'];
    }

    public function getTasks(): iterable
    {
        yield from parent::getTasks();
        yield $this->testTask();
        yield $this->toolTask('cargo');
        yield $this->clippyTask();
        yield $this->fmtTask();
    }

    protected function configureService(ServiceBuilder $service): void
    {
        // The crate registry in the shared home rather than the image, so it
        // survives rebuilds and is shared project-wide.
        $service->environment('CARGO_HOME', '/home/app/.cargo');
    }

    protected function getDefaultCommand(): string
    {
        return $this->joinPath(static::MOUNT_POINT, $this->getBinaryPath());
    }

    protected function getBuildArgs(): array
    {
        return [
            'rust_version' => $this->getVersion(),
            'rust_components' => json_encode($this->rustupComponents, \JSON_THROW_ON_ERROR),
            'rust_targets' => json_encode($this->rustupTargets, \JSON_THROW_ON_ERROR),
            'rust_toolchains' => json_encode($this->rustupToolchains, \JSON_THROW_ON_ERROR),
        ];
    }

    protected function isWatched(string $file): bool
    {
        // Build scripts generate sources under target/, so each build would
        // trigger the next one.
        if (str_contains($file, '/target/')) {
            return false;
        }

        return str_ends_with($file, '.rs') || str_ends_with($file, 'Cargo.toml') || str_ends_with($file, 'Cargo.lock');
    }

    /**
     * @return array{task: AsTask, function: \Closure}
     */
    protected function testTask(): array
    {
        return [
            'task' => new AsTask('test', $this->name, 'Run the test suite of the ' . $this->name . ' application'),
            'function' => function (#[AsRawTokens] array $args): void {
                $this->runInBuilder(['cargo', 'test', ...$args]);
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

                $this->runInBuilder(['cargo', 'clippy', '--all-targets', ...$args]);
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

                $this->runInBuilder(['cargo', 'fmt', ...$args]);
            },
        ];
    }
}
