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

Languages can be mixed the same way — a React front-end, a PHP back-end, a Go
API and a Rust worker in the same stack, all routed by the same router:

```php
$event->addService((new NodeService('front'))->withDirectory(__DIR__ . '/front')->withDomain('front.project.test'));
$event->addService((new GoService('api'))->withDirectory(__DIR__ . '/api')->withDomain('api.project.test'));
$event->addService((new RustService('crawler'))->withDirectory(__DIR__ . '/crawler'));
```

## Monorepos

The shape above gives each application its own directory, its own image and its
own builder container. That stops paying off once a single Git root holds a
dozen of them: the toolchain gets declared over and over, and a container can
only see its own sub-directory.

Three things change.

### Mount the root, name the sub-directory

`withDirectory()` is what gets mounted, `withWorkingDirectory()` is where the
commands run inside it. Mount the repository and every container can read what
the others produce:

```php
(new SymfonyService('backend'))
    ->withDirectory(__DIR__)                // the repository root
    ->withWorkingDirectory('apps/backend')  // where composer and the console run
```

### One builder per language, not per application

PHP applications share a builder container with
[`withSharedBuilder()`](../services/php.md#sharing-one-builder-container).
Compiled languages go further and split the compiler from the runtime:
[`RustBuilder`](../services/rust.md#rustbuilder) or
[`GoBuilder`](../services/go.md#gobuilder) holds the toolchain and declares the
applications it compiles, and one
[`BinaryRunService`](../services/rust.md#binaryrunservice) per binary runs the
result.

```php
$rust = (new RustBuilder('rust-builder'))
    ->withDirectory(__DIR__)
    ->addRustupTarget('x86_64-unknown-linux-musl')
    ->withApp('agent/agent-application', target: 'x86_64-unknown-linux-musl')
    ->withApp('server/log-injector');

$event->addService($rust);

$event->addService(
    (new BinaryRunService('agent', 'agent/target/x86_64-unknown-linux-musl/debug/agent-application'))
        ->withBuilder($rust, 'agent-application')
        ->withDomain('agent.project.test')
);
```

One image, one registry cache reference, one place where the toolchain is
declared — and `castor agent-application:build` runs in the builder rather than
inside a running binary.

### Call the other applications by their real domains

`https://backend.project.test` works from inside a container, not just from your
browser: every container gets an `extra_hosts` entry for each domain of the
project. Nothing to configure, and the URL is the same in development and in
production — see [router and
HTTPS](../services/router.md#reaching-your-own-domains-from-inside-a-container).

## A complete example

The [`example/` directory](https://github.com/castor-php/docker/tree/main/example)
of the repository is exactly that: one root with two PHP applications sharing a
builder, a Rust binary and a Go binary each built by their language's builder,
and a container calling another through its public domain.
