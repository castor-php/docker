<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasName;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\expose_service_port;

class ElasticsearchService implements ServiceInterface
{
    use HasName;
    use HasVersion;

    protected function getDefaultVersion(): string
    {
        return '7.8.0';
    }

    protected function getDefaultName(): string
    {
        return 'elasticsearch';
    }

    /**
     * The Kibana container that comes with this instance. Unlike the other
     * generated names it is not derived from the service one, so the first
     * instance keeps the plain "kibana" it has always had.
     */
    public function getKibanaName(): string
    {
        return $this->hasDefaultName() ? 'kibana' : $this->getName() . '-kibana';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $rootDomain = $context->data['root_domain'] ?? 'castor.local';

        $name = $this->getName();
        $kibana = $this->getKibanaName();

        return $builder
            ->volume($name . '-data')
            ->service($name)
                ->image('elasticsearch:' . $this->getVersion())
                ->volume($name . '-data', '/usr/share/elasticsearch/data')
                ->environment('discovery.type', 'single-node')
                ->withHttpRouting("{$name}.{$rootDomain}", 9200)
                ->healthcheck(['CMD-SHELL', 'curl --fail http://localhost:9200/_cat/health || exit 1'])
                ->profile('default')
            ->end()
            ->service($kibana)
                ->image('kibana:' . $this->getVersion())
                ->dependsOn($name, ['condition' => 'service_healthy'])
                ->withHttpRouting("{$kibana}.{$rootDomain}", 5601)
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
