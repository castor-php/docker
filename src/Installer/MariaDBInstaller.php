<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Service\MariaDBService;
use Castor\Docker\Service\ServiceInterface;

final class MariaDBInstaller extends AbstractServiceInstaller implements DatabaseServiceInstaller
{
    public function getName(): string
    {
        return 'mariadb';
    }

    public function getDescription(): string
    {
        return 'MariaDB database server';
    }

    public function getInputs(): array
    {
        return [
            new Input('version', 'MariaDB version', InputType::Text, '12.1'),
        ];
    }

    public function buildStatements(ServiceStatementBuilder $builder, array $answers): void
    {
        $builder->addNewServiceAst(MariaDBService::class)
            ->callMethod('withVersion', [(string) $answers['version']]);
    }

    public function createInstance(array $answers): ServiceInterface
    {
        return (new MariaDBService())->withVersion((string) $answers['version']);
    }
}
