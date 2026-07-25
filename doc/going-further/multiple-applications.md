---
title: Multiple applications
description: Run several applications, with their own databases, in a single stack.
---

# Multiple applications

Nothing stops you from registering several applications in the same
infrastructure — each with its own directory, PHP version, database and domains:

```php
#[AsListener(RegisterServiceEvent::class)]
function register_service(RegisterServiceEvent $event)
{
    $postgresService = new PostgresService();
    $mysqlService = new MySQLService();

    $event->addService($postgresService);
    $event->addService($mysqlService);

    $event->addService(
        (new SymfonyService('app1'))
            ->withDirectory(__DIR__ . '/app1')
            ->withDatabaseService($postgresService)
            ->withDomain('app1.project.test', 'project.test')
    );

    $event->addService(
        (new SymfonyService('app2'))
            ->withDirectory(__DIR__ . '/app2')
            ->withVersion('8.2')
            ->withDatabaseService($mysqlService)
            ->withDomain('app2.project.test')
    );
}
```

Each application gets its own set of tasks, prefixed with its name:
`castor app1:install`, `castor app2:symfony`, and so on.

Languages can be mixed the same way — a PHP front-end, a Go API and a Rust
worker in the same stack, all routed by the same router:

```php
$event->addService((new GoService('api'))->withDirectory(__DIR__ . '/api')->withDomain('api.project.test'));
$event->addService((new RustService('crawler'))->withDirectory(__DIR__ . '/crawler'));
```

A complete example lives in the
[`example/` directory](https://github.com/castor-php/docker/tree/main/example)
of the repository.
