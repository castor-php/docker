<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\expose_service_port;

class ElasticsearchService implements ServiceInterface
{
    use HasVersion;

    protected function getDefaultVersion(): string
    {
        return '7.8.0';
    }

    public function getName(): string
    {
        return 'elasticsearch';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $rootDomain = $context->data['root_domain'] ?? 'castor.local';

        return $builder
            ->volume('elasticsearch-data')
            ->service('elasticsearch')
                ->image('elasticsearch:' . $this->getVersion())
                ->volume('elasticsearch-data', '/usr/share/elasticsearch/data')
                ->environment('discovery.type', 'single-node')
                ->withHttpRouting("elasticsearch.{$rootDomain}", 9200)
                ->healthcheck(['CMD-SHELL', 'curl --fail http://localhost:9200/_cat/health || exit 1'])
                ->profile('default')
            ->end()
            ->service('kibana')
                ->image('kibana:' . $this->getVersion())
                ->dependsOn('elasticsearch', ['condition' => 'service_healthy'])
                ->withHttpRouting("kibana.{$rootDomain}", 5601)
                ->profile('default')
            ->end()
        ;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('expose', $this->getName(), description: 'Expose the elasticsearch service over TCP on the host (--stop to stop)'),
            'function' => function (
                #[AsArgument(description: 'Host port to expose on (defaults to the service port)')]
                ?int $port = null,
                bool $stop = false,
            ): void {
                expose_service_port($this->getName(), 9200, $port, $stop);
            },
        ];
    }
}
