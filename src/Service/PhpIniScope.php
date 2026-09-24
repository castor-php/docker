<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

/**
 * Which PHP of an application a set of ini directives applies to. The two run
 * in different containers and rarely want the same settings: a worker is happy
 * without a memory or time limit, a page served to a browser is not.
 */
enum PhpIniScope: string
{
    /** The builder container and the workers, the PHP running commands. */
    case Cli = 'cli';

    /** The application container, whether it serves with FPM or FrankenPHP. */
    case Web = 'web';

    case All = 'all';
}
