<?php

namespace Castor\Docker\Service;

use Castor\Context;

class RedisService implements ServiceInterface
{
    public function getName(): string
    {
        return 'redis';
    }

    public function updateCompose(Context $context, array $compose): array
    {
        $projectName = $context->data['project_name'] ?? 'app';
        $rootDomain = $context->data['root_domain'] ?? 'castor.local';

        $compose['volumes']['redis-data'] = [];
        $compose['volumes']['redis-insight-data'] = [];

        $compose['services']['redis'] = [
            'image' => 'redis:5',
            'healthcheck' => [
                'test' => ['CMD', 'redis-cli', 'ping'],
                'interval' => '5s',
                'timeout' => '5s',
                'retries' => 5,
            ],
            'volumes' => [
                'redis-data:/data',
            ],
            'profiles' => ['default'],
        ];

        $compose['services']['redis-insight'] = [
            'image' => 'redislabs/redisinsight',
            'volumes' => [
                'redis-insight-data:/db',
            ],
            'labels' => [
                'traefik.enable=true',
                'traefik.http.routers.' . $projectName . '-redis.rule=Host(`redis.' . $rootDomain . '`)',
                'traefik.http.routers.' . $projectName . '-redis.tls=true',
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
