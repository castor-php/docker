<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Service\MySQLService;
use Castor\Docker\Service\ServiceInterface;

final class MySQLInstaller extends AbstractServiceInstaller implements DatabaseServiceInstaller
{
    public function getName(): string
    {
        return 'mysql';
    }

    public function getDescription(): string
    {
        return 'MySQL database server';
    }

    public function getInputs(): array
    {
        return [
            new Input('version', 'MySQL version', InputType::Text, '8'),
        ];
    }

    public function buildStatements(ServiceStatementBuilder $builder, array $answers): void
    {
        $builder->addNewServiceAst(MySQLService::class)
            ->callMethod('withVersion', [(string) $answers['version']]);
    }

    public function createInstance(array $answers): ServiceInterface
    {
        return (new MySQLService())->withVersion((string) $answers['version']);
    }
}
