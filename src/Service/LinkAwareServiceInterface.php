<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

/**
 * A linkable service that needs to know who links to it — the Mercure hub,
 * which answers the browsers of the applications linked to it and so has to
 * allow their origins.
 */
interface LinkAwareServiceInterface extends LinkableServiceInterface
{
    /**
     * Called by link(), once per service linking to this one.
     */
    public function linkedFrom(ServiceInterface $service): void;
}
