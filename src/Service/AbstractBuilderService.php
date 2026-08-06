<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasDirectory;
use Castor\Docker\Service\Behaviour\HasDockerfile;
use Castor\Docker\Service\Behaviour\HasSharedHomeDirectory;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\Builder\ServiceBuilder;

use function Castor\context;
use function Castor\Docker\docker_compose_run;

/**
 * A compiler container: it holds the toolchain, mounts the sources, and runs
 * the build and QA commands of the applications declared on it. It runs no
 * application itself.
 *
 * This is the monorepo counterpart of RustService / GoService, which build and
 * run a single application in one container. Here the two are split: one
 * builder for the whole repository, and one BinaryRunService per binary it
 * produces.
 *
 *     $rust = (new RustBuilder('rust-builder'))
 *         ->withDirectory(__DIR__)                   // the repository root
 *         ->withApp('agent/agent-application')
 *         ->withApp('server/log-injector');
 *
 * Each application registered with withApp() gets its own task namespace, and
 * its commands run in this container with the working directory set to the
 * application directory.
 *
 * The builder sits on the "builder" profile: "docker:build" builds it,
 * "docker:up" does not start it.
 */
abstract class AbstractBuilderService implements ServiceInterface
{
    use HasDirectory;
    use HasDockerfile;
    use HasSharedHomeDirectory;
    use HasVersion;

    public const MOUNT_POINT = '/app';

    /** @var list<array{name: string, directory: string, options: array<string, mixed>}> */
    protected array $apps = [];

    public function __construct(
        protected readonly string $name,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The applications this builder compiles, each below the mounted directory.
     *
     * $name defaults to the last segment of $directory and becomes the task
     * namespace, so "agent/agent-application" yields
     * "agent-application:build".
     *
     * @param array<string, mixed> $options language-specific settings, see the concrete builders
     */
    public function withApp(string $directory, ?string $name = null, array $options = []): static
    {
        $this->apps[] = [
            'name' => $name ?? basename(rtrim($directory, '/')),
            'directory' => $directory,
            'options' => $options,
        ];

        return $this;
    }

    /**
     * @return list<array{name: string, directory: string, options: array<string, mixed>}>
     */
    public function getApps(): array
    {
        return $this->apps;
    }

    /**
     * The directory of a declared application, named either by its name or by
     * its directory. Null when this builder declares no such application.
     */
    public function getAppDirectory(string $app): ?string
    {
        foreach ($this->apps as $declared) {
            if ($declared['name'] === $app || $declared['directory'] === $app) {
                return $declared['directory'];
            }
        }

        return null;
    }

    /**
     * Where an application lives inside the container — what the tasks of that
     * application set as their working directory.
     */
    public function getAppWorkingDirectory(string $directory): string
    {
        return $this->joinPath($this->getContainerWorkingDirectory(static::MOUNT_POINT), $directory);
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $userId = $context->data['user_id'] ?? 1000;

        $service = $builder
            ->service($this->name)
                ->user("{$userId}:{$userId}")
                ->volume($this->getDirectory(), static::MOUNT_POINT, 'cached')
                ->volume($this->getSharedHomeDirectory(), '/home/app', 'cached')
                ->workingDir($this->getContainerWorkingDirectory(static::MOUNT_POINT))
                ->environment('HOME', '/home/app')
                // No command: nothing runs here on "docker:up". The container
                // exists to be built, and to host the one-off build and QA
                // commands of the applications declared on it.
                ->init(true)
                ->profile('builder')
        ;

        $this->applyBuild($service, $context);

        return $builder;
    }

    public function getTasks(): iterable
    {
        yield $this->bashTask();

        foreach ($this->apps as $app) {
            yield from $this->getAppTasks($app['name'], $app['directory'], $app['options']);
        }
    }

    /**
     * Compile one of the applications declared on this builder, named either by
     * its name or by its directory.
     *
     * This is what BinaryRunService::build() calls, so a runtime container can
     * rebuild the binary it runs without duplicating the build command.
     *
     * @param array<int, string> $args
     */
    public function buildApp(string $app, array $args = []): void
    {
        foreach ($this->apps as $declared) {
            if ($declared['name'] !== $app && $declared['directory'] !== $app) {
                continue;
            }

            $this->run(
                $this->joinArgs($this->getBuildCommand($declared['name'], $declared['directory'], $declared['options']), $args),
                $declared['directory'],
            );

            return;
        }

        throw new \InvalidArgumentException(\sprintf(
            'The "%s" builder declares no application "%s". Declared: %s.',
            $this->name,
            $app,
            implode(', ', array_column($this->apps, 'name')) ?: '(none)',
        ));
    }

    /**
     * The tasks a single application contributes — one set per withApp() call.
     *
     * @param array<string, mixed> $options
     *
     * @return iterable<array{task: AsTask, function: \Closure}>
     */
    abstract protected function getAppTasks(string $name, string $directory, array $options): iterable;

    /**
     * The command compiling one application, as it runs in the container.
     *
     * @param array<string, mixed> $options
     */
    abstract protected function getBuildCommand(string $name, string $directory, array $options): string;

    /**
     * Declare the build producing the toolchain image.
     */
    abstract protected function applyBuild(ServiceBuilder $service, Context $context): void;

    /**
     * @return array{task: AsTask, function: \Closure}
     */
    protected function bashTask(): array
    {
        return [
            'task' => new AsTask('bash', $this->name, 'Run a bash shell inside the ' . $this->name . ' container'),
            'function' => function (): void {
                $this->run('bash', c: context()->toInteractive());
            },
        ];
    }

    /**
     * Run a command in the builder container, optionally in the directory of
     * one of its applications.
     */
    public function run(string $command, ?string $directory = null, ?Context $c = null): void
    {
        docker_compose_run(
            $command,
            service: $this->name,
            c: $c,
            workDir: $directory === null ? null : $this->getAppWorkingDirectory($directory),
        );
    }

    /**
     * @param array<int, string> $args
     */
    protected function joinArgs(string $command, array $args): string
    {
        return trim($command . ' ' . implode(' ', $args));
    }

    /**
     * Sugar so the concrete builders can declare a task in one expression.
     *
     * @param \Closure(array<int, string>): void $function
     *
     * @return array{task: AsTask, function: \Closure}
     */
    protected function task(string $name, string $namespace, string $description, \Closure $function): array
    {
        return [
            'task' => new AsTask($name, $namespace, $description),
            'function' => static function (#[AsRawTokens] array $args) use ($function): void {
                /** @var array<int, string> $args */
                $function($args);
            },
        ];
    }
}
