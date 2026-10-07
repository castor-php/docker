<?php

declare(strict_types=1);

namespace Castor\Docker\Build;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * One line per service, redrawn in place with the step it is at, the way
 * BuildKit shows a single build in a terminal. The log of a failed build is
 * printed in full once they are all done.
 */
final class BoardBuildReporter implements BuildReporter
{
    private const SPINNER = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
    private const REFRESH = 0.05;
    private const SPIN = 0.08;

    /** @var array<string, array{start: float, end: ?float, successful: ?bool, steps: array<int, string>, active: array<int, true>, log: list<string>}> */
    private array $builds = [];
    private int $width;
    private float $renderedAt = 0;

    /**
     * @param non-empty-list<string> $services
     */
    public function __construct(
        private readonly ConsoleSectionOutput $section,
        private readonly OutputInterface $output,
        array $services,
        private readonly ?int $terminalWidth = null,
    ) {
        $this->width = max(array_map(strlen(...), $services));
        $now = microtime(true);

        foreach ($services as $service) {
            $this->builds[$service] = ['start' => $now, 'end' => null, 'successful' => null, 'steps' => [], 'active' => [], 'log' => []];
        }
    }

    public function line(string $service, string $line): void
    {
        $build = &$this->builds[$service];
        $build['log'][] = $line;

        // "#5 [app 2/8] RUN ..." names step 5, then "#5 0.42 <output>" and
        // "#5 DONE 1.2s" follow it.
        if (!preg_match('{^#(\d+) (.+)$}', $line, $matches)) {
            return;
        }

        $id = (int) $matches[1];
        $rest = $matches[2];

        if (preg_match('{^(DONE|CACHED|ERROR|CANCELED)\b}', $rest)) {
            unset($build['active'][$id]);
        } elseif (!isset($build['steps'][$id])) {
            $build['steps'][$id] = $rest;
            $build['active'][$id] = true;
        }
    }

    public function tick(): void
    {
        $now = microtime(true);

        if ($now - $this->renderedAt < self::REFRESH) {
            return;
        }

        $this->renderedAt = $now;
        $this->section->overwrite($this->lines($now));
    }

    public function finish(string $service, bool $successful): void
    {
        $build = &$this->builds[$service];
        $build['end'] = microtime(true);
        $build['successful'] = $successful;
    }

    public function end(): void
    {
        $this->section->overwrite($this->lines(microtime(true)));

        foreach ($this->builds as $service => $build) {
            if (false !== $build['successful']) {
                continue;
            }

            $this->output->writeln('');
            $this->output->writeln(\sprintf('<fg=red>%s</>', $service));

            foreach ($build['log'] as $line) {
                $this->output->writeln(OutputFormatter::escape($line));
            }
        }
    }

    /**
     * @return list<string>
     */
    public function lines(float $now): array
    {
        $frame = self::SPINNER[(int) ($now / self::SPIN) % \count(self::SPINNER)];
        $columns = $this->terminalWidth ?? (new Terminal())->getWidth();
        $lines = [];

        foreach ($this->builds as $service => $build) {
            $elapsed = \sprintf('%.1fs', ($build['end'] ?? $now) - $build['start']);

            [$icon, $text] = match ($build['successful']) {
                true => ['<fg=green>✔</>', 'built'],
                false => ['<fg=red>✘</>', 'failed'],
                null => ["<fg=yellow>{$frame}</>", $this->currentStep($build['steps'], $build['active'])],
            };

            // Icon, two spaces, the name, two spaces, the step, a space, the time.
            $room = max(0, $columns - 1 - 2 - $this->width - 2 - 1 - \strlen($elapsed) - 1);
            $text = Helper::width($text) > $room ? mb_substr($text, 0, max(0, $room - 1)) . '…' : $text;

            $lines[] = \sprintf(
                '%s  %s  %s %s<fg=gray>%s</>',
                $icon,
                str_pad($service, $this->width),
                OutputFormatter::escape($text),
                str_repeat(' ', max(0, $room - Helper::width($text))),
                $elapsed,
            );
        }

        return $lines;
    }

    /**
     * @param array<int, string> $steps
     * @param array<int, true>   $active
     */
    private function currentStep(array $steps, array $active): string
    {
        if ($active) {
            return $steps[array_key_last($active)];
        }

        return $steps ? $steps[array_key_last($steps)] : 'starting';
    }
}
