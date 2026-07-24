<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Service\ElasticsearchService;
use Castor\Docker\Service\ServiceInterface;

final class ElasticsearchInstaller extends AbstractServiceInstaller
{
    public function getName(): string
    {
        return 'elasticsearch';
    }

    public function getDescription(): string
    {
        return 'Elasticsearch search engine with Kibana';
    }

    public function getInputs(): array
    {
        return [
            new Input('version', 'Elasticsearch version', InputType::Text, '7.8.0'),
        ];
    }

    public function buildStatements(ServiceStatementBuilder $builder, array $answers): void
    {
        $builder->addNewServiceAst(ElasticsearchService::class, ['version' => (string) $answers['version']]);
    }

    public function createInstance(array $answers): ServiceInterface
    {
        return new ElasticsearchService((string) $answers['version']);
    }
}
