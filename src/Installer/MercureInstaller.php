<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Service\MercureService;
use Castor\Docker\Service\ServiceInterface;

/**
 * Registers the hub. Where it runs — a container, or the FrankenPHP
 * application linked to it — is decided by the services linked to it later.
 */
final class MercureInstaller extends AbstractServiceInstaller
{
    public function getName(): string
    {
        return 'mercure';
    }

    public function getDescription(): string
    {
        return 'Mercure hub for real-time updates, with its debug UI';
    }

    public function buildStatements(ServiceStatementBuilder $builder, array $answers): void
    {
        $builder->addNewServiceAst(MercureService::class);
    }

    public function createInstance(array $answers): ServiceInterface
    {
        return new MercureService();
    }
}
