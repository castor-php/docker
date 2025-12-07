<?php

namespace Castor\Docker\Service;

interface DatabaseServiceInterface extends ServiceInterface
{
    public function getDatabaseURL(): string;

    public function hasHealthCheck(): bool;
}
