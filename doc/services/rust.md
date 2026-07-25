---
title: Rust
description: Build and run a Cargo application, with clippy and rustfmt tasks.
---

# RustService

Runs a Cargo application from the source directory mounted in the container:
`cargo build` happens inside the container and the resulting debug binary is
used as the container command.

The image is the official `rust` one plus the `clippy` and `rustfmt` components
— the official images ship the *minimal* rustup profile, which leaves them out.

## Configuration

```php
(new RustService('api'))            // Service name — must match the crate name,
                                    // the container runs target/debug/<name>
    ->withVersion('1.90')           // Rust version, tag of the official rust image (default: 1)
    ->withDirectory(__DIR__ . '/api')
    ->withPort(8080)                // Port the application listens on (default: 8080)
    ->withDomain('api.project.test')
    ->withHttpAccess()              // Also serve plain HTTP, without redirecting to HTTPS
```

## Caches

The crate registry lives in the shared home directory
(`CARGO_HOME=/home/app/.cargo`), so a given crate is downloaded once for the
whole project instead of once per service or per rebuild. Build artifacts stay
in the project's own `target/` directory, which survives container recreation.

## Generated tasks

* `castor api:build` — build the application (`cargo build`)
* `castor api:restart` — restart the service
* `castor api:watch` — rebuild and restart on every change to a `.rs`,
  `Cargo.toml` or `Cargo.lock` file
* `castor api:test` — run the test suite (`cargo test`)
* `castor api:cargo` — any cargo command in the container
* `castor api:bash` — a bash shell in the container
* `castor api:qa:clippy` — run Clippy
* `castor api:qa:fmt` — run rustfmt

## Containers

* `api` — the Rust application, sources mounted at `/app`

## Scaffolding a new application

`castor docker:service:install rust` creates the crate with `cargo init` and
writes a dependency-free HTTP server in `src/main.rs`, so the container serves
something as soon as the install finishes.
