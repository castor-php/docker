<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;

class RabbitMQService implements ServiceInterface
{
    public function getName(): string
    {
        return 'rabbitmq';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $projectName = $context->data['project_name'] ?? 'app';
        $rootDomain = $context->data['root_domain'] ?? 'castor.local';

        return $builder
            ->volume('rabbitmq-data')
            ->service('rabbitmq')
                ->build(__DIR__ . '/../Resources/rabbitmq')->end()
                ->volume('rabbitmq-data', '/var/lib/rabbitmq')
                ->withTraefikRouting("{$projectName}-rabbitmq", "rabbitmq.{$rootDomain}", 15672)
                ->healthcheck("rabbitmqctl eval '{ true, rabbit_app_booted_and_running } = { rabbit:is_booted(node()), rabbit_app_booted_and_running }, { [], no_alarms } = { rabbit:alarms(), no_alarms }, [] /= rabbit_networking:active_listeners(), rabbitmq_node_is_healthy.' || exit 1")
                ->profile('default')
            ->end()
        ;
    }

    public function getTasks(): iterable
    {
        return [];
    }
}
