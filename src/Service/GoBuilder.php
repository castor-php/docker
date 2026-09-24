<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Builder\ServiceBuilder;
use Symfony\Component\Console\Input\InputOption;

use function Castor\io;

/**
 * The Go compiler container of a monorepo: one toolchain, N modules.
 *
 *     $go = (new GoBuilder('go-builder'))
 *         ->withDirectory(__DIR__)               // the repository root
 *         ->withVersion('1.25')
 *         ->withApp('server/exporter')
 *         ->withApp('tools/migrator');
 *
 * Each application gets "<name>:build", "<name>:test", "<name>:go" and
 * "<name>:update", running here with go pointed at its module directory. The
 * binaries are run by BinaryRunService containers.
 *
 * Extra Debian packages are deliberately not modelled: extend the "go_base"
 * block of the Dockerfile instead.
 */
class GoBuilder extends AbstractBuilderService
{
    protected function getDefaultVersion(): string
    {
        return '1';
    }

    protected function getDefaultDockerfile(): string
    {
        return __DIR__ . '/../Resources/go/Dockerfile';
    }

    /**
     * $output is where "go build" writes the binary, relative to the module
     * directory; it defaults to the application name.
     */
    public function withApp(
        string $directory,
        ?string $name = null,
        array $options = [],
        ?string $output = null,
        ?string $buildCommand = null,
    ): static {
        return parent::withApp($directory, $name, $options + array_filter([
            'output' => $output,
            'build_command' => $buildCommand,
        ], static fn($value): bool => null !== $value));
    }

    protected function applyBuild(ServiceBuilder $service, Context $context): void
    {
        $service
            ->build(__DIR__ . '/../Resources/go')
                ->useTwigFrontend($context)
                ->dockerfile($this->getDockerfile())
                ->target('runtime')
                ->withRegistryCache($this->name)
                ->arg('go_version', $this->getVersion())
        ;
    }

    protected function getBuildCommand(string $name, string $directory, array $options): string|array
    {
        if (\is_string($options['build_command'] ?? null)) {
            // given by the project, and may be written to use a shell
            return $options['build_command'];
        }

        return ['go', 'build', '-o', \is_string($options['output'] ?? null) ? $options['output'] : $name];
    }

    protected function getAppTasks(string $name, string $directory, array $options): iterable
    {
        yield $this->task('build', $name, 'Build the ' . $name . ' application', function (array $args) use ($name): void {
            $this->buildApp($name, $args);
        });

        yield $this->task('test', $name, 'Run the test suite of the ' . $name . ' application', function (array $args) use ($directory): void {
            $this->run($this->joinArgs(['go', 'test', './...'], $args), $directory);
        });

        yield $this->task('go', $name, 'Run go for the ' . $name . ' application', function (array $args) use ($directory): void {
            $this->run($this->joinArgs(['go'], $args), $directory);
        });

        yield $this->updateTask($name, $directory);
    }

    /**
     * "go get" alone leaves behind requirements nothing needs any more and an
     * out-of-date go.sum, so "go mod tidy" runs by default rather than being
     * something to remember.
     *
     * @return array{task: AsTask, function: \Closure}
     */
    protected function updateTask(string $name, string $directory): array
    {
        return [
            'task' => new AsTask('update', $name, 'Update the dependencies of the ' . $name . ' application'),
            'function' => function (
                #[AsArgument(description: 'The module to update, every dependency when omitted')]
                ?string $module = null,
                #[AsOption(description: 'Stay inside the current minor version')]
                bool $patch = false,
                #[AsOption(mode: InputOption::VALUE_NEGATABLE, description: 'Also run "go mod tidy" afterwards')]
                bool $tidy = true,
            ) use ($directory): void {
                io()->section('Updating the dependencies...');

                // "-u=patch" stays inside the current minor version.
                $this->run(\sprintf('go get %s %s', $patch ? '-u=patch' : '-u', $module ?? './...'), $directory);

                if ($tidy) {
                    $this->run(['go', 'mod', 'tidy'], $directory);
                }
            },
        ];
    }
}
