<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

class MariaDBService extends AbstractMysqlFamilyService
{
    protected function getDefaultVersion(): string
    {
        return '12.3.2';
    }

    protected function getDefaultName(): string
    {
        return 'mariadb';
    }

    protected function isMariaDB(): bool
    {
        return true;
    }

    protected function getHealthcheck(): string
    {
        return 'healthcheck.sh --connect';
    }

    /**
     * Doctrine rejects an incomplete MariaDB version: omit it rather.
     */
    protected function getServerVersion(): ?string
    {
        $version = $this->getVersionNumber();

        return null !== $version && substr_count($version, '.') >= 2 ? 'mariadb-' . $version : null;
    }
}
