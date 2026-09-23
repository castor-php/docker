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

class RedisService implements ServiceInterface
{
    use HasName;
    use HasVersion;

    protected function getDefaultVersion(): string
    {
        return '8.10';
    }

    protected function getDefaultName(): string
    {
        return 'redis';
    }

    /**
     * The RedisInsight container that comes with this instance.
     */
    public function getInsightName(): string
    {
        return $this->getName() . '-insight';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $rootDomain = $context->data['root_domain'] ?? 'castor.local';
        $name = $this->getName();
        $insight = $this->getInsightName();

        return $builder
            ->volume($name . '-data')
            ->volume($insight . '-data')
            ->service($name)
                ->image('redis:' . $this->getVersion())
                ->volume($name . '-data', '/data')
                ->healthcheck(['CMD', 'redis-cli', 'ping'])
                ->profile('default')
            ->end()
            ->service($insight)
                ->image('redis/redisinsight')
                ->volume($insight . '-data', '/data')
                ->withHttpRouting("{$name}.{$rootDomain}", 5540)
                ->healthcheck(['CMD', 'wget', '--quiet', '--spider', 'http://127.0.0.1:5540/api/health'], startPeriod: '1m')
                ->profile('default')
            ->end()
        ;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('expose', $this->getName(), description: 'Expose the redis service over TCP on the host (--stop to stop)'),
            'function' => function (
                #[AsArgument(description: 'Host port to expose on (defaults to the service port)')]
                ?int $port = null,
                bool $stop = false,
            ): void {
                expose_service_port($this->getName(), 6379, $port, $stop);
            },
        ];
    }
}
