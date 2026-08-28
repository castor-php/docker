<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Docker\Service\PHPService;
use PHPUnit\Framework\TestCase;

/**
 * The QA tools are installed by the composer of the container, not by the one
 * castor embeds: composer resolves against the platform it runs on, and the
 * tool runs on the container's PHP and extensions.
 */
final class QaToolContainerInstallationTest extends TestCase
{
    private function application(string $name = 'app'): object
    {
        return new class ($name) extends PHPService {
            /**
             * @param array<string, string> $dependencies
             */
            public function manifest(string $directory, array $dependencies): string
            {
                return $this->getQaToolManifest($directory, $dependencies);
            }

            /**
             * @return list<string>
             */
            public function installCommand(string $directory): array
            {
                return $this->getQaToolInstallCommand($directory);
            }

            public function installation(string $tool): string
            {
                return $this->getQaToolInstallation($tool);
            }

            public function mountPoint(): string
            {
                return static::QA_TOOLS_MOUNT_POINT;
            }
        };
    }

    public function testTheInstallationIsDrivenByTheComposerOfTheContainer(): void
    {
        $command = $this->application()->installCommand('app-phpstan');

        static::assertSame(['composer', 'update'], \array_slice($command, 0, 2));
        static::assertContains('--no-interaction', $command);
    }

    /**
     * The command runs in the container, so the directory it is given has to be
     * the mount point. Naming the host path would install nothing, or install
     * somewhere the container cannot see.
     */
    public function testTheInstallationTargetsTheMountedDirectory(): void
    {
        $app = $this->application();

        static::assertContains(
            '--working-dir=' . $app->mountPoint() . '/app-phpstan',
            $app->installCommand('app-phpstan'),
        );
    }

    public function testTheManifestRequiresWhatTheTaskAsksFor(): void
    {
        $manifest = json_decode(
            $this->application()->manifest('app-phpstan', [
                'phpstan/phpstan' => '^2.0',
                'phpstan/phpstan-symfony' => '*',
            ]),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        static::assertSame(['phpstan/phpstan' => '^2.0', 'phpstan/phpstan-symfony' => '*'], $manifest['require']);
    }

    /**
     * Composer asks before running the plugin of a dependency, and nothing is
     * there to answer: an unallowed plugin turns the task into a hanging prompt.
     */
    public function testTheManifestAllowsThePluginsOfTheToolItInstalls(): void
    {
        $manifest = json_decode(
            $this->application()->manifest('app-phpstan', ['phpstan/extension-installer' => '^1.4']),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        static::assertSame(['phpstan/extension-installer' => true], $manifest['config']['allow-plugins']);
    }

    /**
     * Two applications install side by side in the same mounted directory, so
     * nothing they write may collide.
     */
    public function testTwoApplicationsInstallInDirectoriesOfTheirOwn(): void
    {
        $backend = $this->application('backend');
        $frontend = $this->application('frontend');

        static::assertNotSame(
            $backend->installCommand($backend->installation('phpstan')),
            $frontend->installCommand($frontend->installation('phpstan')),
        );
    }

    /**
     * The manifest is the fingerprint of the installation: were it to name the
     * directory only, two tools of the same application would share it.
     */
    public function testTheManifestIdentifiesTheInstallation(): void
    {
        $app = $this->application();

        static::assertNotSame(
            $app->manifest('app-phpstan', ['phpstan/phpstan' => '*']),
            $app->manifest('app-rector', ['rector/rector' => '*']),
        );
    }
}
