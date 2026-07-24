<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

/**
 * Marks an installer that provides a database service (implements
 * DatabaseServiceInterface), so it can be offered when linking an app.
 */
interface DatabaseServiceInstaller extends ServiceInstaller {}
