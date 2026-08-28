<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\RustBuilder;
use Castor\Docker\Tests\SnapshotTestCase;

/**
 * Most of rustfmt's options are still unstable, so a rustfmt.toml using any of
 * them is silently ignored by a stable rustfmt. The usual answer is to build
 * and lint on stable and to format on nightly, which means the image has to
 * carry nightly and only the "fmt" task may use it.
 */
final class RustNightlyFormatterTest extends SnapshotTestCase
{
    private function builder(bool $nightly): RustBuilder
    {
        $builder = new class ('rust-builder') extends RustBuilder {
            /**
             * @return list<array{name: string, components: list<string>}>
             */
            public function toolchains(): array
            {
                return $this->getRustupToolchains();
            }

            /**
             * @param array<string, mixed> $options
             */
            /**
             * @return list<string>
             */
            public function format(array $options = []): array
            {
                return $this->formatCommand($options);
            }

            /**
             * @param array<string, mixed> $options
             */
            /**
             * @return string|list<string>
             */
            public function build(array $options = []): string|array
            {
                return $this->getBuildCommand('app', 'app', $options);
            }
        };

        return $builder->withNightlyFormatter($nightly);
    }

    public function testNothingChangesWithoutTheFlag(): void
    {
        $builder = $this->builder(false);

        static::assertSame([], $builder->toolchains());
        static::assertSame(['cargo'], $builder->format());
    }

    public function testTheFlagInstallsNightlyWithRustfmt(): void
    {
        static::assertSame(
            [['name' => 'nightly', 'components' => ['rustfmt']]],
            $this->builder(true)->toolchains(),
        );
    }

    public function testTheFlagOnlyMovesTheFormatter(): void
    {
        $builder = $this->builder(true);

        static::assertSame(['rustup', 'run', 'nightly', 'cargo'], $builder->format());
        // Building and linting stay on the default toolchain.
        static::assertSame(['cargo', 'build'], $builder->build());
    }

    /**
     * A project already declaring nightly — for a lint, say — must not get it
     * twice, and must still end up with rustfmt in it.
     */
    public function testAnAlreadyDeclaredNightlyIsCompleted(): void
    {
        $builder = $this->builder(true);
        $builder->addRustupToolchain('nightly', ['clippy']);

        static::assertSame(
            [['name' => 'nightly', 'components' => ['clippy', 'rustfmt']]],
            $builder->toolchains(),
        );
    }

    public function testAnAlreadyCompleteNightlyIsLeftAlone(): void
    {
        $builder = $this->builder(true);
        $builder->addRustupToolchain('nightly', ['rustfmt']);

        static::assertSame(
            [['name' => 'nightly', 'components' => ['rustfmt']]],
            $builder->toolchains(),
        );
    }

    /**
     * The toolchain has to reach the image, or the task would call a rustfmt
     * that is not installed.
     */
    public function testTheToolchainReachesTheBuildArguments(): void
    {
        $compose = (new RustBuilder('rust-builder'))
            ->withDirectory('/project')
            ->withNightlyFormatter()
            ->updateCompose($this->fixedContext(), new ComposeBuilder())
            ->toArray()
        ;

        static::assertSame(
            '[{"name":"nightly","components":["rustfmt"]}]',
            $compose['services']['rust-builder']['build']['args']['rust_toolchains'],
        );
    }

    public function testAnAppKeepsItsOwnToolchainForEverythingElse(): void
    {
        $builder = $this->builder(true);

        // The application already runs on nightly: the formatter flag changes
        // nothing for it.
        static::assertSame(['rustup', 'run', 'nightly', 'cargo'], $builder->format(['toolchain' => 'nightly']));
        static::assertSame(['rustup', 'run', 'beta', 'cargo', 'build'], $builder->build(['toolchain' => 'beta']));
    }
}
