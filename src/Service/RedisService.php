<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\expose_service_port;

class RedisService implements ServiceInterface
{
    use HasVersion;

    protected function getDefaultVersion(): string
    {
        return '5';
    }

    public function getName(): string
    {
        return 'redis';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $rootDomain = $context->data['root_domain'] ?? 'castor.local';

        return $builder
            ->volume('redis-data')
            ->volume('redis-insight-data')
            ->service('redis')
                ->image('redis:' . $this->getVersion())
                ->volume('redis-data', '/data')
                ->healthcheck(['CMD', 'redis-cli', 'ping'])
                ->profile('default')
            ->end()
            ->service('redis-insight')
                ->image('redislabs/redisinsight')
                ->volume('redis-insight-data', '/db')
                ->withHttpRouting("redis.{$rootDomain}")
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
