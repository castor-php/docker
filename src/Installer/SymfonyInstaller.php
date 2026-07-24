<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

use Castor\Docker\Installer\Ast\Ast;
use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Service\DatabaseServiceInterface;
use Castor\Docker\Service\PhpMode;
use Castor\Docker\Service\ServiceInterface;
use Castor\Docker\Service\SymfonyService;

use function Castor\context;
use function Castor\Docker\docker_compose_run;
use function Castor\fs;

final class SymfonyInstaller extends AbstractServiceInstaller implements NeedsDatabase
{
    public function getName(): string
    {
        return 'symfony';
    }

    public function getDescription(): string
    {
        return 'Symfony application (FrankenPHP or PHP-FPM)';
    }

    public function getInputs(): array
    {
        return [
            new Input('name', 'Application name', InputType::Text, 'app'),
            new Input('directory', 'Directory (relative to castor.php)', InputType::Text, static fn(array $answers): string => (string) ($answers['name'] ?? 'app')),
            new Input('version', 'PHP version', InputType::Text, '8.5'),
            new Input('mode', 'Runtime', InputType::Choice, PhpMode::FrankenPhp->value, [PhpMode::FrankenPhp->value, PhpMode::Fpm->value]),
            new Input('domain', 'Domain', InputType::Text, static fn(array $answers): string => \sprintf('%s.%s', $answers['name'] ?? 'app', context()->data['root_domain'] ?? 'castor.local')),
            new Input('symfony_version', 'Symfony version (empty for latest)', InputType::Text, ''),
        ];
    }

    public function buildStatements(ServiceStatementBuilder $builder, array $answers): void
    {
        $expression = $builder->addNewServiceAst(SymfonyService::class, [
            'name' => (string) $answers['name'],
            'directory' => Ast::raw(\sprintf("__DIR__ . '/%s'", $answers['directory'])),
            'version' => (string) $answers['version'],
            'mode' => Ast::raw('PhpMode::' . PhpMode::from((string) $answers['mode'])->name),
        ]);
        $builder->addImport(PhpMode::class);

        if (($answers['domain'] ?? '') !== '') {
            $expression->callMethod('addDomain', [(string) $answers['domain']]);
        }

        if (($answers['database'] ?? null) !== null) {
            $expression->callMethod('withDatabaseService', [Ast::var((string) $answers['database'])]);
        }
    }

    public function createInstance(array $answers): ServiceInterface
    {
        $service = new SymfonyService(
            name: (string) $answers['name'],
            directory: context()->workingDirectory . '/' . $answers['directory'],
            version: (string) $answers['version'],
            mode: PhpMode::from((string) $answers['mode']),
        );

        if (($answers['domain'] ?? '') !== '') {
            $service->addDomain((string) $answers['domain']);
        }

        if (($answers['database_instance'] ?? null) instanceof DatabaseServiceInterface) {
            $service->withDatabaseService($answers['database_instance']);
        }

        return $service;
    }

    public function prepare(array $answers): void
    {
        fs()->mkdir(context()->workingDirectory . '/' . $answers['directory']);
    }

    public function scaffold(array $answers): void
    {
        $version = (string) $answers['symfony_version'];
        $package = 'symfony/skeleton' . ($version !== '' ? ':' . $version : '');

        docker_compose_run(
            \sprintf('composer create-project %s . --no-interaction', $package),
            service: $answers['name'] . '-builder',
            workDir: '/var/www',
        );
    }
}
