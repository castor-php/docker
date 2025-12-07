<?php

namespace project;

use Castor\Attribute\AsListener;
use Castor\Docker\Event\RegisterServiceEvent;
use Castor\Docker\Service\PHPService;
use Castor\Docker\Service\PostgresService;
use Castor\Docker\Service\SymfonyService;
use Castor\Docker\Service\TraefikRouterService;

#[AsListener(RegisterServiceEvent::class)]
function register_service(RegisterServiceEvent $event)
{
    $databaseService = new PostgresService();
    $event->addService($databaseService);

    $event->addService(
        (new PHPService(name: 'app1', directory: __DIR__ . '/app1'))
            ->withDatabaseService($databaseService)
            ->addDomain('app1.project.test')
            ->addDomain('project.test')
            ->addDomain('localhost')
            ->allowHttpAccess()
    );

    $event->addService(
        (new SymfonyService(name: 'app2', directory: __DIR__ . '/app2', version: '8.2'))
            ->withDatabaseService($databaseService)
            ->addDomain('app2.project.test')
    );

    $event->addService(
        (new TraefikRouterService(rootDomain:  'project.test'))
            ->addExtraDomain('app2.project.test')
            ->addExtraDomain('app1.project.test')
    );
}