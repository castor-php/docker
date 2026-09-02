---
title: Installing services
description: Add and remove services without editing your castor.php by hand.
---

# Installing services

`castor docker:service:install` registers a service in your listener, then builds
and starts it. Your `castor.php` is edited with a format-preserving AST rewrite,
so only the added lines change — comments and formatting stay untouched.

```bash
# List the installable services
castor docker:service:install

# Install one
castor docker:service:install mariadb
castor docker:service:install symfony
castor docker:service:install rust
castor docker:service:install node
```

Depending on the service you are asked a few questions (application name,
directory, version, domain…). Pass `--no-interaction` to accept every default,
or `--file` to target a listener file other than `castor.php`.

## Services that do more on install

Some installers go beyond registering a service:

* **symfony** — scaffolds the application with
  `composer create-project symfony/skeleton` inside its own builder container;
* **rust** — creates the crate with `cargo init` and drops in a dependency-free
  HTTP server so the container serves something right away;
* **node** — scaffolds the application from the template you pick: a
  dependency-free HTTP server, `create-vite --template react`, or
  `create-next-app` — and writes the dev-server configuration each one needs to
  be served on a domain;
* any application needing a database offers to link an existing one or to
  install a new one on the spot.

## Removing a service

```bash
# List the registered services
castor docker:service:remove

# Remove one
castor docker:service:remove mailpit
```

A database another service links to is protected: you are asked to remove or
unlink the dependent service first.

## Adding your own installer

Other plugins — or your own project — can contribute installers by listening to
`RegisterServiceInstallerEvent`. See
[writing your own service](../going-further/writing-a-service.md).
