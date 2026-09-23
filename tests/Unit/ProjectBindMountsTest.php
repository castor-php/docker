<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use Castor\Context;
use PHPUnit\Framework\TestCase;

use function Castor\Docker\get_project_bind_mounts;

/**
 * The directories "docker:doctor" checks the permissions of: the ones the
 * project bind-mounts from its own tree, read back from its compose files.
 */
final class ProjectBindMountsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'compose');
        \assert($directory !== false);
        unlink($directory);
        mkdir($directory);

        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testTheMountsOfTheProjectAreFound(): void
    {
        file_put_contents($this->directory . '/compose.generated.yaml', <<<YAML
            services:
                app:
                    volumes:
                        - '{$this->directory}:/var/www:cached'
                        - '.home:/home/app:cached'
                        - 'postgres_data:/var/lib/postgresql'
                        - '/var/run/docker.sock:/var/run/docker.sock'
            volumes:
                postgres_data: ~
            YAML);
        file_put_contents($this->directory . '/compose.override.yaml', <<<'YAML'
            services:
                app:
                    volumes:
                        - type: bind
                          source: ./var/uploads
                          target: /uploads
                        - type: volume
                          source: cache
                          target: /cache
                        - '${HOME}/.ssh:/home/app/.ssh'
            YAML);

        static::assertSame(
            [$this->directory, $this->directory . '/.home', $this->directory . '/var/uploads'],
            get_project_bind_mounts(new Context(workingDirectory: $this->directory)),
        );
    }

    public function testNoComposeFileMountsNothing(): void
    {
        static::assertSame([], get_project_bind_mounts(new Context(workingDirectory: $this->directory)));
    }
}
