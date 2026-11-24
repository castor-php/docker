<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Behaviour;

/**
 * What rustup installs in a Rust image: targets, components and extra
 * toolchains, the nightly rustfmt among them.
 */
trait HasRustupToolchain
{
    /** The rustup toolchain rustfmt runs under when withNightlyFormatter() is on. */
    protected const NIGHTLY_TOOLCHAIN = 'nightly';

    /** @var list<string> */
    protected array $rustupComponents = ['clippy', 'rustfmt'];

    /** @var list<string> */
    protected array $rustupTargets = [];

    /** @var list<array{name: string, components: list<string>}> */
    protected array $rustupToolchains = [];

    protected bool $nightlyFormatter = false;

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
     * Format with the nightly toolchain, leaving everything else on the default
     * one.
     *
     * Most of rustfmt's options are still unstable, and a stable rustfmt
     * silently ignores a rustfmt.toml using them — hence the usual build and
     * lint on stable, format on nightly.
     */
    public function withNightlyFormatter(bool $nightlyFormatter = true): static
    {
        $this->nightlyFormatter = $nightlyFormatter;

        return $this;
    }

    /**
     * The declared ones, plus the nightly rustfmt needs when
     * withNightlyFormatter() is on. A nightly declared by hand is completed
     * with rustfmt rather than added a second time.
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
     * The arguments of the Rust Dockerfile.
     *
     * @return array<string, string>
     */
    protected function getRustupBuildArgs(): array
    {
        return [
            'rust_version' => $this->getVersion(),
            'rust_components' => json_encode($this->rustupComponents, \JSON_THROW_ON_ERROR),
            'rust_targets' => json_encode($this->rustupTargets, \JSON_THROW_ON_ERROR),
            'rust_toolchains' => json_encode($this->getRustupToolchains(), \JSON_THROW_ON_ERROR),
        ];
    }

    abstract public function getVersion(): string;
}
