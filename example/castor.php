<?php

namespace project;

use Castor\Attribute\AsContext;
use Castor\Attribute\AsListener;
use Castor\Context;
use Castor\Docker\Event\RegisterServiceEvent;
use Castor\Docker\Service\ElasticsearchService;
use Castor\Docker\Service\MySQLService;
use Castor\Docker\Service\PostgresService;
use Castor\Docker\Service\RabbitMQService;
use Castor\Docker\Service\RedisService;
use Castor\Docker\Service\SymfonyService;

#[AsContext(default: true)]
function default_context(): Context
{
    return new Context([
        'project_name' => 'castor-docker-demo',
        'root_domain' => 'project.test',
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
            ->addDomain('app1.project.test')
            ->addDomain('project.test')
            ->addDomain('localhost')
            ->allowHttpAccess()
            ->addWorker('messenger', 'php -d memory_limit=1G bin/console messenger:consume async --time-limit=3600 --memory-limit=128M')
    );

    $event->addService(
        (new SymfonyService(name: 'app2', directory: __DIR__ . '/app2', version: '8.2'))
            ->withDatabaseService($mysqlService)
            ->addDomain('app2.project.test')
    );

    $event->addService(new RabbitMQService());
    $event->addService(new RedisService());
    $event->addService(new ElasticsearchService());
}