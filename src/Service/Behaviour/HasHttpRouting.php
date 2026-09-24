<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Behaviour;

use Castor\Docker\Service\Builder\ServiceBuilder;

/**
 * The domains to serve, whether plain HTTP is allowed, and the container port
 * the router forwards to. Services listening elsewhere than on 80 override
 * getDefaultPort().
 */
trait HasHttpRouting
{
    use HasDomains;
    use HasHttpAccess;

    protected ?int $port = null;

    public function withPort(int $port): static
    {
        $this->port = $port;

        return $this;
    }

    public function getPort(): int
    {
        return $this->port ?? $this->getDefaultPort();
    }

    protected function getDefaultPort(): int
    {
        return 80;
    }

    /**
     * Emit the router labels for the registered domains, if any.
     */
    protected function applyHttpRouting(ServiceBuilder $service): void
    {
        $domains = $this->getDomains();

        if (!$domains) {
            return;
        }

        $service->withHttpRouting($domains, $this->getPort(), $this->isHttpAccessAllowed());
    }
}
