---
title: Tasks
description: The commands the Castor Docker plugin gives you.
---

# Tasks

The plugin registers a handful of infrastructure tasks, plus every task
contributed by the services you registered. Run `castor list` to see them all.

## Infrastructure

### `castor docker:about`

Sums up the project — its name, its root domain, how many of its containers run,
whether the router is up — then lists **every URL the project answers on**.

```bash
castor docker:about
```

Alias: `castor about`.

The URLs are read from the `caddy` labels of `compose.generated.yaml`,
`compose.yaml` and `compose.override.yaml`, so a domain declared by a service
with `withDomain()`, by an `#[AsDockerComposeBuilder]` function or straight in
your own compose file is listed the same way — and each is shown against the
service serving it, with its status:

```
 --------------- ---------------------------------- ---------
  Service         URL                                Status
 --------------- ---------------------------------- ---------
  app             https://app.project.test           running
                  https://project.test
                  http://app.project.test
  adminer         https://adminer.project.test       stopped
 --------------- ---------------------------------- ---------
```

The `http://` entries are the services that also allow plain HTTP with
`withHttpAccess()`; every other domain is served over HTTPS only.

Everything comes from the compose files, so the task answers with the
infrastructure stopped, and without a running Docker daemon — only the
running/stopped statuses need one.

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

### `castor docker:logs:clear`

Empties the log files docker keeps for the containers, so `docker:logs` starts
from a clean slate.

```bash
castor docker:logs:clear          # every container of the project
castor docker:logs:clear app      # a single service
```

Nothing is restarted: the file is truncated in place, so a running container
keeps running and keeps writing to the same stream.

Stopped containers are cleared too, and so are the ones on a profile that is not
active — their logs are still on disk. A container whose logging driver is not
`json-file` keeps its logs elsewhere and is reported as skipped.

> [!NOTE]
> The log file belongs to `root`, and on Docker Desktop it lives inside the VM
> rather than on your machine. When it cannot be written directly, the task runs
> a short-lived `--privileged` container to reach it — that is the only way to
> get at it on Docker Desktop.

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

## Completing a service name

Every argument naming something completes, and each one offers the right list:

| Task | Completes with |
|---|---|
| `docker:build`, `docker:up`, `docker:stop`, `docker:logs`, `docker:logs:clear` | the **containers** of the compose files |
| `docker:service:remove` | the **services registered** in your `castor.php` |
| `docker:service:install` | the services the plugin knows how to install |
| `{app}:worker:restart`, `{app}:worker:stop` | the **workers of that application** |

```bash
castor docker:logs app<TAB>          # app1  app1-builder  app1-worker-messenger
castor docker:service:remove <TAB>   # app1  app2  postgres  redis  …
castor app1:worker:restart <TAB>     # messenger
```

The container names are read from `compose.generated.yaml`, `compose.yaml` and
`compose.override.yaml`, so the services you declare yourself are offered
alongside the generated ones, and completion answers without a running Docker
daemon.

Install the shell completion once with `castor completion | source` — see the
[castor documentation](https://castor.jolicode.com/going-further/interacting-with-castor/autocomplete/).

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

The router is global and shared by every project, so its tasks are not tied to
the current one — see [router and HTTPS](services/router.md).

`docker:up` starts it when the project routes a domain, and `docker:stop` and
`docker:destroy` stop it once no routed container is left running on the
machine. These tasks are for the times you want to decide yourself:

* `castor docker:router:enable` — create, start and trust the router, copying
  the mkcert CA if available
* `castor docker:router:status` — whether it runs, whether the autostart is on,
  and the projects it serves
* `castor docker:router:logs` — its logs, `--follow` to tail them
* `castor docker:router:restart` — restart it
* `castor docker:router:disable` — stop it

Set the `router_autostart` [context variable](configuration.md#starting-and-stopping-the-router-with-your-projects)
to `false`, or `CASTOR_DOCKER_ROUTER_AUTOSTART=0` for a single command, to leave
the router entirely to those tasks.

## Profiles

Services are organised into Docker Compose profiles:

* `default` — the services started by default
* `builder` — build and CI/CD containers

Every infrastructure task takes `--profiles`:

```bash
castor docker:up --profiles default
castor docker:build --profiles builder
```

Set the `docker_profiles` [context variable](configuration.md#default-profiles)
to change what the tasks activate when you pass none.

## Running a command in a container

`Castor\Docker\docker_compose_run()` is what the service tasks are built on, and
what your own tasks should use to reach a container:

```php
use function Castor\Docker\docker_compose_run;

docker_compose_run('bin/console app:import', 'app-builder');

docker_compose_run(
    'bin/replay --verbose',
    service: 'agent',
    workDir: '/app/agent',
    environment: ['RUST_LOG' => 'debug'],   // -e RUST_LOG=debug
    entrypoint: '/bin/bash',                // --entrypoint
    ports: ['10080:10080', '28080:8080/tcp'], // -p
);
```

A failing command raises a `RuntimeException` naming the service and the command
that broke, instead of the bare `docker compose` error. Use
`docker_exit_code()`, which takes the same arguments, when you want the exit
code rather than an exception.

Compose announces the throwaway container each run creates —
`Container app-builder-run-8c9d8bef Creating`, then `Created` — in front of the
output you asked for. Those lines are silenced, and come back as soon as you run
a task with `-v`.
