<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Context;

use function Castor\Docker\docker_compose_run;
use function Castor\Docker\docker_exit_code;
use function Castor\fingerprint;
use function Castor\hasher;
use function Castor\io;

/**
 * Installs and runs the QA tools of one PHP application inside its container,
 * so the analysis sees the PHP version, the extensions and the vendor/ the
 * application really runs on rather than whichever PHP happens to run castor.
 *
 * Holds nothing but resolved paths and names: PHPService::getQaToolRunner()
 * builds one per run, against the context of that run.
 */
final readonly class QaToolRunner
{
    /**
     * Where the QA tools are installed, and mounted in the container that runs
     * them.
     */
    public const MOUNT_POINT = '/castor-tools';

    /**
     * The configuration file names each QA tool discovers on its own, in the
     * directory it runs in.
     */
    private const CONFIGURATION_FILES = [
        'phpstan' => ['.phpstan.neon', 'phpstan.neon', '.phpstan.neon.dist', 'phpstan.neon.dist', '.phpstan.dist.neon', 'phpstan.dist.neon'],
        'php-cs-fixer' => ['.php-cs-fixer.php', '.php-cs-fixer.dist.php'],
        'rector' => ['rector.php'],
    ];

    /**
     * @param string  $application               the application the installations belong to
     * @param string  $phpVersion                the PHP the tools are resolved against
     * @param string  $service                   the compose service the tools are installed and run in
     * @param ?string $workDir                   the directory they run in, null for the one of the image
     * @param string  $hostWorkingDirectory      where the application keeps its configuration files
     * @param string  $containerWorkingDirectory the same directory, as seen from the container
     * @param string  $toolsDirectory            the host directory mounted on MOUNT_POINT
     */
    public function __construct(
        private string $application,
        private string $phpVersion,
        private string $service,
        private ?string $workDir,
        private string $hostWorkingDirectory,
        private string $containerWorkingDirectory,
        private string $toolsDirectory,
    ) {}

    /**
     * The host directory holding the QA tools castor installs, mounted in the
     * container that runs them.
     */
    public static function getToolsDirectory(Context $context): string
    {
        return $context->workingDirectory . '/.castor/vendor/.tools';
    }

    /**
     * @param array<string, string> $dependencies the composer requirements of the tool
     * @param list<string>          $arguments
     */
    public function run(string $tool, array $dependencies, array $arguments): int
    {
        $directory = $this->getInstallation($tool);

        $this->install($directory, $dependencies);

        return docker_exit_code(
            [self::MOUNT_POINT . '/' . $directory . '/vendor/bin/' . $tool, ...$arguments],
            $this->service,
            workDir: $this->workDir,
        );
    }

    /**
     * What a QA tool analyses when the task was given no arguments of its own.
     *
     * A path on the command line *replaces* the ones the configuration file
     * declares rather than restricting them — PHPStan only falls back to
     * `parameters.paths` when none is named, PHP CS Fixer ignores its finder
     * without `--path-mode=intersection`, Rector does the same with
     * `withPaths()`. So no path is named when the application ships a
     * configuration the tool discovers on its own.
     *
     * @param list<string> $command the sub-command the tool needs, if any
     *
     * @return list<string>
     */
    public function getDefaultArguments(string $tool, array $command, string $suffix = ''): array
    {
        if ($this->hasConfiguration($tool)) {
            return $command;
        }

        return [...$command, $this->containerWorkingDirectory . $suffix];
    }

    /**
     * One per application, per tool: a repository pinning PHPStan 1 on one
     * application and 2 on another would otherwise have every run reinstall
     * over the previous one. Naming it after the application also keeps the
     * name stable, so a version bump reinstalls in place.
     */
    public function getInstallation(string $tool): string
    {
        return $this->application . '-' . $tool;
    }

    /**
     * Pins nothing beyond what the task asks for: the resolution is the
     * container's to make.
     *
     * @param array<string, string> $dependencies
     */
    public function getManifest(string $directory, array $dependencies): string
    {
        return json_encode([
            'name' => 'tools/' . $directory,
            'require' => $dependencies,
            'config' => [
                // A tool is a leaf: its own plugins are the only ones here,
                // and none is worth an interactive prompt.
                'allow-plugins' => array_fill_keys(array_keys($dependencies), true),
            ],
        ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * Run in the container, so --working-dir names the mount point and not the
     * host directory behind it.
     *
     * @return list<string>
     */
    public function getInstallCommand(string $directory): array
    {
        return [
            'composer',
            'update',
            '--working-dir=' . self::MOUNT_POINT . '/' . $directory,
            '--no-interaction',
        ];
    }

    /**
     * Resolved by the composer of the container, not the one castor embeds:
     * composer picks versions against the platform it runs on, so a host on PHP
     * 8.5 installing for a container on 8.1 gets a tool the container cannot
     * run, and the reverse analyses with an older tool than it should.
     *
     * The directory is on the host, mounted in the container: an installation
     * survives the containers, and the composer cache is reused across tools.
     *
     * @param array<string, string> $dependencies
     */
    private function install(string $directory, array $dependencies): void
    {
        $path = $this->toolsDirectory . '/' . $directory;
        $manifest = $this->getManifest($directory, $dependencies);

        // The PHP version takes part in the resolution, so bumping it has to
        // reinstall the tool.
        fingerprint(
            callback: function () use ($path, $manifest, $directory): void {
                if (!is_dir($path)) {
                    mkdir($path, 0o777, true);
                }

                file_put_contents($path . '/composer.json', $manifest);

                io()->comment('Installing/Updating ' . $directory . '...');

                docker_compose_run($this->getInstallCommand($directory), service: $this->service, workDir: $this->workDir);
            },
            id: 'docker-tools-' . $directory,
            fingerprint: hasher()->write($manifest)->write($this->phpVersion)->finish(),
            force: !is_file($path . '/composer.json'),
        );
    }

    /**
     * Whether the application ships a configuration file the tool discovers on
     * its own, in the directory it runs in.
     */
    private function hasConfiguration(string $tool): bool
    {
        foreach (self::CONFIGURATION_FILES[$tool] ?? [] as $name) {
            if (is_file($this->hostWorkingDirectory . '/' . $name)) {
                return true;
            }
        }

        return false;
    }
}
