<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Context;
use Castor\Docker\Service\Builder\ServiceBuilder;

/**
 * The Go compiler container of a monorepo: one toolchain, N modules.
 *
 *     $go = (new GoBuilder('go-builder'))
 *         ->withDirectory(__DIR__)               // the repository root
 *         ->withVersion('1.25')
 *         ->withApp('server/exporter')
 *         ->withApp('tools/migrator');
 *
 * Each application gets "<name>:build", "<name>:test" and "<name>:go", all
 * running in this container with go pointed at the module directory.
 *
 * The binaries it produces are run by BinaryRunService containers.
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
     * Declare a module built by this builder.
     *
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

    protected function getBuildCommand(string $name, string $directory, array $options): string
    {
        if (\is_string($options['build_command'] ?? null)) {
            return $options['build_command'];
        }

        return 'go build -o ' . (\is_string($options['output'] ?? null) ? $options['output'] : $name);
    }

    protected function getAppTasks(string $name, string $directory, array $options): iterable
    {
        yield $this->task('build', $name, 'Build the ' . $name . ' application', function (array $args) use ($name): void {
            $this->buildApp($name, $args);
        });

        yield $this->task('test', $name, 'Run the test suite of the ' . $name . ' application', function (array $args) use ($directory): void {
            $this->run($this->joinArgs('go test ./...', $args), $directory);
        });

        yield $this->task('go', $name, 'Run go for the ' . $name . ' application', function (array $args) use ($directory): void {
            $this->run($this->joinArgs('go', $args), $directory);
        });
    }
}
