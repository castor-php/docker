<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Docker\Service\PHPService;
use PHPUnit\Framework\TestCase;

/**
 * The QA tools are installed on the host, in a directory the container mounts.
 * A single directory per tool would collide as soon as a repository holds two
 * applications pinning different versions: each run would reinstall over the
 * previous one, and leave whichever ran last in place.
 */
final class QaToolInstallationTest extends TestCase
{
    private function installation(string $application, string $tool): string
    {
        $app = new class ($application) extends PHPService {
            public function installation(string $tool): string
            {
                return $this->getQaToolInstallation($tool);
            }
        };

        return $app->installation($tool);
    }

    public function testAnInstallationBelongsToOneApplicationAndOneTool(): void
    {
        static::assertSame('app-phpstan', $this->installation('app', 'phpstan'));
        static::assertSame('backend-twig-cs-fixer', $this->installation('backend', 'twig-cs-fixer'));
    }

    public function testTwoApplicationsNeverShareAnInstallation(): void
    {
        static::assertNotSame(
            $this->installation('backend', 'phpstan'),
            $this->installation('demo', 'phpstan'),
        );
    }

    public function testTwoToolsNeverShareAnInstallation(): void
    {
        static::assertNotSame(
            $this->installation('app', 'phpstan'),
            $this->installation('app', 'rector'),
        );
    }

    /**
     * The name says nothing about the version, so bumping one reinstalls in
     * place rather than leaving the previous installation behind forever.
     */
    public function testTheNameDoesNotDependOnTheVersionInstalled(): void
    {
        $before = (new PHPService('app'))->withPhpStanVersion('^1.0');
        $after = (new PHPService('app'))->withPhpStanVersion('^2.0');

        static::assertSame(
            $this->installation($before->getName(), 'phpstan'),
            $this->installation($after->getName(), 'phpstan'),
        );
    }
}
