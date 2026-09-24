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
 * Runs a Node.js application from the source directory mounted in the
 * container: the official "node" image, the package manager of your choice
 * through corepack, and a package.json script as the container command.
 *
 * "<manager> run dev" by default, which serves a Vite/React or Next.js dev
 * server watching the mounted sources itself. withScript() picks another
 * script, withRunCommand() replaces the command outright for an application no
 * script starts ("node server.js").
 *
 * withDirectory() is what gets mounted and withWorkingDirectory() is where the
 * package.json lives below it — the two come apart in a monorepo.
 */
class NodeService implements ServiceInterface
{
    use HasDirectory;
    use HasDockerfile;
    use HasEnvironment;
    use HasHttpRouting;
    use HasLinks;
    use HasSharedHomeDirectory;
    use HasVersion;

    protected const MOUNT_POINT = '/app';

    protected PackageManager $packageManager = PackageManager::Npm;

    protected string $script = 'dev';

    protected ?string $installCommand = null;

    protected ?string $buildCommand = null;

    /** @var null|list<string>|string */
    protected array|string|null $runCommand = null;

    protected bool $polling = false;

    public function __construct(
        protected readonly string $name,
    ) {}

    protected function getDefaultVersion(): string
    {
        return '24';
    }

    protected function getDefaultDockerfile(): string
    {
        return __DIR__ . '/../Resources/node/Dockerfile';
    }

