<?php

namespace project;

use Castor\Attribute\AsContext;
use Castor\Attribute\AsListener;
use Castor\Context;
use Castor\Docker\Event\RegisterServiceEvent;
use Castor\Docker\Service\ClickhouseService;
use Castor\Docker\Service\ElasticsearchService;
use Castor\Docker\Service\GoService;
use Castor\Docker\Service\MariaDBService;
use Castor\Docker\Service\MySQLService;
use Castor\Docker\Service\PhpMode;
use Castor\Docker\Service\PostgresService;
use Castor\Docker\Service\RabbitMQService;
use Castor\Docker\Service\RedirectionioAgentService;
use Castor\Docker\Service\RedisService;
use Castor\Docker\Service\RustService;
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
        (new SymfonyService('app1'))
            ->withDirectory(__DIR__ . '/app1')
            ->withMode(PhpMode::FrankenPhp)
            ->withDatabaseService($postgresService)
            ->addExtension('amqp')
            ->addExtension('redis')
            ->withDomain('app1.project.test', 'project.test', 'localhost')
            ->withHttpAccess()
            ->addWorker('messenger', 'php -d memory_limit=1G bin/console messenger:consume async --time-limit=3600 --memory-limit=128M')
    );

    // app2 declares no domain: it is served through the redirection.io agent,
    // which holds the domain and proxies the traffic to it.
    $app2Service = (new SymfonyService('app2'))
        ->withDirectory(__DIR__ . '/app2')
        ->withVersion('8.2')
        ->withMode(PhpMode::Fpm)
        ->withDatabaseService($mysqlService)
        ->addExtension('amqp')
        ->addExtension('mysql')
    ;
    $event->addService($app2Service);

    $event->addService(new RabbitMQService());
    $event->addService(new RedisService());
    $event->addService(new ElasticsearchService());
    $event->addService(
        (new RedirectionioAgentService())
            ->addReverseProxy('app2.project.test', $app2Service, 'b02088e2-ef87-4622-8e5e-35d7f553ca9f:707268c4-1e23-4df2-a3d9-1c088e944652')
    );
    $event->addService((new ClickhouseService())->withVersion('25.8'));
    $event->addService((new GoService('app3'))
        ->withVersion('1.25')
        ->withDirectory(__DIR__ . '/go-app')
        ->withDomain('app3.project.test'));
    $event->addService((new RustService('app4'))
        ->withVersion('1.90')
        ->withDirectory(__DIR__ . '/rust-app')
        ->withDomain('app4.project.test'));
}
