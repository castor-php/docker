---
title: Tasks
description: The commands the Castor Docker plugin gives you.
---

# Tasks

The plugin registers a handful of infrastructure tasks, plus every task
contributed by the services you registered. Run `castor list` to see them all.

## Infrastructure

### `castor docker:build`

Builds the Docker images of the infrastructure.

```bash
castor docker:build                     # everything
castor docker:build app                 # a single service
castor docker:build --profiles builder  # restrict to a profile
```

Alias: `castor build`.

### `castor docker:up`

Starts the containers, building the missing images first.

```bash
castor docker:up
castor docker:up app
castor docker:up --build      # force a rebuild before starting
```

Alias: `castor up`. Services exposed over TCP before the last stop are
re-exposed automatically.

### `castor docker:stop`

Stops the containers, and the TCP forwarders that went with them.

```bash
castor docker:stop
castor docker:stop app
```

Alias: `castor stop`.

### `castor docker:logs`

Follows the container logs.

```bash
castor docker:logs
castor docker:logs app
```

Alias: `castor logs`.

### `castor docker:ps`

Lists the containers and their status. Alias: `castor ps`.

### `castor docker:destroy`

Removes containers, volumes and networks of the project. **Destroys your data**,
so it asks for confirmation unless `--force` is given.

```bash
castor docker:destroy
castor docker:destroy --force
```

### `castor docker:push`

Pushes the build cache images to the registry configured in the `registry`
context variable.

```bash
castor docker:push
castor docker:push --dry-run
```

## Services

### `castor docker:service:install`

Registers a service in your `castor.php`, then builds and starts it. See
[installing services](getting-started/installing-services.md).

### `castor docker:service:remove`

Unregisters a service and tears down its containers.

### Exposing a service over TCP

Databases and brokers expose a `{service}:expose` task, to reach them from the
host with a native client — the router only handles HTTP and HTTPS.

```bash
castor postgres:expose            # on the service default port
castor postgres:expose 15432      # on a specific host port
castor postgres:expose --stop     # stop the forwarder
```

Exposed services are remembered across `docker:stop` / `docker:up`.

## Router

* `castor router:enable` — enable the router, copying the mkcert CA if available
* `castor router:disable` — disable the router

## Profiles

Services are organised into Docker Compose profiles:

* `default` — the services started by default
* `builder` — build and CI/CD containers
* `router` — the Caddy reverse proxy

Every infrastructure task takes `--profiles`:

```bash
castor docker:up --profiles default,router
castor docker:build --profiles builder
```
