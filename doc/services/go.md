---
title: Go
description: Build and run a Go application from the mounted sources.
---

# GoService

Runs a Go application from the source directory mounted in the container:
`go build` happens inside the container and the resulting binary is used as the
container command.

## Configuration

```php
(new GoService('api'))              // Service name, also the name of the built binary
    ->withVersion('1.25')           // Go version, tag of the official golang image (default: 1)
    ->withDirectory(__DIR__ . '/api')
    ->withDomain('api.project.test')
    ->withHttpAccess()              // Also serve plain HTTP, without redirecting to HTTPS
```

The application is expected to listen on port 80 inside the container. Use
`->withPort()` if it listens somewhere else.

## Generated tasks

* `castor api:build` — build the application (`go build -o api`)
* `castor api:restart` — restart the service
* `castor api:watch` — rebuild and restart on every change to a `.go` file

## Containers

* `api` — the Go application, based on the official `golang` image with your
  sources mounted at `/app`

The shared home directory is mounted at `/home/app`, so the module cache is
reused across rebuilds.
