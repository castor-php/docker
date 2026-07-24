<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\expose_service_port;

class RabbitMQService implements ServiceInterface
{
    public function getName(): string
    {
        return 'rabbitmq';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $rootDomain = $context->data['root_domain'] ?? 'castor.local';

        return $builder
            ->volume('rabbitmq-data')
            ->service('rabbitmq')
                ->build(__DIR__ . '/../Resources/rabbitmq')->end()
                ->volume('rabbitmq-data', '/var/lib/rabbitmq')
                ->withHttpRouting("rabbitmq.{$rootDomain}", 15672)
                ->healthcheck("rabbitmqctl eval '{ true, rabbit_app_booted_and_running } = { rabbit:is_booted(node()), rabbit_app_booted_and_running }, { [], no_alarms } = { rabbit:alarms(), no_alarms }, [] /= rabbit_networking:active_listeners(), rabbitmq_node_is_healthy.' || exit 1")
                ->profile('default')
            ->end()
        ;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('expose', $this->getName(), description: 'Expose the rabbitmq service (AMQP) over TCP on the host (--stop to stop)'),
            'function' => function (
                #[AsArgument(description: 'Host port to expose on (defaults to the service port)')]
                ?int $port = null,
                bool $stop = false,
            ): void {
                expose_service_port($this->getName(), 5672, $port, $stop);
            },
        ];
    }
}
