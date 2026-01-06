<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;

class ElasticsearchService implements ServiceInterface
{
    public function __construct(
        private readonly string $version = '7.8.0',
    )
    {
    }

    public function getName(): string
    {
        return 'elasticsearch';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $projectName = $context->data['project_name'] ?? 'app';
        $rootDomain = $context->data['root_domain'] ?? 'castor.local';

        $builder->volume('elasticsearch-data');

        return $builder
            ->service('elasticsearch')
                ->image('elasticsearch:' . $this->version)
                ->volume('elasticsearch-data', '/usr/share/elasticsearch/data')
                ->environment('discovery.type', 'single-node')
                ->withTraefikRouting("{$projectName}-elasticsearch", "elasticsearch.{$rootDomain}", 9200)
                ->healthcheck(['CMD-SHELL', 'curl --fail http://localhost:9200/_cat/health || exit 1'])
                ->profile('default')
            ->end()
            ->service('kibana')
                ->image('kibana:' . $this->version)
                ->dependsOn('elasticsearch', ['condition' => 'service_healthy'])
                ->withTraefikRouting("{$projectName}-kibana", "kibana.{$rootDomain}", 5601)
                ->profile('default')
            ->end()
        ;
    }

    public function getTasks(): iterable
    {
        return [];
    }
}
