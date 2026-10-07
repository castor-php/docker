<?php

declare(strict_types=1);

namespace Castor\Docker\Build;

/**
 * Shows several builds running at once, fed line by line.
 */
interface BuildReporter
{
    public function line(string $service, string $line): void;

    /**
     * Called every few milliseconds while the builds run, to animate what needs to be.
     */
    public function tick(): void;

    public function finish(string $service, bool $successful): void;

    public function end(): void;
}
