<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\PackageManager;
use Castor\Docker\Service\PHPService;
use Castor\Docker\Tests\SnapshotTestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Which JavaScript package manager the builder image prepares. Corepack is
 * enabled whichever one it is, so this only decides what a project declaring no
 * "packageManager" field finds ready to run.
 */
final class PackageManagerTest extends SnapshotTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function argsOf(PHPService $app): array
    {
        $compose = $app->updateCompose($this->fixedContext(), new ComposeBuilder())->toArray();

        return $compose['services']['app-builder']['build']['args'];
    }

    /**
     * npm comes with node, so it is the one costing the image nothing.
     */
    public function testNpmIsTheDefault(): void
    {
        static::assertSame(PackageManager::Npm, (new PHPService('app'))->getPackageManager());
        static::assertSame('npm', $this->argsOf(new PHPService('app'))['package_manager']);
    }

    public function testTheChosenManagerReachesTheImage(): void
    {
        foreach (PackageManager::cases() as $manager) {
            static::assertSame(
                $manager->value,
                $this->argsOf((new PHPService('app'))->withPackageManager($manager))['package_manager'],
                $manager->name,
            );
        }
    }

    /**
     * Rendering the template is what a build does first, so a branch that does
     * not render — or renders nothing for a case — is caught here rather than
     * halfway through an image build.
     */
    public function testEveryCasePreparesItsManager(): void
    {
        $expected = [
            'npm' => null,                                    // installed with node
            'yarn' => 'yarn set version stable',
            'pnpm' => 'corepack prepare pnpm@latest --activate',
        ];

        foreach (PackageManager::cases() as $manager) {
            $dockerfile = $this->render($manager);

            static::assertStringContainsString('corepack enable', $dockerfile, $manager->name);

            if (null === $expected[$manager->value]) {
                static::assertStringNotContainsString('yarn set version', $dockerfile, $manager->name);
                static::assertStringNotContainsString('corepack prepare', $dockerfile, $manager->name);

                continue;
            }

            static::assertStringContainsString($expected[$manager->value], $dockerfile, $manager->name);
        }
    }

    /**
     * The version is interpolated into the NodeSource repository name, so a
     * variable that stops reaching the template installs the wrong Node.
     */
    public function testTheNodeVersionReachesTheRepositoryName(): void
    {
        static::assertStringContainsString('node_24.x nodistro', $this->render(PackageManager::Npm, '24.x'));
    }

    /**
     * The Dockerfile is a Twig template rendered by a BuildKit frontend; this
     * renders it the same way, with the variables PHPService sends.
     */
    private function render(PackageManager $manager, string $nodeVersion = '20.x'): string
    {
        $directory = \dirname(__DIR__, 3) . '/src/Resources/php';

        $twig = new Environment(new FilesystemLoader($directory), ['autoescape' => false]);
        $twig->addFunction(new TwigFunction('copy', static fn(string ...$a): string => 'COPY ' . implode(' ', $a)));

        return $twig->render('Dockerfile', [
            'php_version' => '8.5',
            'php_extensions' => [],
            'node_version' => $nodeVersion,
            'package_manager' => $manager->value,
        ]);
    }

    /**
     * Only the stage installing node is given it: the application and worker
     * stages would carry it into their build cache for nothing.
     */
    public function testOnlyTheStageInstallingNodeIsGivenIt(): void
    {
        $compose = (new PHPService('app'))
            ->withPackageManager(PackageManager::Pnpm)
            ->updateCompose($this->fixedContext(), new ComposeBuilder())
            ->toArray()
        ;

        static::assertArrayNotHasKey('package_manager', $compose['services']['app']['build']['args']);
    }
}
