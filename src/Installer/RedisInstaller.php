<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Service\RedisService;
use Castor\Docker\Service\ServiceInterface;

final class RedisInstaller extends AbstractServiceInstaller
{
    public function getName(): string
    {
        return 'redis';
    }

    public function getDescription(): string
    {
        return 'Redis server with RedisInsight UI';
    }

    public function buildStatements(ServiceStatementBuilder $builder, array $answers): void
    {
        $builder->addNewServiceAst(RedisService::class);
    }

    public function createInstance(array $answers): ServiceInterface
    {
        return new RedisService();
    }
}
