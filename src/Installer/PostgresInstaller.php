<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Service\PostgresService;
use Castor\Docker\Service\ServiceInterface;

final class PostgresInstaller extends AbstractServiceInstaller implements DatabaseServiceInstaller
{
    public function getName(): string
    {
        return 'postgres';
    }

    public function getDescription(): string
    {
        return 'PostgreSQL database server';
    }

    public function buildStatements(ServiceStatementBuilder $builder, array $answers): void
    {
        $builder->addNewServiceAst(PostgresService::class);
    }

    public function createInstance(array $answers): ServiceInterface
    {
        return new PostgresService();
    }
}
