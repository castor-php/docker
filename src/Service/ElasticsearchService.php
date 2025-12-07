<?php

namespace Castor\Docker\Service;

use Castor\Context;

class ElasticsearchService implements ServiceInterface
{
    public function getName(): string
    {
        return 'elasticsearch';
    }

    public function updateCompose(Context $context, array $compose): array
    {
        $projectName = $context->data['project_name'] ?? 'app';
        $rootDomain = $context->data['root_domain'] ?? 'castor.local';

        $compose['volumes']['elasticsearch-data'] = [];
        $compose['services']['elasticsearch'] = [
            'image' => 'elasticsearch:7.8.0',
            'volumes' => [
                'elasticsearch-data:/usr/share/elasticsearch/data',
            ],
            'environment' => [
                'discovery.type=single-node',
            ],
            'labels' => [
                'traefik.enable=true',
                "traefik.http.routers.{$projectName}-elasticsearch.rule=Host(`elasticsearch.{$rootDomain}`)",
                "traefik.http.routers.{$projectName}-elasticsearch.tls=true",
            ],
            'healthcheck' => [
                'test' => ['CMD-SHELL', 'curl --fail http://localhost:9200/_cat/health || exit 1'],
                'interval' => '5s',
                'timeout' => '5s',
                'retries' => 5,
            ],
            'profiles' => ['default'],
        ];
        $compose['services']['kibana'] = [
            'image' => 'kibana:7.8.0',
            'depends_on' => [
                'elasticsearch',
            ],
            'labels' => [
                'traefik.enable=true',
                "project-name={$projectName}",
                "traefik.http.routers.{$projectName}-kibana.rule=Host(`kibana.{$rootDomain}`)",
                "traefik.http.routers.{$projectName}-kibana.tls=true",
            ],
            'profiles' => ['default'],
        ];

        return $compose;
    }

    public function getTasks(): iterable
    {
        return [];
    }
}
