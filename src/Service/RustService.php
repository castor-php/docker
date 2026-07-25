<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;

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
 */
final class RustService implements ServiceInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $version = '1',
        private readonly string $directory = '.',
        /** @var string[] */
        private array $domains = [],
        private bool $allowHttpAccess = false,
        private readonly string $sharedHomeDirectory = '.home',
        private readonly int $port = 8080,
    ) {}

    public function addDomain(string $domain): self
    {
        $this->domains[] = $domain;

        return $this;
    }

    public function allowHttpAccess(bool $allow = true): self
    {
        $this->allowHttpAccess = $allow;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $userId = $context->data['user_id'] ?? 1000;

        $appService = $builder
            ->service($this->name)
                ->build(__DIR__ . '/../Resources/rust')
                    ->withRegistryCache($this->name)
                    ->arg('rust_version', $this->version)
                ->end()
                ->user("{$userId}:{$userId}")
                ->volume($this->directory, '/app', 'cached')
                ->volume($this->sharedHomeDirectory, '/home/app', 'cached')
                ->profile('default')
                ->workingDir('/app')
                ->command('/app/target/debug/' . $this->name)
                ->environment('HOME', '/home/app')
                // Keep the crate registry inside the shared home directory
                // instead of the image, so it is reused across rebuilds and
                // shared by every Rust service of the project.
                ->environment('CARGO_HOME', '/home/app/.cargo')
        ;

        if ($this->domains) {
            $appService
                ->withHttpRouting($this->domains, $this->port, $this->allowHttpAccess);
        }

        return $builder;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('build', $this->name, 'Build the ' . $this->name . ' application'),
            'function' => function (): void {
                docker_compose_run('cargo build', service: $this->name);
            },
        ];

        yield [
            'task' => new AsTask('restart', $this->name, 'Restart the ' . $this->name . ' service'),
            'function' => function (): void {
                docker_compose(['restart', $this->name]);
            },
        ];

        yield [
            'task' => new AsTask('watch', $this->name, 'Watch for changes and rebuild then restart the ' . $this->name . ' application'),
            'function' => function (): void {
                $watchDirectory = str_starts_with($this->directory, '/') ? $this->directory : context()['root_dir'] . '/' . $this->directory;

                watch($watchDirectory, function ($file, $event): void {
                    // Build scripts generate Rust sources under target/, watching
                    // them would make each build trigger the next one.
                    if (str_contains($file, '/target/')) {
                        return;
                    }

                    if (!str_ends_with($file, '.rs') && !str_ends_with($file, 'Cargo.toml') && !str_ends_with($file, 'Cargo.lock')) {
                        return;
                    }

                    docker_compose_run('cargo build', service: $this->name);
                    docker_compose(['restart', $this->name]);
                });
            },
        ];

        yield [
            'task' => new AsTask('test', $this->name, 'Run the test suite of the ' . $this->name . ' application'),
            'function' => function (#[AsRawTokens] array $args): void {
                docker_compose_run(trim('cargo test ' . implode(' ', $args)), service: $this->name);
            },
        ];

        yield [
            'task' => new AsTask('cargo', $this->name, 'Run cargo for this service'),
            'function' => function (#[AsRawTokens] array $args): void {
                docker_compose_run(trim('cargo ' . implode(' ', $args)), service: $this->name);
            },
        ];

        yield [
            'task' => new AsTask('bash', $this->name, 'Run a bash shell inside the Rust container'),
            'function' => function (): void {
                docker_compose_run('bash', $this->name, c: context()->toInteractive());
            },
        ];

        yield [
            'task' => new AsTask('clippy', $this->name . ':qa', 'Runs Clippy'),
            'function' => function (#[AsRawTokens] array $args): void {
                io()->section('Running Clippy...');

                docker_compose_run(trim('cargo clippy --all-targets ' . implode(' ', $args)), service: $this->name);
            },
        ];

        yield [
            'task' => new AsTask('fmt', $this->name . ':qa', 'Fixes Coding Style'),
            'function' => function (#[AsRawTokens] array $args): void {
                io()->section('Running rustfmt...');

                docker_compose_run(trim('cargo fmt ' . implode(' ', $args)), service: $this->name);
            },
        ];
    }
}
