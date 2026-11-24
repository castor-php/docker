<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use Castor\Context;
use PHPUnit\Framework\TestCase;

use function Castor\Docker\assert_compose_generation_enabled;
use function Castor\Docker\get_compose_file;
use function Castor\Docker\get_compose_files;
use function Castor\Docker\is_compose_generation_enabled;

/**
 * A project whose stack another tool manages still runs the commands of its
 * services, on the compose file it names, while the plugin writes nothing.
 */
final class ComposeGenerationTest extends TestCase
{
    public function testItIsOnByDefault(): void
    {
        static::assertTrue(is_compose_generation_enabled(new Context([], workingDirectory: '/project')));
    }

    public function testTheContextTurnsItOff(): void
    {
        static::assertFalse(is_compose_generation_enabled(new Context(['generate_compose_files' => false], workingDirectory: '/project')));
    }

    public function testATaskManagingThePluginStackStopsWhenItIsOff(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"generate_compose_files" is false');

        assert_compose_generation_enabled(new Context(['generate_compose_files' => false], workingDirectory: '/project'));
    }

    public function testATaskManagingThePluginStackRunsByDefault(): void
    {
        $this->expectNotToPerformAssertions();

        assert_compose_generation_enabled(new Context([], workingDirectory: '/project'));
    }

    public function testTheComposeFileIsAtTheRootByDefault(): void
    {
        static::assertSame('/project/compose.yaml', get_compose_file(new Context([], workingDirectory: '/project')));
    }

    public function testTheContextNamesAnotherComposeFile(): void
    {
        static::assertSame('/project/docker/compose.yaml', get_compose_file(new Context(['compose_file' => 'docker/compose.yaml'], workingDirectory: '/project')));
        static::assertSame('/srv/stack/compose.yaml', get_compose_file(new Context(['compose_file' => '/srv/stack/compose.yaml'], workingDirectory: '/project')));
    }

    /**
     * The generated files only exist when the plugin writes them.
     */
    public function testTheServicesAreReadFromTheFilesOfWhoeverManagesTheStack(): void
    {
        static::assertSame(
            ['/project/compose.generated.yaml', '/project/compose.yaml', '/project/compose.override.yaml'],
            get_compose_files(new Context([], workingDirectory: '/project')),
        );

        static::assertSame(
            ['/project/docker/compose.yaml'],
            get_compose_files(new Context(['generate_compose_files' => false, 'compose_file' => 'docker/compose.yaml'], workingDirectory: '/project')),
        );
    }
}
