<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

/**
 * Base class providing no-op defaults for the optional install steps, so most
 * installers only implement the core methods.
 */
abstract class AbstractServiceInstaller implements ServiceInstaller
{
    public function getInputs(): array
    {
        return [];
    }

    public function prepare(array $answers): void {}

    public function scaffold(array $answers): void {}

    public function postUp(array $answers): void {}
}
