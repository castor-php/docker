---
title: Rust
description: Build and run a Cargo application, with clippy and rustfmt tasks.
---

# Rust

Two shapes, depending on how many binaries you build:

* **one crate, one container** — [`RustService`](#rustservice) builds and runs
  the application in a single container. The common case;
* **one toolchain, N binaries** — [`RustBuilder`](#rustbuilder) compiles, and one
  [`BinaryRunService`](#binaryrunservice) per binary runs. The monorepo case.

## RustService

Runs a Cargo application from the source directory mounted in the container:
`cargo build` happens inside the container and the resulting debug binary is
used as the container command.

The image is the official `rust` one plus the `clippy` and `rustfmt` components
— the official images ship the *minimal* rustup profile, which leaves them out.

```php
(new RustService('api'))            // Service name — must match the crate name,
                                    // the container runs target/debug/<name>
    ->withVersion('1.90')           // Rust version, tag of the official rust image (default: 1)
    ->withDirectory(__DIR__ . '/api')
    ->withPort(8080)                // Port the application listens on (default: 8080)
    ->withDomain('api.project.test')
    ->withHttpAccess()              // Also serve plain HTTP, without redirecting to HTTPS
```

### Paths

Three different things, three settings — they only coincide in the simple case:

| Method | What it sets | Default |
|---|---|---|
| `withDirectory()` | the host directory mounted at `/app` | `.` |
| `withWorkingDirectory()` | where cargo runs, **relative to the mount** | `.` |
| `withBinaryPath()` | the binary the container starts, relative to the mount | `target/debug/<name>` |

They come apart as soon as the mount is bigger than the crate — a crate inside a
workspace, or a binary that has to read a directory another application writes:

```php
(new RustService('agent'))
    ->withDirectory(__DIR__)                     // mount the repository
    ->withWorkingDirectory('agent/agent-application')  // run cargo in the crate
    ->withBinaryPath('agent/target/debug/agent-application')
```

### Commands and targets

```php
->withTarget('x86_64-unknown-linux-musl')   // adds --target, and moves the default
                                            // binary path to target/<triple>/debug/<name>
->withBuildCommand('cargo build --release') // replaces "cargo build"
->withRunCommand(['--listen', '0.0.0.0:18089', '--insecure'])
```

`withTarget()` does both halves of the job: forgetting that the binary moves to
`target/<triple>/debug/` is the classic musl mistake.

`withRunCommand()` given a **list** appends the arguments to the binary; given a
**string** it replaces the container command outright.

### Caches

The crate registry lives in the shared home directory
(`CARGO_HOME=/home/app/.cargo`), so a given crate is downloaded once for the
whole project instead of once per service or per rebuild. Build artifacts stay
in the project's own `target/` directory, which survives container recreation.

### Generated tasks

* `castor api:build` — build the application (`cargo build`)
* `castor api:restart` — restart the service
* `castor api:watch` — rebuild and restart on every change to a `.rs`,
  `Cargo.toml` or `Cargo.lock` file
* `castor api:test` — run the test suite (`cargo test`)
* `castor api:cargo` — any cargo command in the container
* `castor api:bash` — a bash shell in the container
* `castor api:qa:clippy` — run Clippy
* `castor api:qa:fmt` — run rustfmt

### Containers

* `api` — the Rust application, sources mounted at `/app`

### Scaffolding a new application

`castor docker:service:install rust` creates the crate with `cargo init` and
writes a dependency-free HTTP server in `src/main.rs`, so the container serves
something as soon as the install finishes.

## RustBuilder

One compiler container for a whole repository. It holds the toolchain, mounts
the sources, and declares the applications it compiles — it runs no application
itself and sits on the `builder` profile, so `docker:build` builds it and
`docker:up` does not start it.

```php
$rust = (new RustBuilder('rust-builder'))
    ->withVersion('1.94.0')
    ->withDirectory(__DIR__)                     // the repository root
    ->addRustupTarget('x86_64-unknown-linux-musl')
    ->addRustupComponent('rust-analyzer')        // clippy and rustfmt are there already
    ->addRustupToolchain('nightly', ['rustfmt']) // an extra toolchain
    ->withNightlyFormatter()                     // format on nightly, build on stable
    ->withApp('agent/agent-application', target: 'x86_64-unknown-linux-musl')
    ->withApp('server/log-injector')
    ->withApp('tools/codegen', 'codegen', toolchain: 'nightly')
;
```

### Formatting on nightly

Most of rustfmt's options are still unstable, so a `rustfmt.toml` using any of
them — `imports_granularity`, `group_imports`, `wrap_comments` — is silently
ignored by a stable rustfmt. Your file looks applied, and nothing happens.

```php
->withNightlyFormatter()
```

installs the nightly toolchain with its rustfmt in the image, and points the
`fmt` task of every application at it. **Only** the formatter moves: `build`,
`test`, `cargo` and `qa:clippy` stay on the default toolchain, which is the
point — nightly for the formatter, stable for everything that ships.

Declaring nightly yourself keeps working: `addRustupToolchain('nightly', [...])`
is completed with `rustfmt` rather than installed twice.

### `withApp()`

```php
->withApp(string $directory, ?string $name = null, ?string $target = null,
          ?string $toolchain = null, ?string $buildCommand = null)
```

`$directory` is relative to the mount. `$name` defaults to its last segment and
becomes the **task namespace**, so `agent/agent-application` yields
`agent-application:build`. `$toolchain` runs cargo through `rustup run
<toolchain>`, for a crate that needs another one than the default.

Each application contributes, all running in the one builder container with the
working directory set to the crate:

* `castor <app>:build`, `castor <app>:test`, `castor <app>:cargo`
* `castor <app>:qa:clippy`, `castor <app>:qa:fmt`

Plus `castor rust-builder:bash` for the container itself.

### Why not one service per binary

Layer caching makes N identical builds cheap, so that is not the reason. What
does not come for free:

* **the registry cache is per reference** — six services means pushing the same
  cache content to six `${REGISTRY}/<name>:cache` refs;
* **the toolchain is declared once** — six copies of `->withVersion()` and
  `->addRustupTarget()` drift the moment someone edits five of them;
* **build and QA belong somewhere that is not a running binary** — with
  `RustService` they run *inside* the application container.

## BinaryRunService

Runs one already-compiled binary. Language-agnostic: the same class runs a Rust
binary, a Go one, or anything else.

```php
(new BinaryRunService('agent', 'agent/target/x86_64-unknown-linux-musl/debug/agent-application'))
    ->withBuilder($rust, 'agent-application')  // image, mount, and the build task
    ->withImage('debian:13-slim')              // override the image
    ->withRunCommand(['--listen', '0.0.0.0:18089'])
    ->withEnvironment('BACKEND_URL', 'https://backend.project.test')
    ->withRestart('on-failure')                // or unless-stopped, always, no
    ->withDomain('agent.project.test')
    ->withPort(8080)
```

Nothing watches a binary that exits — because a dependency was not up yet,
because it panicked — so without a restart policy it stays down until someone
notices. `withRestart()` defaults to `on-failure`, which brings it back without
fighting a deliberate `castor docker:stop` the way `always` would. There is no
policy unless you ask for one.

The constructor takes the service name and the binary path, relative to the
mounted directory.

### Attaching a builder

`withBuilder()` is optional but does three things at once:

1. **the image** — by default the runtime container runs the image its builder
   produced. A binary linked against the glibc of `rust:1.94` will not start in
   an unrelated slim image; `withImage('debian:13-slim')` is the opt-in you take
   once you target musl or `CGO_ENABLED=0`;
2. **the mount** — it inherits the builder's directory, so both sides resolve the
   binary path the same way;
3. **the tasks** — `<name>:build` and `<name>:watch` need a compiler to run in.
   Without a builder you only get `<name>:restart`.

The second argument names the builder application this binary comes from, by
name or by directory; it defaults to the service name.

### Generated tasks

* `castor agent:restart` — always
* `castor agent:build`, `castor agent:watch` — only with a builder attached

## Dockerfile extension points

Both `RustService` and `RustBuilder` are built from a [Twig
Dockerfile](../going-further/custom-dockerfile.md) you can extend:

```dockerfile
# syntax=ghcr.io/castor-php/twig-dockerfile:0.1
{% extends 'Dockerfile' %}

{% block rust_base %}
    {{ parent() }}
RUN apt-get update \
    && apt-get install -y --no-install-recommends musl-tools musl-dev make patch \
    && rm -rf /var/lib/apt/lists/*
{% endblock %}
```

Then `->withDockerfile(__DIR__ . '/Dockerfile')`. This is also how you install
extra Debian packages — there is no `addAptPackage()`, the block is the
extension point.

### Blocks

| Block | Stage | What it holds |
|-------|-------|---------------|
| `rust_base` | `rust-base` | the official `rust` image, the rustup components, targets and toolchains, `WORKDIR /app` |
| `runtime` | `runtime` | `FROM rust-base`, the stage the container runs |

### Variables

| Variable | Type | Comes from |
|----------|------|------------|
| `rust_components` | list of strings | `clippy`, `rustfmt`, plus `addRustupComponent()` |
| `rust_targets` | list of strings | `addRustupTarget()` |
| `rust_toolchains` | list of `{name, components}` | `addRustupToolchain()` |

The version is **not** a Twig variable: it reaches `FROM` through the
`rust_version` Docker build argument and shell interpolation. Build arguments
are JSON-decoded before Twig sees them, which would turn `"1.90"` into the
number `1.9` and pull the wrong image.
