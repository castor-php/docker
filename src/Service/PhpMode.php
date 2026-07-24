<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

enum PhpMode: string
{
    case Fpm = 'fpm';
    case FrankenPhp = 'frankenphp';
}
