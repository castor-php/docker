<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;

class ElasticsearchService implements ServiceInterface
{
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
                ->image('elasticsearch:7.8.0')
                ->volume('elasticsearch-data', '/usr/share/elasticsearch/data')
                ->environment('discovery.type', 'single-node')
                ->withTraefikRouting("{$projectName}-elasticsearch", "elasticsearch.{$rootDomain}", 9200)
                ->healthcheck(['CMD-SHELL', 'curl --fail http://localhost:9200/_cat/health || exit 1'])
                ->profile('default')
            ->end()
            ->service('kibana')
                ->image('kibana:7.8.0')
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
