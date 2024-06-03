<?php

namespace Castor\Docker;

final class GenerateComposeEvent
{
    public function __construct(public array $content)
    {
    }

    public function addService(string $name, array $service): void
    {
        $this->content['services'][$name] = $service;
    }
}