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

A service with at least one domain gets the `caddy.*` labels the
[router](../services/router.md) turns into routes. HTTPS is served with an
on-demand, locally-trusted certificate, and plain HTTP redirects to it. Call
`->withHttpAccess()` to also serve plain HTTP without the redirect.
