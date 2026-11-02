<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsTask;
use Castor\Docker\Service\Builder\ServiceBuilder;

use function Castor\Docker\docker_compose;

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
class NodeService extends AbstractSourceService
{
    protected PackageManager $packageManager = PackageManager::Npm;

    protected string $script = 'dev';

    protected ?string $installCommand = null;

    protected bool $polling = false;

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
        return parent::withRunCommand($runCommand);
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
     * "<manager> run build" unless withBuildCommand() replaces it.
     */
    public function getBuildCommand(): string|array
    {
        return $this->buildCommand ?? [$this->packageManager->value, 'run', 'build'];
    }

    public function getTasks(): iterable
    {
        yield $this->installTask();
        yield from parent::getTasks();
        yield $this->toolTask($this->packageManager->value);
    }

    protected function configureService(ServiceBuilder $service): void
    {
        $service
            // A dev server spawns children and is no process manager: without
            // an init, the ones it leaks keep the container alive until compose
            // kills it.
            ->init(true)
            // Lands what corepack downloads in the shared home directory, so
            // every Node service of the project downloads it once.
            ->environment('COREPACK_HOME', '/home/app/.cache/node/corepack')
            // Read by Next.js, Nuxt and react-scripts: bound to localhost a dev
            // server answers only inside its container, which the router
            // reaches as a 502. Vite reads neither, it needs "--host".
            ->environment('HOST', '0.0.0.0')
            ->environment('PORT', (string) $this->getPort())
        ;

        if ($this->polling) {
            // chokidar backs Vite and Nuxt, watchpack backs Next.js and webpack.
            $service
                ->environment('CHOKIDAR_USEPOLLING', 'true')
                ->environment('WATCHPACK_POLLING', 'true')
            ;
        }
    }

    /**
     * A list replaces the script too: there is no binary to give it to.
     */
    protected function getContainerCommand(): array|string
    {
        return $this->runCommand ?? $this->getDefaultCommand();
    }

    protected function getDefaultCommand(): array
    {
        return [$this->packageManager->value, 'run', $this->script];
    }

    protected function getBuildArgs(): array
    {
        return [
            'node_version' => $this->getVersion(),
            'package_manager' => $this->packageManager->value,
        ];
    }

    /**
     * What an application started with a plain "node server.js" needs. A dev
     * server watches the mounted sources by itself.
     */
    protected function isWatched(string $file): bool
    {
        // Installing writes thousands of files, and the build output comes
        // from the very process this restarts.
        if (str_contains($file, '/node_modules/') || str_contains($file, '/.next/') || str_contains($file, '/dist/')) {
            return false;
        }

        foreach (['.js', '.jsx', '.mjs', '.cjs', '.ts', '.tsx', '.json'] as $extension) {
            if (str_ends_with($file, $extension)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The sources run as they are: a restart is all a change takes.
     */
    protected function reload(): void
    {
        docker_compose(['restart', $this->name]);
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
}
