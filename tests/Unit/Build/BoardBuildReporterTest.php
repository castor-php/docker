<?php

declare(strict_types=1);

namespace Castor\Docker\Tests\Unit\Build;

use Castor\Docker\Build\BoardBuildReporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class BoardBuildReporterTest extends TestCase
{
    public function testEachServiceShowsTheStepItIsAt(): void
    {
        $reporter = $this->reporter(['app', 'app-builder']);

        $reporter->line('app', '#5 [frontend 2/8] RUN apt-get update');
        $reporter->line('app', '#5 0.42 Get:1 http://deb.debian.org/debian trixie InRelease');
        $reporter->line('app-builder', '#4 [builder 1/10] FROM php-base');

        static::assertSame([
            'app          [frontend 2/8] RUN apt-get update',
            'app-builder  [builder 1/10] FROM php-base',
        ], $this->steps($reporter));
    }

    /**
     * BuildKit runs independent steps at once: the board follows the latest
     * one still running, and falls back to the latest one started.
     */
    public function testAFinishedStepGivesWayToTheOneStillRunning(): void
    {
        $reporter = $this->reporter(['app']);

        $reporter->line('app', '#5 [frontend 2/8] RUN apt-get update');
        $reporter->line('app', '#6 [frontend 3/8] COPY . .');
        $reporter->line('app', '#6 DONE 0.1s');

        static::assertSame(['app  [frontend 2/8] RUN apt-get update'], $this->steps($reporter));

        $reporter->line('app', '#5 CACHED');

        static::assertSame(['app  [frontend 3/8] COPY . .'], $this->steps($reporter));
    }

    public function testAFinishedBuildShowsItsOutcome(): void
    {
        $reporter = $this->reporter(['app', 'node']);

        $reporter->finish('app', true);
        $reporter->finish('node', false);

        static::assertSame(['✔  app   built', '✘  node  failed'], $this->steps($reporter, withIcon: true));
    }

    public function testALongStepIsCutToTheWidthOfTheTerminal(): void
    {
        $reporter = $this->reporter(['app'], width: 40);

        $reporter->line('app', '#5 [frontend 2/8] RUN install-php-extensions intl pdo_pgsql redis');

        $line = $this->plain($reporter->lines(microtime(true))[0]);

        static::assertLessThanOrEqual(40, mb_strlen($line));
        static::assertStringContainsString('…', $line);
    }

    public function testTheLogOfAFailedBuildIsPrintedInTheEnd(): void
    {
        $output = new BufferedOutput();
        $reporter = $this->reporter(['app', 'node'], output: $output);

        $reporter->line('app', '#5 [frontend 2/8] RUN true');
        $reporter->line('node', '#5 [node 2/4] RUN npm ci');
        $reporter->line('node', '#5 ERROR: process "npm ci" did not complete successfully');
        $reporter->finish('app', true);
        $reporter->finish('node', false);
        $reporter->end();

        $printed = $output->fetch();

        static::assertStringContainsString('npm ci" did not complete successfully', $printed);
        static::assertStringNotContainsString('RUN true', $printed);
    }

    /**
     * @param non-empty-list<string> $services
     */
    private function reporter(array $services, int $width = 200, ?OutputInterface $output = null): BoardBuildReporter
    {
        $sections = [];
        $section = new ConsoleSectionOutput(fopen('php://memory', 'w'), $sections, OutputInterface::VERBOSITY_NORMAL, false, new OutputFormatter());

        return new BoardBuildReporter($section, $output ?? new BufferedOutput(), $services, $width);
    }

    /**
     * The lines without their spinner nor elapsed time, which move.
     *
     * @return list<string>
     */
    private function steps(BoardBuildReporter $reporter, bool $withIcon = false): array
    {
        return array_map(function (string $line) use ($withIcon): string {
            $line = rtrim(preg_replace('{\s+\d+\.\ds$}', '', $this->plain($line)) ?? '');

            return $withIcon ? $line : mb_substr($line, 3);
        }, $reporter->lines(microtime(true)));
    }

    private function plain(string $line): string
    {
        return (new OutputFormatter())->format($line) ?? '';
    }
}
