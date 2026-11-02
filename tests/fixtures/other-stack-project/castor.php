<?php

namespace other_stack;

use Castor\Attribute\AsContext;
use Castor\Attribute\AsListener;
use Castor\Context;
use Castor\Docker\Event\RegisterServiceEvent;
use Castor\Docker\Service\GoService;
use Castor\Docker\Service\PostgresService;

defined('CASTOR_USE_CHDIR') || define('CASTOR_USE_CHDIR', false);

#[AsContext(default: true)]
function default_context(): Context
{
    return new Context(['generate_compose_files' => false]);
}

#[AsListener(RegisterServiceEvent::class)]
function register_service(RegisterServiceEvent $event): void
{
    $event->addService(new PostgresService());
    $event->addService((new GoService('api'))->withDirectory(__DIR__ . '/api'));
}
