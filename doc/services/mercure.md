---
title: Mercure
description: Real-time updates pushed to the browser, from a hub FrankenPHP embeds or from a container of its own.
---

# Mercure

A [Mercure](https://mercure.rocks) hub pushes updates to the browsers
subscribed to it: the application publishes over HTTP, the pages listen with an
`EventSource`. Register one and link it:

```php
$mercure = new MercureService();
$event->addService($mercure);

$event->addService(
    (new SymfonyService('app'))
        ->withDirectory(__DIR__)
        ->withDomain('app.project.test')
        ->link($mercure)
);
```

```php
(new MercureService())
    ->withVersion('v0.24.2')                            // Image tag (default: v0.24.2)
    ->withJwtSecret('…')                                // default: !ChangeThisMercureHubJWTSecretKey!
    ->withCorsOrigin('https://legacy.project.test')     // on top of the linked applications
```

## Where the hub runs

The code is the same whatever serves the application; where the hub runs
depends on who links to it.

FrankenPHP is Caddy, compiled with the Mercure module: the hub can be a
directive of its configuration rather than a container. So when the only
service linked to the hub is an application served in `PhpMode::FrankenPhp` —
the default — on a domain, the hub runs in that application, on
`/.well-known/mercure` of its own domains, and no container is generated. A page
subscribing to it stays on its origin. The directive is part of the image, like
the worker mode: linking the hub takes a `castor docker:build`. The secret and
the domains are read when the server starts, so changing those is a `docker:up`
away.

Otherwise the hub is a container of its own, served on
`https://mercure.{root_domain}`: an application in `PhpMode::Fpm`, where nginx
serves the pages and there is no Caddy to embed the hub in; an application with
no domain for the browsers to reach it on; and a hub several services link to.
One `MercureService` is one hub — two applications linked to it hear each
other's updates, which two hubs embedded in each of them would not. Register a
`MercureService` per application to give each its own.

Either way the domains of the linked applications are the origins the hub
accepts: a page served on another domain than the hub — or than the first
domain of the application, for an embedded one — subscribes from another
origin. The list is explicit rather than `*`, because a hub answering `*` cannot
accept the credentials a browser sends along with the authorization cookie of
private updates. `withCorsOrigin()` is for the pages the hub cannot know about —
an application served behind the [redirection.io agent](redirectionio.md),
which holds the domain instead of the application.

The cookie itself only works between domains sharing a parent:
`app.project.test` and `mercure.project.test` do, `localhost` and
`mercure.project.test` do not.

* **Containers:** `mercure`, named volume `mercure-data` (the history a
  reconnecting subscriber catches up with) — none when the application embeds
  the hub
* **UI:** the debug UI on `https://mercure.{root_domain}/.well-known/mercure/ui/`
  — on `/.well-known/mercure/ui/` of the application for an embedded hub

There is no `expose` task: the hub speaks HTTP, and the router already serves it
to the host.

## What the application receives

Either way the application — its builder and its workers included, since a
message handler publishes as much as a controller — gets the variables of the
`symfony/mercure-bundle` recipe:

| Variable | Embedded hub | Container |
|----------|--------------|----------------|
| `MERCURE_URL` | `http://app/.well-known/mercure` | `http://mercure/.well-known/mercure` |
| `MERCURE_PUBLIC_URL` | `https://app.project.test/.well-known/mercure` | `https://mercure.project.test/.well-known/mercure` |
| `MERCURE_JWT_SECRET` | the secret | the secret of the hub |

`MERCURE_URL` is where the server publishes: over plain HTTP, on the project
network, because the containers do not trust the certificates of the router.
`MERCURE_PUBLIC_URL` is what the browser is told, through the router. The
default secret is the one of the recipe, so an application still carrying it in
its `.env` already matches — and at 34 bytes, it is long enough for the 32 that
`lcobucci/jwt` requires to sign with HS256.

The hub is a development one: subscribers without a token receive the public
updates, the subscription API is on, and so is the debug UI.

## Why 0.x

The image is pinned to the 0.x series, not to the 1.0 `latest` now points to.
Mercure 1.0 changed the protocol — the tokens it accepts, their issuer, their
audience — and rejects what a Symfony application sends by default, while the
FrankenPHP images still embed 0.x. Staying on 0.x keeps the embedded hub and the
container interchangeable; move the container to 1.0 only once the application
is configured for it.
