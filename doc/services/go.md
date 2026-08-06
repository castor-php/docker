---
title: Go
description: Build and run a Go application from the mounted sources.
---

# Go

Two shapes, depending on how many binaries you build:

* **one module, one container** — [`GoService`](#goservice) builds and runs the
  application in a single container. The common case;
* **one toolchain, N binaries** — [`GoBuilder`](#gobuilder) compiles, and one
  [`BinaryRunService`](rust.md#binaryrunservice) per binary runs. The monorepo
  case.

## GoService

Runs a Go application from the source directory mounted in the container:
`go build` happens inside the container and the resulting binary is used as the
container command.

```php
(new GoService('api'))              // Service name, also the name of the built binary
    ->withVersion('1.25')           // Go version, tag of the official golang image (default: 1)
    ->withDirectory(__DIR__ . '/api')
    ->withDomain('api.project.test')
    ->withHttpAccess()              // Also serve plain HTTP, without redirecting to HTTPS
```

The application is expected to listen on port 80 inside the container. Use
`->withPort()` if it listens somewhere else.

### Paths

Three different things, three settings — they only coincide in the simple case:

| Method | What it sets | Default |
|---|---|---|
| `withDirectory()` | the host directory mounted at `/app` | `.` |
| `withWorkingDirectory()` | where go runs, **relative to the mount** | `.` |
| `withBinaryPath()` | the binary the container starts, relative to the mount | `<name>` |

```php
(new GoService('exporter'))
    ->withDirectory(__DIR__)                 // mount the repository
    ->withWorkingDirectory('server/exporter')
    ->withBinaryPath('server/exporter/exporter')
```

### Commands

```php
->withBuildCommand('go build -ldflags=-s -o exporter')  // replaces "go build -o <binary path>"
->withRunCommand(['--config', '/app/config.yaml'])      // arguments appended to the binary
```

`withRunCommand()` given a **list** appends the arguments to the binary; given a
**string** it replaces the container command outright.

### Generated tasks

* `castor api:build` — build the application (`go build -o api`)
* `castor api:restart` — restart the service
* `castor api:watch` — rebuild and restart on every change to a `.go` file

### Containers

* `api` — the Go application, with your sources mounted at `/app`

The shared home directory is mounted at `/home/app`, so the module cache is
reused across rebuilds.

## GoBuilder

One compiler container for a whole repository. It holds the toolchain, mounts
the sources, and declares the modules it compiles — it runs no application
itself and sits on the `builder` profile, so `docker:build` builds it and
`docker:up` does not start it.

```php
$go = (new GoBuilder('go-builder'))
    ->withVersion('1.25')
    ->withDirectory(__DIR__)          // the repository root
    ->withApp('server/exporter')
    ->withApp('tools/migrator', 'migrator', output: 'bin/migrator')
;
```

### `withApp()`

```php
->withApp(string $directory, ?string $name = null,
          ?string $output = null, ?string $buildCommand = null)
```

`$directory` is relative to the mount. `$name` defaults to its last segment and
becomes the **task namespace**, so `server/exporter` yields `exporter:build`.
`$output` is where `go build` writes the binary, relative to the module
directory; it defaults to the application name.

Each application contributes, all running in the one builder container with the
working directory set to the module:

* `castor <app>:build` — `go build -o <output>`
* `castor <app>:test` — `go test ./...`
* `castor <app>:go` — any go command

Plus `castor go-builder:bash` for the container itself.

The binaries it produces are run by
[`BinaryRunService`](rust.md#binaryrunservice) containers — the same class the
Rust side uses.

## Dockerfile extension points

Both `GoService` and `GoBuilder` are built from a [Twig
Dockerfile](../going-further/custom-dockerfile.md) you can extend:

```dockerfile
# syntax=ghcr.io/castor-php/twig-dockerfile:0.1
{% extends 'Dockerfile' %}

{% block go_base %}
    {{ parent() }}
RUN apt-get update \
    && apt-get install -y --no-install-recommends protobuf-compiler \
    && rm -rf /var/lib/apt/lists/*
{% endblock %}
```

Then `->withDockerfile(__DIR__ . '/Dockerfile')`. This is also how you install
extra Debian packages — there is no `addAptPackage()`, the block is the
extension point.

### Blocks

| Block | Stage | What it holds |
|-------|-------|---------------|
| `go_base` | `go-base` | the official `golang` image and `WORKDIR /app` |
| `runtime` | `runtime` | `FROM go-base`, the stage the container runs |

The version is **not** a Twig variable: it reaches `FROM` through the
`go_version` Docker build argument and shell interpolation. Build arguments are
JSON-decoded before Twig sees them, which would turn `"1.20"` into the number
`1.2` and pull the wrong image.
