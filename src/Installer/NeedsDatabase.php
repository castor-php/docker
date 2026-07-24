<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

/**
 * Marks an installer whose service can be linked to a database. The install
 * command resolves a database (existing or freshly installed) and passes its
 * variable name to buildStatements() under the "database" answer.
 */
interface NeedsDatabase {}
