<?php

declare(strict_types=1);

namespace Castor\Docker\Event;

use Castor\Docker\Service\ServiceInterface;

final class RegisterServiceEvent
{
    /** @var ServiceInterface[] */
    public array $services = [];

    public function addService(ServiceInterface $service): void
    {
        $this->services[] = $service;
    }
}
