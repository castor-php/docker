---
title: Your first environment
description: Describe a Symfony application and its database, then start the whole stack.
---

# Your first environment

## Describe the stack

Create a `castor.php` file at the root of your project:

```php
<?php

namespace project;

use Castor\Attribute\AsContext;
use Castor\Attribute\AsListener;
use Castor\Context;
use Castor\Docker\Event\RegisterServiceEvent;
use Castor\Docker\Service\PostgresService;
use Castor\Docker\Service\SymfonyService;

#[AsContext(default: true)]
function default_context(): Context
{
    return new Context([
        'root_domain' => 'myproject.test',
        'registry' => 'ghcr.io/mycompany/myproject',
    ]);
}

#[AsListener(RegisterServiceEvent::class)]
function register_service(RegisterServiceEvent $event)
{
    $postgresService = new PostgresService();
    $event->addService($postgresService);

    $event->addService(
        (new SymfonyService('app'))
            ->withDirectory(__DIR__)
            ->withDatabaseService($postgresService)
            ->withDomain('myproject.test')
            ->withHttpAccess()
    );
}
```

Every service you register with `RegisterServiceEvent` contributes its
containers to the generated compose file, and its own tasks to the Castor CLI.

## Start it

```bash
# Build the images
castor docker:build

# Start the containers
castor docker:up

# Enable the router to reach your domains over HTTPS (once per machine)
castor docker:router:enable
```

Your application is now served on `https://myproject.test`. Point that domain to
`127.0.0.1` in your `/etc/hosts` if it does not resolve already.

## What you get

Registering those two services also gave you a set of tasks:

```bash
castor app:bash          # a shell in the builder container
castor app:install       # composer install
castor app:symfony cache:clear
castor db:psql           # a psql session on the database
castor docker:logs
```

See [tasks](../tasks.md) for the full list, and [services](../services/index.md)
for everything else you can add to the stack.

## Files created next to your castor.php

| File | Role |
|------|------|
| `compose.yaml` | Entry point, includes the two files below. Yours to edit. |
| `compose.generated.yaml` | Generated from `castor.php` on every run. **Never edit.** |
| `compose.override.yaml` | Your local overrides. Never touched by the plugin. |
| `.home/` | Shared home directory: composer, cargo and other caches. |

More about them in [configuration](../configuration.md).
