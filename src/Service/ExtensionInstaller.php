<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

/**
 * How addExtension() gets a module into the image.
 */
enum ExtensionInstaller: string
{
    /**
     * The sury packages for PhpMode::Fpm, the install-php-extensions catalogue
     * for PhpMode::FrankenPhp. Prebuilt, hence the default.
     */
    case Mode = 'mode';

    /**
     * Built from sources while the image is, for a module the installer of the
     * mode does not carry or a version it does not offer yet.
     */
    case Pie = 'pie';
}
