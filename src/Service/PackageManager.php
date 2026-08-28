<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

/**
 * The JavaScript package manager the builder image prepares.
 *
 * Corepack is enabled whichever one is chosen, so a `packageManager` field in a
 * package.json is honoured either way; this only decides which one the image
 * carries ready to run for a project declaring nothing.
 */
enum PackageManager: string
{
    case Npm = 'npm';
    case Yarn = 'yarn';
    case Pnpm = 'pnpm';
}
