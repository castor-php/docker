<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;

class RedisService implements ServiceInterface
{
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
                ->image('redis:5')
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
        return [];
    }
}
