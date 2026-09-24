<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

/**
 * A linkable service that needs to know who links to it — the Mercure hub,
 * which has to allow the origins of the applications it answers.
 */
interface LinkAwareServiceInterface extends LinkableServiceInterface
{
    /**
     * Called by link(), once per service linking to this one.
     */
    public function linkedFrom(ServiceInterface $service): void;
}
