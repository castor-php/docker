<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit;

use Castor\Context;
use PHPUnit\Framework\TestCase;

use function Castor\Docker\get_compose_service_names;

/**
 * The shell completion of the "service" argument reads the compose files rather
 * than asking docker: it has to answer instantly, and must not need a running
 * daemon.
 */
final class ComposeServiceNamesTest extends TestCase
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

    private function write(string $file, string $content): void
    {
        file_put_contents($this->directory . '/' . $file, $content);
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return get_compose_service_names(new Context(workingDirectory: $this->directory));
    }

    public function testNoComposeFileYieldsNothing(): void
    {
        static::assertSame([], $this->names());
    }

    /**
     * The services a project writes itself count too, not only the generated
     * ones — completion that only knew half of them would be worse than none.
     */
    public function testTheThreeComposeFilesAreMerged(): void
    {
        $this->write('compose.generated.yaml', "services:\n    postgres: {}\n    app: {}\n");
        $this->write('compose.yaml', "name: demo\nservices:\n    legacy: {}\n");
        $this->write('compose.override.yaml', "services:\n    app: {}\n    mailhog: {}\n");

        static::assertSame(['app', 'legacy', 'mailhog', 'postgres'], $this->names());
    }

    /**
     * compose.override.yaml is created holding nothing but a comment, and
     * compose.yaml often declares no service of its own.
     */
    public function testFilesWithoutServicesAreIgnored(): void
    {
        $this->write('compose.generated.yaml', "services:\n    app: {}\n");
        $this->write('compose.override.yaml', "# This file is for your local overrides.\n");
        $this->write('compose.yaml', "name: demo\ninclude:\n    - path: compose.generated.yaml\n");

        static::assertSame(['app'], $this->names());
    }
}