    /**
     * Vite, Next.js, Nuxt and create-react-app all serve on 3000 or are told to
     * by the PORT variable this service sets.
     */
    protected function getDefaultPort(): int
    {
        return 3000;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Corepack is enabled in the image whichever one this is, so a
     * "packageManager" field in package.json still wins. This only decides what
     * the generated tasks type.
     */
    public function withPackageManager(PackageManager $packageManager): static
    {
        $this->packageManager = $packageManager;

        return $this;
    }

    public function getPackageManager(): PackageManager
    {
        return $this->packageManager;
    }

    /**
     * The package.json script the container runs, "dev" by default.
     */
    public function withScript(string $script): static
    {
        $this->script = $script;

        return $this;
    }

    /**
     * Replace the install command, "<manager> install" by default.
     */
    public function withInstallCommand(string $installCommand): static
    {
        $this->installCommand = $installCommand;

        return $this;
    }

    /**
     * Replace the build command, "<manager> run build" by default.
     */
    public function withBuildCommand(string $buildCommand): static
    {
        $this->buildCommand = $buildCommand;

        return $this;
    }

    /**
     * What the container runs, replacing "<manager> run <script>". A list is
     * the argument vector, a string goes through a shell:
     *
     *     ->withRunCommand(['node', 'server.js'])
     *     ->withRunCommand('npm run dev -- --host 0.0.0.0')
     *
     * @param list<string>|string $runCommand
     */
    public function withRunCommand(array|string $runCommand): static
    {
        $this->runCommand = $runCommand;

        return $this;
    }

    /**
     * Make the file watchers poll instead of waiting for inotify events.
     *
     * A bind mount carries no inotify across the Docker Desktop VM, nor across
     * a Windows filesystem mounted into WSL: the dev server serves and never
     * notices an edit. Polling costs CPU, hence off by default.
     */
    public function withPolling(bool $polling = true): static
    {
        $this->polling = $polling;

        return $this;
    }

    /**
     * Tokens unless the project gave its own command, which may use a shell.
     *
     * @return string|list<string>
     */
    public function getInstallCommand(): string|array
    {
        return $this->installCommand ?? [$this->packageManager->value, 'install'];
    }

    /**
     * @return string|list<string>
     */
    public function getBuildCommand(): string|array
    {
        return $this->buildCommand ?? [$this->packageManager->value, 'run', 'build'];
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $userId = $context->data['user_id'] ?? 1000;

        $appService = $builder
            ->service($this->name)
                ->user("{$userId}:{$userId}")
                ->volume($this->getDirectory(), static::MOUNT_POINT, 'cached')
                ->volume($this->getSharedHomeDirectory($context), '/home/app', 'cached')
                ->profile('default')
                ->workingDir($this->getContainerWorkingDirectory(static::MOUNT_POINT))
                ->command($this->getContainerCommand())
                // A dev server spawns children and is no process manager:
                // without an init, the ones it leaks keep the container alive
                // until compose kills it.
                ->init(true)
                ->environment('HOME', '/home/app')
                // Lands what corepack downloads in the shared home directory,
                // so every Node service of the project downloads it once.
                ->environment('COREPACK_HOME', '/home/app/.cache/node/corepack')
                // Read by Next.js, Nuxt and react-scripts: bound to localhost
                // a dev server answers only inside its container, which the
                // router reaches as a 502. Vite reads neither, it needs
                // "--host".
                ->environment('HOST', '0.0.0.0')
                ->environment('PORT', (string) $this->getPort())
        ;

        if ($this->polling) {
            // chokidar backs Vite and Nuxt, watchpack backs Next.js and webpack.
            $appService
                ->environment('CHOKIDAR_USEPOLLING', 'true')
                ->environment('WATCHPACK_POLLING', 'true')
            ;
        }

        $this->applyLinks($context, $appService);

        // Last, so a project overriding one of the above gets its value.
        $this->applyEnvironment($appService);

        $this->applyBuild($appService, $context);
        $this->applyHttpRouting($appService);

        return $builder;
    }

    public function getTasks(): iterable
    {
        yield $this->installTask();
        yield $this->buildTask();
        yield $this->restartTask();
        yield $this->watchTask();
        yield $this->packageManagerTask();
        yield $this->bashTask();
    }

    /**
     * @return array{task: AsTask, function: \Closure}
     */
    protected function installTask(): array
    {
        return [
            'task' => new AsTask('install', $this->name, 'Install the dependencies of the ' . $this->name . ' application'),
            'function' => function (): void {
                $this->runInBuilder($this->getInstallCommand());
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
     * What an application started with a plain "node server.js" needs. A dev
     * server watches the mounted sources by itself.
     *
     * @return array{task: AsTask, function: \Closure}
     */
    protected function watchTask(): array
    {
        return [
            'task' => new AsTask('watch', $this->name, 'Watch for changes and restart the ' . $this->name . ' application'),
            'function' => function (): void {
                $directory = $this->getDirectory();
                $watchDirectory = str_starts_with($directory, '/') ? $directory : context()['root_dir'] . '/' . $directory;

                watch($watchDirectory, function ($file, $event): void {
                    // Installing writes thousands of files, and the build
                    // output comes from the very process this restarts.
                    if (str_contains($file, '/node_modules/') || str_contains($file, '/.next/') || str_contains($file, '/dist/')) {
                        return;
                    }

                    foreach (['.js', '.jsx', '.mjs', '.cjs', '.ts', '.tsx', '.json'] as $extension) {
                        if (str_ends_with($file, $extension)) {
                            docker_compose(['restart', $this->name]);

                            return;
                        }
                    }
                });
            },
        ];
    }

    /**
     * @return array{task: AsTask, function: \Closure}
     */
    protected function packageManagerTask(): array
    {
        $manager = $this->packageManager->value;

        return [
            'task' => new AsTask($manager, $this->name, 'Run ' . $manager . ' for this service'),
            'function' => function (#[AsRawTokens] array $args) use ($manager): void {
                $this->runInBuilder([$manager, ...$args]);
            },
        ];
    }

    /**
     * @return array{task: AsTask, function: \Closure}
     */
    protected function bashTask(): array
    {
        return [
            'task' => new AsTask('bash', $this->name, 'Run a bash shell inside the Node container'),
            'function' => function (): void {
                $this->runInBuilder(['bash'], interactive_context());
            },
        ];
    }

    /**
     * Extra Debian packages are deliberately not modelled: extend the
     * "node_base" block of the Dockerfile instead.
     */
    protected function applyBuild(ServiceBuilder $service, Context $context): void
    {
        $service
            ->build(__DIR__ . '/../Resources/node')
                ->useTwigFrontend($context)
                ->dockerfile($this->getDockerfile())
                ->target('runtime')
                ->withRegistryCache($this->name)
                ->arg('node_version', $this->getVersion())
                ->arg('package_manager', $this->packageManager->value)
        ;
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
     * single-package service wants and keeps a compose.override.yaml effective.
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
        if (null !== $this->runCommand) {
            return $this->runCommand;
        }

        return [$this->packageManager->value, 'run', $this->script];
    }
}
