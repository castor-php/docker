<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class OtherStackProjectTest extends TestCase
{
    private const PROJECT = __DIR__ . '/../fixtures/other-stack-project';

    private const WRITTEN = ['compose.yaml', 'compose.generated.yaml', 'compose.override.yaml', '.home', 'api'];

    private string $castor;

    protected function setUp(): void
    {
        $castor = getenv('CASTOR_BINARY') ?: (new ExecutableFinder())->find('castor');

        if (!$castor) {
            static::markTestSkipped('No castor binary found: install castor or set CASTOR_BINARY.');
        }

        $this->castor = $castor;

        if (!is_dir(self::PROJECT . '/.castor/vendor')) {
            $install = new Process([$castor, 'composer', 'install'], self::PROJECT, timeout: 300);
            $install->run();

            if (!$install->isSuccessful()) {
                static::markTestSkipped('Could not install the fixture dependencies: ' . $install->getErrorOutput());
            }
        }

        foreach (['compose.yaml', 'compose.generated.yaml', 'compose.override.yaml'] as $file) {
            @unlink(self::PROJECT . '/' . $file);
        }

        foreach (['.home', 'api'] as $directory) {
            @rmdir(self::PROJECT . '/' . $directory);
        }
    }

    public function testNothingIsWrittenAndTheServiceTasksRemain(): void
    {
        $process = $this->castor(['postgres:client', '--help']);

        static::assertTrue($process->isSuccessful(), "castor postgres:client --help failed:\n" . $process->getOutput() . $process->getErrorOutput());

        foreach (self::WRITTEN as $path) {
            static::assertFileDoesNotExist(self::PROJECT . '/' . $path, \sprintf('"%s" must not be written when "generate_compose_files" is false.', $path));
        }
    }

    public function testTheTasksOfThePluginStackStop(): void
    {
        $process = $this->castor(['docker:ps']);

        static::assertFalse($process->isSuccessful());
        static::assertStringContainsString('generate_compose_files', $process->getOutput() . $process->getErrorOutput());
        static::assertFileDoesNotExist(self::PROJECT . '/compose.yaml');
    }

    /**
     * @param list<string> $arguments
     */
    private function castor(array $arguments): Process
    {
        $process = new Process([$this->castor, ...$arguments, '--no-interaction'], self::PROJECT, timeout: 120);
        $process->run();

        return $process;
    }
}
