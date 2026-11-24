<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

class MySQLService extends AbstractMysqlFamilyService
{
    protected function getDefaultVersion(): string
    {
        return '9.7.2';
    }

    protected function getDefaultName(): string
    {
        return 'mysql';
    }

    protected function isMariaDB(): bool
    {
        return false;
    }

    protected function getHealthcheck(): string
    {
        return 'mysqladmin ping -h 127.0.0.1 --silent';
    }

    protected function getServerVersion(): ?string
    {
        return $this->getVersionNumber();
    }
}
