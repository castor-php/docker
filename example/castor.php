<?php

namespace project;

use Castor\Attribute\AsListener;
use Castor\Docker\GenerateComposeEvent;

#[AsListener(GenerateComposeEvent::class)]
function add_service(GenerateComposeEvent $event)
{
    $event->addService('http-test', [
        'image' => 'kennethreitz/httpbin:latest',
        'ports' => ['8080:80'],
    ]);
}