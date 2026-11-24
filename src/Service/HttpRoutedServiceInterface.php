<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

/**
 * A service the router serves over HTTP, which the HasHttpRouting behaviour
 * implements: what another service needs to reach it or to allow its pages.
 */
interface HttpRoutedServiceInterface extends ServiceInterface
{
    /**
     * @return list<string>
     */
    public function getDomains(): array;

    public function isHttpAccessAllowed(): bool;

    /**
     * The container port the router forwards to.
     */
    public function getPort(): int;
}
