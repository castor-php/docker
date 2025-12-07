<?php

namespace Castor\Docker\Service;

use Castor\Context;

class RabbitMQService implements ServiceInterface
{
    public function getName(): string
    {
        return 'rabbitmq';
    }

    public function updateCompose(Context $context, array $compose): array
    {
        $projectName = $context->data['project_name'] ?? 'app';
        $rootDomain = $context->data['root_domain'] ?? 'castor.local';

        $compose['volumes']['rabbitmq-data'] = [];
        $compose['services']['rabbitmq'] = [
            'build' => __DIR__ . '/../Resources/rabbitmq',
            'volumes' => [
                'rabbitmq-data:/var/lib/rabbitmq',
            ],
            'labels' => [
                'traefik.enable=true',
                "traefik.http.routers.{$projectName}-rabbitmq.rule=Host(`rabbitmq.{$rootDomain}`)",
                "traefik.http.routers.{$projectName}-rabbitmq.tls=true",
                "traefik.http.services.rabbitmq.loadbalancer.server.port=15672",
            ],
            'healthcheck' => [
                'test' => "rabbitmqctl eval '{ true, rabbit_app_booted_and_running } = { rabbit:is_booted(node()), rabbit_app_booted_and_running }, { [], no_alarms } = { rabbit:alarms(), no_alarms }, [] /= rabbit_networking:active_listeners(), rabbitmq_node_is_healthy.' || exit 1",
                'interval' => '5s',
                'timeout' => '5s',
                'retries' => 5,
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
