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

class RabbitMQService implements ServiceInterface
{
    use HasName;
    use HasVersion;

    protected function getDefaultVersion(): string
    {
        return '4.3';
    }

    protected function getDefaultName(): string
    {
        return 'rabbitmq';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $rootDomain = $context->data['root_domain'] ?? 'castor.local';

        $name = $this->getName();

        return $builder
            ->volume($name . '-data')
            ->service($name)
                ->build(__DIR__ . '/../Resources/rabbitmq')
                    ->arg('rabbitmq_version', $this->getVersion())
                ->end()
                // Stable across recreates, so the node keeps its data.
                ->environment('RABBITMQ_NODENAME', 'rabbit@localhost')
                ->volume($name . '-data', '/var/lib/rabbitmq')
                ->withHttpRouting("{$name}.{$rootDomain}", 15672)
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
