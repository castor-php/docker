<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Service\MeilisearchService;
use Castor\Docker\Service\ServiceInterface;

final class MeilisearchInstaller extends AbstractServiceInstaller
{
    public function getName(): string
    {
        return 'meilisearch';
    }

    public function getDescription(): string
    {
        return 'Meilisearch search engine with its dashboard';
    }

    public function getInputs(): array
    {
        return [
            new Input('version', 'Meilisearch version (image tag)', InputType::Text, 'v1.54'),
        ];
    }

    public function buildStatements(ServiceStatementBuilder $builder, array $answers): void
    {
        $builder->addNewServiceAst(MeilisearchService::class)
            ->callMethod('withVersion', [(string) $answers['version']]);
    }

    public function createInstance(array $answers): ServiceInterface
    {
        return (new MeilisearchService())->withVersion((string) $answers['version']);
    }
}
