---
title: Configuring services
description: The fluent with*() API and the behaviour traits shared by every service.
---

# Configuring services

Services take **only their identity in the constructor** — a name for the
application services, nothing at all for the others. Everything else is set with
fluent `with*()` methods, so you only write the options you actually change:

```php
(new RustService('api'))
    ->withVersion('1.90')
    ->withDirectory(__DIR__ . '/api')
    ->withDomain('api.project.test')
```

## Behaviour traits

Those methods come from a small set of traits in
`Castor\Docker\Service\Behaviour`, shared by every service that needs the
behaviour. Use them in your own services to get the same API for free.

| Trait | Methods | Used by |
|-------|---------|---------|
| `HasVersion` | `withVersion()`, `getVersion()` | every versioned service |
| `HasDomains` | `withDomain(...$domains)`, `getDomains()` | every routed service |
| `HasHttpAccess` | `withHttpAccess()`, `isHttpAccessAllowed()` | every routed service |
| `HasHttpRouting` | the two above + `withPort()`, `getPort()`, `applyHttpRouting()` | every routed service |
| `HasDirectory` | `withDirectory()`, `getDirectory()`, `withWorkingDirectory()`, `getWorkingDirectory()` | every service mounting sources |
| `HasSharedHomeDirectory` | `withSharedHomeDirectory()`, `getSharedHomeDirectory()` | `PHPService`, `GoService`, `RustService`, the builders |
| `HasDockerfile` | `withDockerfile()`, `getDockerfile()` | `PHPService`, `GoService`, `RustService`, the builders |
| `HasEnvironment` | `withEnvironment()`, `getEnvironment()`, `applyEnvironment()` | `BinaryRunService` |
| `HasName` | `withName()`, `getName()` | every service naming itself |
| `HasMysqlConfiguration` | `withSetting()`, `withSettings()`, `withConfiguration()`, `withConfigurationFile()` | `MySQLService`, `MariaDBService` |

### Registering the same service twice

Application services take their name in the constructor. The infrastructure ones
name themselves, and `withName()` overrides that default — which is what makes a
second instance possible:

```php
$event->addService(new PostgresService());
$event->addService((new PostgresService())->withName('analytics'));
```

Everything the service generates is derived from the name: the compose service,
the named volumes (`analytics_data`), the routed domain
(`analytics.project.test`), the connection string (`postgresql://…@analytics:5432/…`)
and the companion containers — `redis-insight` becomes `sessions-insight`,
`clickhouse-keeper` becomes `events-keeper`.

**Its tasks too**: they are named `{service}:{task}`, so two instances give you
two full task sets rather than a collision — `castor postgres:client` and
`castor analytics:client`, `castor postgres:expose` and
`castor analytics:expose`.

The task half names what it does, not what it runs: the session a database
opens is `client` on all of them, rather than `psql` on one and `mysql` on the
next.

One generated name is an exception, because it is not derived from the service
one: the Kibana container stays `kibana` for the first instance, and a renamed
Elasticsearch gets `logs-kibana`.

Names are not checked for collisions: register two services under the same name
and the second silently merges into the first, since `ComposeBuilder::service()`
returns the existing builder.

### Mount, working directory, binary

`HasDirectory` carries two distinct paths, because in a monorepo they are two
distinct things: `withDirectory()` is the host directory **mounted** in the
container, and `withWorkingDirectory()` is where the commands run **below** it —
relative to the mount, `.` by default. `GoService`, `RustService` and
`BinaryRunService` add a third with `withBinaryPath()`, for the binary the
container starts.

They only coincide when an application owns its own directory, which is why the
defaults leave the generated file exactly as it was.

`HasVersion` and `HasDockerfile` require the service to declare its own fallback
with `getDefaultVersion()` / `getDefaultDockerfile()`, and `HasHttpRouting` lets
a service override `getDefaultPort()` — that is how `RustService` defaults to
8080 while everything else defaults to 80.

## Lazy defaults

Defaults are resolved when the compose file is generated, not in the
constructor. `PHPService` picks its Dockerfile from the runtime mode, so this
works whatever the call order is:

```php
(new SymfonyService('app'))
    ->withDirectory(__DIR__)
    ->withMode(PhpMode::Fpm) // still selects the FPM Dockerfile
```

## Domains and HTTP access

`withDomain()` is variadic and de-duplicates, so these are equivalent:

```php
->withDomain('app.test', 'www.app.test')
->withDomain('app.test')->withDomain('www.app.test')
```

`withPort()` names the port the service listens on inside the container, and
the routing labels always carry it — routing without one would let the router
pick whichever port the image exposes, and answer 502 when it guesses wrong.
Services that listen somewhere other than their default say so:

```php
(new RustService('api'))->withPort(18089)->withDomain('api.project.test')
```

A service with at least one domain gets the `caddy.*` labels the
[router](../services/router.md) turns into routes. HTTPS is served with an
on-demand, locally-trusted certificate, and plain HTTP redirects to it. Call
`->withHttpAccess()` to also serve plain HTTP without the redirect.
