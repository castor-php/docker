<?php

namespace project;

use Castor\Attribute\AsContext;
use Castor\Attribute\AsListener;
use Castor\Context;
use Castor\Docker\Event\RegisterServiceEvent;
use Castor\Docker\Service\ElasticsearchService;
use Castor\Docker\Service\MariaDBService;
use Castor\Docker\Service\MySQLService;
use Castor\Docker\Service\PostgresService;
use Castor\Docker\Service\RabbitMQService;
use Castor\Docker\Service\RedirectionioAgentService;
use Castor\Docker\Service\RedisService;
use Castor\Docker\Service\SymfonyService;

#[AsContext(default: true)]
function default_context(): Context
{
    return new Context([
        'root_domain' => 'project.test',
        'registry' => 'ghcr.io/castor-php/docker-example'
    ]);
}

#[AsListener(RegisterServiceEvent::class)]
function register_service(RegisterServiceEvent $event)
{
    $postgresService = new PostgresService();
    $event->addService($postgresService);

    $mysqlService = new MySQLService();
    $event->addService($mysqlService);

    $event->addService(
        (new SymfonyService(name: 'app1', directory: __DIR__ . '/app1'))
            ->withDatabaseService($postgresService)
            ->withDockerfile(__DIR__ . '/Dockerfile')
            ->addDomain('app1.project.test')
            ->addDomain('project.test')
            ->addDomain('localhost')
            ->allowHttpAccess()
            ->addWorker('messenger', 'php -d memory_limit=1G bin/console messenger:consume async --time-limit=3600 --memory-limit=128M')
    );

    $event->addService(
        (new SymfonyService(name: 'app2', directory: __DIR__ . '/app2', version: '8.2'))
            ->withDockerfile(__DIR__ . '/Dockerfile')
            ->withDatabaseService($mysqlService)
            ->withRedirectionIoKey("b02088e2-ef87-4622-8e5e-35d7f553ca9f:707268c4-1e23-4df2-a3d9-1c088e944652")
            ->addDomain('app2.project.test')
    );

    $event->addService(new RabbitMQService());
    $event->addService(new RedisService());
    $event->addService(new ElasticsearchService());
    $event->addService(new RedirectionioAgentService());
}