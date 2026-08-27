---
title: Getting started
description: Install the Castor Docker plugin, describe your stack and start it.
---

# Getting started

The plugin turns a PHP description of your stack into a Docker Compose file and
a set of tasks to drive it. You describe the services you need in `castor.php`,
the plugin writes `compose.generated.yaml` and gives you `castor docker:up`,
`castor docker:build`, per-service shells, database clients and more.

Nothing is generated behind your back: the compose file sits next to your
`castor.php`, you can read it, and you keep a `compose.override.yaml` for
anything the plugin does not cover.

1. [Installation](installation.md) — add the plugin to your project
2. [Your first environment](first-environment.md) — a Symfony application and its database
3. [Installing services](installing-services.md) — add services without writing code
4. [Configuring services](configuring-services.md) — the fluent API shared by every service

Coming from [docker-starter](https://github.com/jolicode/docker-starter)? See
[migrating from docker-starter](migrating-from-docker-starter.md).
