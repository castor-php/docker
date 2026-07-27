# Castor Docker Plugin

A [Castor](https://castor.jolicode.com/) plugin that turns a PHP description of
your stack into a Docker Compose environment, and gives you the tasks to drive
it.

📖 **[Read the documentation](https://castor-php.github.io/docker/)**

## Features

- 🚀 Automatic Docker Compose configuration generation
- 🔧 Pre-configured services for common infrastructure components
- 🎯 Service-specific tasks for common operations
- 🔒 On-demand, locally-trusted HTTPS (with mkcert support)
- 🌐 Caddy-based reverse proxy with automatic routing from Docker labels
- 📦 Multi-stage Docker builds with registry caching
- 👥 Multi-application, multi-language support in a single project

<!-- start index -->

## Installation

```bash
castor composer require castor-php/docker
```

## Usage

1. Create a `castor.php` file in your project root:

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
        'registry' => 'ghcr.io/mycompany/myproject'
    ]);
}

#[AsListener(RegisterServiceEvent::class)]
function register_service(RegisterServiceEvent $event)
{
    $postgresService = new PostgresService();
    $event->addService($postgresService);

    $event->addService(
        (new SymfonyService(name: 'app', directory: __DIR__))
            ->withDatabaseService($postgresService)
            ->addDomain('myproject.test')
            ->allowHttpAccess()
    );
}
```

2. Enable the global Caddy router (one time setup):

```bash
castor docker:router:enable
```

3. Start your project infrastructure:

```bash
castor docker:build
castor docker:up
```

## Global router

The Caddy router is **global**: one instance, living outside of any project,
serves every Castor Docker project on the machine. Ports 80 and 443 are bound
once, projects run side by side, and the router survives their restarts.

It lives in `~/.castor/docker/router/` and is managed separately from your
project services:

```bash
castor docker:router:enable     # create, start and trust it — run once
castor docker:router:status     # is it running, which projects it serves
castor docker:router:logs       # add --follow to tail them
castor docker:router:restart
castor docker:router:disable
```

Each project keeps its own compose network, and the router joins it on
`docker:up` rather than every project joining a shared one — so two projects can
both have a service named `app` without colliding in the Docker DNS. See the
[router documentation](https://castor-php.github.io/docker/services/router/) for
the details.

Registering those two services also gave you tasks: `castor app:bash`,
`castor app:install`, `castor app:symfony`, `castor db:psql`,
`castor docker:logs`, and more.

Prefer not to write it by hand? Let the plugin do it:

```bash
castor docker:service:install symfony
```

<!-- end index -->

## Documentation

Everything is on **<https://castor-php.github.io/docker/>**:

- [Getting started](https://castor-php.github.io/docker/getting-started/) — installation, first environment, service configuration
- [Services](https://castor-php.github.io/docker/services/) — PHP, Go, Rust, databases, cache, queue, search, router
- [Tasks](https://castor-php.github.io/docker/tasks/) — the commands the plugin gives you
- [Going further](https://castor-php.github.io/docker/going-further/) — multiple applications, custom images, writing your own service

## Example

See the [example](./example/) directory for a complete working project with
multiple applications and services.

## Contributing

The documentation lives in [`doc/`](./doc/) and is built with MkDocs:

```bash
castor docs:serve   # http://127.0.0.1:8000, rebuilds on change
castor docs:build   # build the static site in tools/mkdocs/site
```

Quality tooling for the plugin itself:

```bash
castor qa:phpstan
castor qa:cs
vendor/bin/phpunit
```

## License

This plugin is part of the Castor project, released under the MIT license.
