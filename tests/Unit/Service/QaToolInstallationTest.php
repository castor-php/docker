<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Service;

use Castor\Docker\Service\PHPService;
use PHPUnit\Framework\TestCase;

/**
 * The QA tools are installed on the host, in a directory the container mounts.
 * One directory per tool would collide as soon as a repository holds two
 * applications pinning different versions: each run would reinstall over the
 * previous one, and leave whichever ran last in place.
 */
final class QaToolInstallationTest extends TestCase
{
    /**
     * @param array<string, string> $dependencies
     */
    private function installation(string $tool, array $dependencies): string
    {
        $app = new class ('app') extends PHPService {
            /**
             * @param array<string, string> $dependencies
             */
            public function installation(string $tool, array $dependencies): string
            {
                return $this->getQaToolInstallation($tool, $dependencies);
            }
        };

        return $app->installation($tool, $dependencies);
    }

    public function testTheDirectoryStartsWithTheToolName(): void
    {
        static::assertStringStartsWith('phpstan-', $this->installation('phpstan', ['phpstan/phpstan' => '*']));
    }

    public function testDifferentVersionsGetDifferentDirectories(): void
    {
        static::assertNotSame(
            $this->installation('phpstan', ['phpstan/phpstan' => '^1.0']),
            $this->installation('phpstan', ['phpstan/phpstan' => '^2.0']),
        );
    }

    public function testDifferentExtensionsGetDifferentDirectories(): void
    {
        static::assertNotSame(
            $this->installation('phpstan', ['phpstan/phpstan' => '*']),
            $this->installation('phpstan', ['phpstan/phpstan' => '*', 'phpstan/phpstan-symfony' => '^2.0']),
        );
    }

    /**
     * Two applications asking for the same thing must share one installation,
     * rather than each carrying its own copy of the same tool.
     */
    public function testTheSameRequirementsShareOneDirectory(): void
    {
        static::assertSame(
            $this->installation('phpstan', ['phpstan/phpstan' => '^2.0']),
            $this->installation('phpstan', ['phpstan/phpstan' => '^2.0']),
        );
    }

    /**
     * The requirements are a map: the order they were declared in is not a
     * difference.
     */
    public function testTheDeclarationOrderDoesNotMatter(): void
    {
        static::assertSame(
            $this->installation('phpstan', ['phpstan/phpstan' => '*', 'phpstan/phpstan-symfony' => '^2.0']),
            $this->installation('phpstan', ['phpstan/phpstan-symfony' => '^2.0', 'phpstan/phpstan' => '*']),
        );
    }

    public function testTwoToolsNeverShareADirectory(): void
    {
        static::assertNotSame(
            $this->installation('phpstan', ['x/y' => '*']),
            $this->installation('rector', ['x/y' => '*']),
        );
    }
}
