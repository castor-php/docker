<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

interface DatabaseServiceInterface extends LinkableServiceInterface
{
    public function getDatabaseURL(): string;

    public function hasHealthCheck(): bool;
}
