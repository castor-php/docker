<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

/**
 * The JavaScript package manager the builder image prepares.
 *
 * Corepack is enabled whichever one is chosen, so a `packageManager` field in a
 * package.json still wins; this only decides what a project declaring nothing
 * finds ready to run.
 */
enum PackageManager: string
{
    case Npm = 'npm';
    case Yarn = 'yarn';
    case Pnpm = 'pnpm';
}
