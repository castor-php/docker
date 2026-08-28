<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\Builder\ServiceBuilder;

use function Castor\io;

/**
 * The Rust compiler container of a monorepo: one toolchain, N crates.
 *
 *     $rust = (new RustBuilder('rust-builder'))
 *         ->withDirectory(__DIR__)                    // the repository root
 *         ->withVersion('1.94.0')
 *         ->addRustupTarget('x86_64-unknown-linux-musl')
 *         ->addRustupToolchain('nightly', ['rustfmt'])
 *         ->withApp('agent/agent-application', target: 'x86_64-unknown-linux-musl')
 *         ->withApp('server/log-injector');
 *
 * Each application gets "<name>:build", "<name>:test", "<name>:cargo",
 * "<name>:qa:clippy" and "<name>:qa:fmt", all running in this container with
 * cargo pointed at the crate directory.
 *
 * The binaries it produces are run by BinaryRunService containers.
 *
 * Extra Debian packages are deliberately not modelled: extend the "rust_base"
 * block of the Dockerfile instead.
 */
class RustBuilder extends AbstractBuilderService
{
    /** @var list<string> */
    protected array $rustupComponents = ['clippy', 'rustfmt'];

    /** @var list<string> */
    protected array $rustupTargets = [];

    /** @var list<array{name: string, components: list<string>}> */
    protected array $rustupToolchains = [];

    /** The rustup toolchain rustfmt runs under when withNightlyFormatter() is on. */
    protected const NIGHTLY_TOOLCHAIN = 'nightly';

    protected bool $nightlyFormatter = false;

    protected function getDefaultVersion(): string
    {
        return '1';
    }

    protected function getDefaultDockerfile(): string
    {
        return __DIR__ . '/../Resources/rust/Dockerfile';
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
     * Format with the nightly toolchain, leaving everything else on the default
     * one.
     *
     * Most of rustfmt's options are still unstable, so a rustfmt.toml using any
     * of them is silently ignored by a stable rustfmt — the usual answer is to
     * build and lint on stable and to format on nightly. This installs the
     * nightly toolchain with its rustfmt in the image, and points the "fmt"
     * task of every application at it.
     */
    public function withNightlyFormatter(bool $nightlyFormatter = true): static
    {
        $this->nightlyFormatter = $nightlyFormatter;

        return $this;
    }

    /**
     * Declare a crate built by this builder.
     *
     * $target adds "--target <triple>" to its build command; $toolchain runs
     * cargo through "rustup run <toolchain>", for a crate that needs another
     * one than the default.
     */
    public function withApp(
        string $directory,
        ?string $name = null,
        array $options = [],
        ?string $target = null,
        ?string $toolchain = null,
        ?string $buildCommand = null,
    ): static {
        return parent::withApp($directory, $name, $options + array_filter([
            'target' => $target,
            'toolchain' => $toolchain,
            'build_command' => $buildCommand,
        ], static fn($value): bool => null !== $value));
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $builder = parent::updateCompose($context, $builder);

        // Keep the crate registry inside the shared home directory instead of
        // the image, so it is reused across rebuilds and shared by every Rust
        // application of the project.
        $builder->service($this->name)->environment('CARGO_HOME', '/home/app/.cargo');

        return $builder;
    }

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
                ->arg('rust_toolchains', json_encode($this->getRustupToolchains(), \JSON_THROW_ON_ERROR))
        ;
    }

    /**
     * The toolchains the image installs: the ones declared, plus the nightly
     * rustfmt needs when withNightlyFormatter() is on.
     *
     * Declaring nightly yourself keeps working — it is completed with rustfmt
     * rather than added a second time.
     *
     * @return list<array{name: string, components: list<string>}>
     */
    protected function getRustupToolchains(): array
    {
        $toolchains = $this->rustupToolchains;

        if (!$this->nightlyFormatter) {
            return $toolchains;
        }

        foreach ($toolchains as $index => $toolchain) {
            if (static::NIGHTLY_TOOLCHAIN !== $toolchain['name']) {
                continue;
            }

            if (!\in_array('rustfmt', $toolchain['components'], true)) {
                $toolchains[$index]['components'][] = 'rustfmt';
            }

            return $toolchains;
        }

        $toolchains[] = ['name' => static::NIGHTLY_TOOLCHAIN, 'components' => ['rustfmt']];

        return $toolchains;
    }

    /**
     * The cargo the "fmt" task runs through, which is the only one nightly
     * applies to.
     *
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    protected function formatCommand(array $options): array
    {
        if (!$this->nightlyFormatter) {
            return $this->cargoCommand($options);
        }

        return $this->cargoCommand(['toolchain' => static::NIGHTLY_TOOLCHAIN]);
    }

    protected function getBuildCommand(string $name, string $directory, array $options): string|array
    {
        if (\is_string($options['build_command'] ?? null)) {
            // given by the project, and may be written to use a shell
            return $options['build_command'];
        }

        $target = \is_string($options['target'] ?? null) ? ['--target', $options['target']] : [];

        return [...$this->cargoCommand($options), 'build', ...$target];
    }

    protected function getAppTasks(string $name, string $directory, array $options): iterable
    {
        $cargo = $this->cargoCommand($options);

        yield $this->task('build', $name, 'Build the ' . $name . ' application', function (array $args) use ($name): void {
            $this->buildApp($name, $args);
        });

        yield $this->task('test', $name, 'Run the test suite of the ' . $name . ' application', function (array $args) use ($cargo, $directory): void {
            $this->run($this->joinArgs([...$cargo, 'test'], $args), $directory);
        });

        yield $this->task('cargo', $name, 'Run cargo for the ' . $name . ' application', function (array $args) use ($cargo, $directory): void {
            $this->run($this->joinArgs($cargo, $args), $directory);
        });

        yield $this->task('clippy', $name . ':qa', 'Runs Clippy', function (array $args) use ($cargo, $directory): void {
            io()->section('Running Clippy...');

            $this->run($this->joinArgs([...$cargo, 'clippy', '--all-targets'], $args), $directory);
        });

        $format = $this->formatCommand($options);

        yield $this->task('fmt', $name . ':qa', 'Fixes Coding Style' . ($this->nightlyFormatter ? ' (nightly rustfmt)' : ''), function (array $args) use ($format, $directory): void {
            io()->section('Running rustfmt...');

            $this->run($this->joinArgs([...$format, 'fmt'], $args), $directory);
        });
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    protected function cargoCommand(array $options): array
    {
        $toolchain = $options['toolchain'] ?? null;

        return \is_string($toolchain) ? ['rustup', 'run', $toolchain, 'cargo'] : ['cargo'];
    }
}
