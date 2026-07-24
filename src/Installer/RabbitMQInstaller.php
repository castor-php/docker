<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Service\RabbitMQService;
use Castor\Docker\Service\ServiceInterface;

final class RabbitMQInstaller extends AbstractServiceInstaller
{
    public function getName(): string
    {
        return 'rabbitmq';
    }

    public function getDescription(): string
    {
        return 'RabbitMQ message broker with management UI';
    }

    public function buildStatements(ServiceStatementBuilder $builder, array $answers): void
    {
        $builder->addNewServiceAst(RabbitMQService::class);
    }

    public function createInstance(array $answers): ServiceInterface
    {
        return new RabbitMQService();
    }
}
