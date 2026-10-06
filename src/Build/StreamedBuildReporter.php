<?php

declare(strict_types=1);

namespace Castor\Docker\Build;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Every line as it comes, prefixed by its service the way "docker compose
 * logs" does: what a CI log, or any output that is not a terminal, can show.
 */
final class StreamedBuildReporter implements BuildReporter
{
    private const COLORS = ['cyan', 'yellow', 'green', 'magenta', 'blue', 'red'];

    /** @var array<string, string> */
    private array $prefixes = [];

    /**
     * @param non-empty-list<string> $services
     */
    public function __construct(private readonly OutputInterface $output, array $services)
    {
        $width = max(array_map(strlen(...), $services));

        foreach ($services as $i => $service) {
            $this->prefixes[$service] = \sprintf('<fg=%s>%s</> | ', self::COLORS[$i % \count(self::COLORS)], str_pad($service, $width));
        }
    }

    public function line(string $service, string $line): void
    {
        $this->output->writeln($this->prefixes[$service] . OutputFormatter::escape($line));
    }

    public function tick(): void {}

    public function finish(string $service, bool $successful): void {}

    public function end(): void {}
}
