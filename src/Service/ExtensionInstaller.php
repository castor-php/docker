<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

/**
 * How addExtension() gets a module into the image.
 */
enum ExtensionInstaller: string
{
    /**
     * Whatever the mode installs with: the sury packages for PhpMode::Fpm, the
     * install-php-extensions catalogue for PhpMode::FrankenPhp. Prebuilt, so
     * the fast one, and the reason it is the default.
     */
    case Mode = 'mode';

    /**
     * PIE, which builds the extension from its sources while the image is
     * built. For a module the installer of the mode does not carry, or a
     * version it does not offer yet.
     */
    case Pie = 'pie';
}
