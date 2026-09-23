<?php

declare(strict_types=1);

namespace Castor\Docker\Doctor;

enum Status: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
    /** The check could not run: what it needs is missing, and reported elsewhere. */
    case Skipped = 'skipped';

    public function symbol(): string
    {
        return match ($this) {
            self::Ok => '<fg=green>✔</>',
            self::Warning => '<fg=yellow>⚠</>',
            self::Error => '<fg=red>✘</>',
            self::Skipped => '<fg=gray>–</>',
        };
    }
}
