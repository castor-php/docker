<?php

declare(strict_types=1);

namespace Castor\Docker\Event;

use Castor\Docker\Installer\ServiceInstaller;

/**
 * Collects the installers available to "docker:service:install". The plugin
 * registers the built-in ones; other plugins add their own by listening to this
 * event.
 */
final class RegisterServiceInstallerEvent
{
    /** @var array<string, ServiceInstaller> */
    public array $installers = [];

    public function addInstaller(ServiceInstaller $installer): void
    {
        $this->installers[$installer->getName()] = $installer;
    }
}
