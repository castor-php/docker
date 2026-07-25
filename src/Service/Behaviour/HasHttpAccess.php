<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Behaviour;

/**
 * For services that may also be served over plain HTTP, without the automatic
 * redirection to HTTPS.
 */
trait HasHttpAccess
{
    private bool $allowHttpAccess = false;

    public function withHttpAccess(bool $allow = true): static
    {
        $this->allowHttpAccess = $allow;

        return $this;
    }

    public function isHttpAccessAllowed(): bool
    {
        return $this->allowHttpAccess;
    }
}
