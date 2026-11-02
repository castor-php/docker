<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Behaviour;

use Castor\Context;

/**
 * What a database hands to the services linked to it: DATABASE_URL, the name
 * Doctrine, Laravel and most frameworks read, and a start once it is ready.
 */
trait HasDatabaseLink
{
    public function getLinkEnvironment(Context $context): array
    {
        return ['DATABASE_URL' => $this->getDatabaseURL()];
    }

    public function getLinkDependencies(): array
    {
        return [$this->getName() => 'service_healthy'];
    }

    abstract public function getDatabaseURL(): string;

    abstract public function getName(): string;
}
