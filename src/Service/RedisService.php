<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Context;
use Castor\Docker\Service\Behaviour\HasName;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\expose_task;

use const Castor\Docker\DEFAULT_ROOT_DOMAIN;

class RedisService implements LinkableServiceInterface
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

    public function getInsightName(): string
    {
        return $this->getName() . '-insight';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $rootDomain = $context->data['root_domain'] ?? DEFAULT_ROOT_DOMAIN;
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
        yield expose_task($this, 6379);
    }

    public function getLinkEnvironment(Context $context): array
    {
        return [
            // Symfony's cache and lock, Predis, phpredis through a DSN.
            'REDIS_URL' => 'redis://' . $this->getName() . ':6379',
        ];
    }

    public function getLinkDependencies(): array
    {
        return [$this->getName() => 'service_healthy'];
    }
}
