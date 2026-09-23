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

When [public tunnels](#sharing-the-project-over-a-public-tunnel) are open, a
second table gives the public URL of each tunnelled domain.

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

Stops the containers, and the TCP forwarders and [public tunnels](#sharing-the-project-over-a-public-tunnel)
that went with them.

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

### `castor docker:stats`

Shows what the project costs the machine it runs on. Alias: `castor stats`.

```bash
castor docker:stats
castor docker:stats -v          # also lists every image and volume
castor docker:stats --no-disk   # skips the disk usage
```

The first table gives, for every container of the project, its status and what
it is consuming right now — CPU, memory, network and block I/O, processes — and
totals the columns. The line below it puts those totals in proportion to the
cores and the memory the docker host has.

The second table adds up the disk the project takes: its images, the writable
layer of its containers, and its volumes. Two numbers are given for each:

* **Size** is what docker reports for these images, containers and volumes;
* **Exclusive** is what destroying the project would actually free. Image layers
  shared with images belonging to other projects only count in *Size*.

> [!NOTE]
> The disk usage makes docker measure every image, container and volume of the
> daemon, which takes a few seconds. Pass `--no-disk` when only the CPU and the
> memory matter.

Volumes are recognised by the compose labels they carry. Images carry none, so
they are recognised by name: the `<project>-<service>` images this plugin
builds, and the images the project containers were started from — which is how
a pulled image such as `redis:8` is attributed to the project too.

### `castor docker:doctor`

Looks for what keeps the project from working on this machine, and says how to
fix each problem it finds. Alias: `castor doctor`.

```bash
castor docker:doctor
```

Run it before anything else when something goes wrong: most of the failures
[troubleshooting](troubleshooting.md) describes are ones it detects — a daemon
that does not answer, a port held by another program, a router that never joined
the project network, a domain that does not resolve, a certificate the browser
does not trust, a directory docker created as `root`. Each section of the report
is a table, with the fix under what went wrong:

```
 --- ---------- ------------------------------------------------------
      Check      Result
 --- ---------- ------------------------------------------------------
  ✔   Port 80    Held by the router.
  ✘   Port 443   Held by nginx (pid 812): the router cannot bind it.
                 → Stop nginx (pid 812).
 --- ---------- ------------------------------------------------------
```

A `✘` is enough on its own to keep the project from working, and makes the task
exit with a non-zero code. A `⚠` leaves it working, but not as it should: a
certificate the browser warns about, a router running an older configuration
than your project's. A `–` is a check that could not run, because what it needs is missing and
reported elsewhere: without a Docker daemon, the daemon is what the report is
about, not every check that needed it. So the task answers with nothing running,
and with no daemon at all.

**Docker.** Compose has to be 2.23.1 or later: 2.20 for the `include:` of your
`compose.yaml`, 2.23.1 for the compose file of the router, which inlines its
Caddyfile. Compose 2.40.2 and later build through Buildx and refuse one older
than 0.17, so a missing Buildx breaks `docker:build` there — and `docker:push`
everywhere. When the context sets a `registry`, the doctor also makes sure the
builder can export the build cache `docker:push` pushes. It measures the disk the
daemon writes to when the daemon runs on this machine; Docker Desktop and Colima
keep theirs in a VM, out of reach.

**Project.** Compose has to accept your compose files, which catches a mistake in
`compose.override.yaml` before `docker:up` does. A container that keeps
restarting, turned unhealthy or crashed is reported with the `docker:logs` to
read. The containers run as `user_id`, which should own the project and every
directory it mounts: otherwise they write files you cannot change, or cannot
write at all.

**Router.** It has to run when the project does, with a configuration at least
as recent as the one of your project — see
[keeping it up to date](services/router.md#keeping-it-up-to-date) — and to have
joined the project network. It also has to watch
the socket of the daemon your projects run on: the doctor compares it with the
one docker talks to, since a router watching another daemon serves nothing
without failing.

**Ports.** 80 and 443, when the project routes a domain, and every host port it
publishes: each one must be free, or held by the router or by the project itself.
Otherwise the doctor names the container or the process holding it.

**HTTPS and DNS.** mkcert has to be installed, its CA copied to the router and
trusted by the system, and every domain [`docker:about`](#castor-dockerabout)
lists has to resolve to this machine. Under WSL, the browser runs on Windows,
which keeps a trust store and a hosts file of its own: the doctor asks Windows
too, through `certutil.exe` and `powershell.exe`.

**Worktree.** In a [worktree](going-further/worktrees.md), it lists what the
checkout still shares with the other ones — a domain outside of its root domain,
a host port — as `docker:about` does.

### `castor docker:destroy`

Removes containers, volumes and networks of the project, and closes its public
tunnels. **Destroys your data**, so it asks for confirmation unless `--force` is
given.

```bash
castor docker:destroy
castor docker:destroy --force
```

### `castor docker:push`

Pushes the images and their build cache to the registry configured in the
`registry` context variable. Only the services declaring a `cache_from` are
built, and `docker buildx bake` reads the compose file itself, so what it builds
is exactly what `castor docker:build` builds.

Each service lands in one repository, holding its cache under the `cache` tag
and its image under `latest` — `--tag` publishes it under another name. The
image carries `org.opencontainers.image.source`, which is what
[attaches the package to your repository](#publishing-to-ghcr-io).

`--dry-run` prints the build plan bake resolved, without running it.

```bash
castor docker:push
castor docker:push --tag "$(git rev-parse --short HEAD)"
castor docker:push --dry-run
```

#### Publishing to ghcr.io

GitHub attaches a package to a repository in two cases only: a push from a
workflow authenticating with `GITHUB_TOKEN`, or a push carrying the
`org.opencontainers.image.source` label. A build cache carries no label — the
manifest has nowhere to hold one — so a push from a laptop used to leave an
orphan package behind, owned by whoever pushed it first and writable by them
alone. The CI could then no longer feed the cache it was supposed to own: its
`GITHUB_TOKEN` has no permission on a package attached to nothing.

The image pushed next to the cache is what carries the label, so the package is
attached no matter who pushes it, and it inherits the permissions of the repository —
push rights on the repository are push rights on its images.

The repository is looked up in this order, the first one that answers winning:

| Source | Example |
|---|---|
| the `repository` context variable | `'repository' => 'mycompany/myproject'` |
| `GITHUB_REPOSITORY`, which Actions sets | `mycompany/myproject` |
| the `origin` git remote | `git@github.com:mycompany/myproject.git` |

The task refuses to push to `ghcr.io` when none of them answers, rather than
create a package nobody can take over afterwards.

Packages pushed before this, or from a registry namespace that does not match
the repository owner, stay orphans: delete them, or attach them by hand in
**Package settings › Connect repository**, once.

## Completing a service name

Every argument naming something completes, and each one offers the right list:

| Task | Completes with |
|---|---|
| `docker:build`, `docker:up`, `docker:stop`, `docker:logs`, `docker:logs:clear` | the **containers** of the compose files |
| `docker:service:remove` | the **services registered** in your `castor.php` |
| `docker:service:install` | the services the plugin knows how to install |
| `{app}:worker:restart`, `{app}:worker:stop` | the **workers of that application** |
| `docker:tunnel:start`, `docker:tunnel:stop` | the **domains** the project routes |

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

Exposed services are remembered across `docker:stop` / `docker:up`, per
checkout: a [worktree](going-further/worktrees.md) restores its own forwarders
and not the ones of the main checkout.

A host port belongs to the machine, so two checkouts cannot publish the same one.
The task says which container holds it, and remembers the request: the forwarder
comes back on the next `docker:up`, once the port is free again.

## Worktrees

Every checkout of the repository is a stack of its own — see
[git worktrees](going-further/worktrees.md).

* `castor worktree:list` — every checkout, its branch, its compose project, the
  state of its stack and its URL
* `castor worktree:create <name>` — create one, `--start` to build and start its
  stack, `--branch` and `--from` to pick the branch
* `castor worktree:delete <name>` — destroy its stack and remove it, keeping the
  branch

Every task also takes a `--worktree <name>` (`main` for the main checkout) to run
in another checkout.

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

## Sharing the project over a public tunnel

### `castor docker:tunnel:start`

Gives the domains of the project a public HTTPS URL. Use it to show a colleague
or a client what you are working on, to open the project on a phone, or to
receive the webhooks of a third-party service.

```bash
castor docker:tunnel:start                                    # every domain of the project
castor docker:tunnel:start app.myproject.test                 # a single one
castor docker:tunnel:start app.myproject.test myproject.test  # or a few
castor tunnel                                                 # the same, shorter
```

```
 --------- -------------------- --------------------------------------------------- ---------
  Service   Domain               Public URL                                          Status
 --------- -------------------- --------------------------------------------------- ---------
  app       app.myproject.test   https://calm-river-sample-words.trycloudflare.com   running
            myproject.test       https://other-sample-words.trycloudflare.com        running
 --------- -------------------- --------------------------------------------------- ---------
```

The tunnels are [Cloudflare quick tunnels](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/do-more-with-tunnels/trycloudflare/),
opened by a `cloudflared` container: there is nothing to install and no account
to create. A quick tunnel gets **one** random `*.trycloudflare.com` URL, so each
domain gets a container and a URL of its own.

The tunnels run in the background. Running the task again prints the URLs of the
ones already open and opens the missing ones. `docker:about` lists the open ones
too. A tunnel lasts until:

* `castor docker:tunnel:stop` closes it. `castor docker:tunnel:stop <domain>...`
  closes only the tunnels of the domains given;
* `castor docker:stop` or `castor docker:destroy` take the project down;
* the router stops or restarts, since the tunnels go through it.

A tunnel that was closed never comes back with the same URL. Open it again and
it gets a new one.

> [!WARNING]
> Anyone with a URL reaches the service behind it, with no authentication in
> between. Without an argument the task tunnels **every** domain of the
> project, including tools such as Adminer or RabbitMQ when they have one. Name
> the domains to share only those.

#### How the traffic reaches your application

Each `cloudflared` container joins the network of the [global router](services/router.md)
and forwards the requests to it over HTTPS. It rewrites the `Host` to the local
domain, so the router serves the request the way it serves your browser, with
the same service behind it, whether it allows plain HTTP or not. Domains
declared in `compose.override.yaml` can be tunnelled as well.

The public host name travels in the `X-Forwarded-Host` header, and the router
passes it through untouched. Your application sees `Host: app.myproject.test`,
and `X-Forwarded-Host: calm-river-sample-words.trycloudflare.com`. To generate
its absolute URLs and redirections with the public host, it has to trust the
router as a proxy. For Symfony:

```yaml
# config/packages/framework.yaml
framework:
    trusted_proxies: 'private_ranges'
    trusted_headers: ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port']
```

Without it, the application keeps seeing its local domain. Relative links work
either way.

> [!NOTE]
> A router started by an older version of the plugin overwrites
> `X-Forwarded-Host` with the local domain. `docker:up` and
> `docker:tunnel:start` warn about it: run `castor docker:router:restart` once
> to pick up the new configuration.

Quick tunnels come with limits of their own: no Server-Sent Events, at most 200
requests in flight, and no uptime guarantee. Cloudflare also rate-limits how
many can be created. When one could not be, the task prints what `cloudflared`
said, and running it again retries only the missing tunnels.

### `castor docker:tunnel:stop`

Closes the tunnels of the project, or the tunnels of the domains given.

```bash
castor docker:tunnel:stop
castor docker:tunnel:stop app.myproject.test myproject.test
```

Both tasks complete the domain names, and leave out the ones already given.

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

docker_compose_run(['bin/console', 'app:import'], 'app-builder');

docker_compose_run(
    ['bin/replay', '--verbose'],
    service: 'agent',
    workDir: '/app/agent',
    environment: ['RUST_LOG' => 'debug'],   // -e RUST_LOG=debug
    entrypoint: '/bin/bash',                // --entrypoint
    ports: ['10080:10080', '28080:8080/tcp'], // -p
);
```

Give the command as a list of tokens. They reach docker as they are, so nothing
splits or expands them, and an argument holding a space, a quote, a `$` or a
`;` arrives whole — which is what you want as soon as an argument comes from
whoever typed the task:

```php
// the message arrives as one argument, and $USER is not expanded
docker_compose_run(['bin/console', 'app:notify', 'deployed by $USER'], 'app-builder');
```

A string is still accepted, and still runs through a shell in the container,
which is what a command written to use one needs:

```php
docker_compose_run('bin/console app:import | tee /tmp/import.log', 'app-builder');
```

A failing command raises a `RuntimeException` naming the service and the command
that broke, instead of the bare `docker compose` error. Use
`docker_exit_code()`, which takes the same arguments, when you want the exit
code rather than an exception.

### In a container already running

`docker_compose_run()` starts a throwaway container. To reach the one a service
is already running — to look at a cache it has warmed, to send it a signal —
use `docker_compose_exec()`:

```php
use function Castor\Docker\docker_compose_exec;

docker_compose_exec(['bin/console', 'cache:pool:list'], 'app');
docker_compose_exec(['apt-get', 'update'], 'app', privileged: true);
```

It takes the same `workDir` and `environment` arguments, and the same two
command forms. The service has to be up: `docker compose exec` fails on a
service that is not running, where `docker_compose_run()` would have started
one.

Compose announces the throwaway container each run creates —
`Container app-builder-run-8c9d8bef Creating`, then `Created` — in front of the
output you asked for. Those lines are silenced, and come back as soon as you run
a task with `-v`.
