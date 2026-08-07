<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use Castor\Context;
use PHPUnit\Framework\TestCase;

use function Castor\Docker\get_project_name;
use function Castor\Docker\initialize_project;

/**
 * The project name is what containers, networks, volumes and the images of a
 * shared builder are all derived from, so a second checkout of the same
 * repository — a git worktree, say — only has to override it to run beside the
 * first.
 *
 * That only works if the context wins over the "name" of compose.yaml, which
 * every checkout shares.
 */
final class ProjectNameTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'project');
        \assert($directory !== false);
        unlink($directory);
        mkdir($directory . '/my-project', 0o777, true);

        $this->directory = $directory . '/my-project';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
        rmdir(\dirname($this->directory));
    }

    private function writeComposeFile(string $name): void
    {
        file_put_contents($this->directory . '/compose.yaml', "name: {$name}\n");
    }

    /**
     * @param array<string, mixed> $data
     */
    private function context(array $data = []): Context
    {
        return new Context(data: $data, workingDirectory: $this->directory);
    }

    public function testTheContextWinsOverTheComposeFile(): void
    {
        $this->writeComposeFile('rio');

        $context = initialize_project($this->context(['project_name' => 'rio-wt2']));

        static::assertSame('rio-wt2', $context->data['project_name']);
        static::assertSame('rio-wt2', get_project_name($context));
    }

    public function testTheComposeFileIsUsedWhenTheContextSaysNothing(): void
    {
        $this->writeComposeFile('rio');

        $context = initialize_project($this->context());

        static::assertSame('rio', $context->data['project_name']);
        static::assertSame('rio', get_project_name($context));
    }

    /**
     * A compose.yaml without a name, which is what a hand-written one often
     * looks like.
     */
    public function testTheDirectoryNameIsTheLastResort(): void
    {
        file_put_contents($this->directory . '/compose.yaml', "services: {}\n");

        static::assertSame('my-project', initialize_project($this->context())->data['project_name']);
    }

    /**
     * Everything a second checkout needs to keep to itself is derived from the
     * name, so overriding it is enough.
     */
    public function testTheDerivedNamesFollowTheContext(): void
    {
        $this->writeComposeFile('rio');

        $context = initialize_project($this->context(['project_name' => 'rio-wt2']));

        static::assertSame('rio-wt2_default', \Castor\Docker\get_project_network($context));
    }

    /**
     * initialize_project() runs on every boot and has to stay idempotent: it
     * must not start reading the compose file back over a name it just set.
     */
    public function testItIsIdempotent(): void
    {
        $this->writeComposeFile('rio');

        $once = initialize_project($this->context(['project_name' => 'rio-wt2']));
        $twice = initialize_project($once);

        static::assertSame('rio-wt2', $twice->data['project_name']);
    }
}
