<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Service\ClickhouseService;
use Castor\Docker\Service\ServiceInterface;

final class ClickhouseInstaller extends AbstractServiceInstaller
{
    public function getName(): string
    {
        return 'clickhouse';
    }

    public function getDescription(): string
    {
        return 'ClickHouse columnar database';
    }

    public function getInputs(): array
    {
        return [
            new Input('version', 'ClickHouse version', InputType::Text, '25.8'),
        ];
    }

    public function buildStatements(ServiceStatementBuilder $builder, array $answers): void
    {
        $builder->addNewServiceAst(ClickhouseService::class, [(string) $answers['version']]);
    }

    public function createInstance(array $answers): ServiceInterface
    {
        return new ClickhouseService((string) $answers['version']);
    }
}
