<?php

/*
 * A project registering a service but declaring no #[AsContext] function, which
 * is what a project looks like right after "composer require castor-php/docker".
 */

namespace fresh;

use Castor\Attribute\AsListener;
use Castor\Docker\Event\RegisterServiceEvent;
use Castor\Docker\Service\GoService;
use Castor\Docker\Service\PostgresService;

#[AsListener(RegisterServiceEvent::class)]
function register_service(RegisterServiceEvent $event): void
{
    $event->addService(new PostgresService());

    // Bind-mounts its directory and the shared home, neither of which exists yet.
    $event->addService((new GoService('api'))->withDirectory(__DIR__ . '/api'));
}
