<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\PHPService;
use Castor\Docker\Tests\SnapshotTestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The passwordless sudo makes whoever reaches the container root in it, so it
 * is installed only when the project asked for it.
 */
final class SudoTest extends SnapshotTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function argsOf(PHPService $app): array
    {
        $compose = $app->updateCompose($this->fixedContext(), new ComposeBuilder())->toArray();

        return $compose['services']['app-builder']['build']['args'];
    }

    public function testItIsOffUntilAskedFor(): void
    {
        static::assertFalse((new PHPService('app'))->hasSudo());
        static::assertArrayNotHasKey('sudo', $this->argsOf(new PHPService('app')));
        static::assertStringNotContainsString('gosu', $this->render(false));
    }

    public function testAskingForItInstallsIt(): void
    {
        $app = (new PHPService('app'))->withSudo();

        static::assertTrue($app->hasSudo());
        static::assertSame('true', $this->argsOf($app)['sudo']);
        static::assertStringContainsString('/usr/local/bin/sudo', $this->render(true));
    }

    public function testItCanBeTurnedBackOff(): void
    {
        $app = (new PHPService('app'))->withSudo()->withSudo(false);

        static::assertFalse($app->hasSudo());
        static::assertArrayNotHasKey('sudo', $this->argsOf($app));
    }

    /**
     * Twig reads the string "false" as true, so testing the flag rather than
     * its value would install sudo when told not to — which the build_args
     * context variable lets anyone say by hand.
     */
    public function testTheFlagIsSentByItsPresenceAndNotByItsValue(): void
    {
        static::assertStringNotContainsString('gosu', $this->renderWith(['sudo' => 'false']));
        static::assertStringNotContainsString('gosu', $this->renderWith([]));
        static::assertStringContainsString('gosu', $this->renderWith(['sudo' => 'true']));
    }

    /**
     * gosu publishes one binary per architecture. Naming amd64 outright leaves
     * the image unbuildable on the arm64 every Apple Silicon machine is.
     */
    public function testTheBinaryFollowsTheArchitectureBuilt(): void
    {
        $dockerfile = $this->render(true);

        static::assertStringContainsString('gosu-${TARGETARCH}', $dockerfile);
        static::assertStringContainsString('ARG TARGETARCH', $dockerfile);
    }

    private function dockerfile(): string
    {
        return \dirname(__DIR__, 3) . '/src/Resources/php/Dockerfile';
    }

    private function render(bool $sudo): string
    {
        return $this->renderWith($sudo ? ['sudo' => 'true'] : []);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function renderWith(array $extra): string
    {
        $twig = new Environment(new FilesystemLoader(\dirname($this->dockerfile())), ['autoescape' => false]);
        $twig->addFunction(new TwigFunction('copy', static fn(string ...$a): string => 'COPY ' . implode(' ', $a)));

        return $twig->render('Dockerfile', [
            'php_version' => '8.5',
            'php_extensions' => [],
            'node_version' => '24.x',
            'package_manager' => 'npm',
            ...$extra,
        ]);
    }
}
