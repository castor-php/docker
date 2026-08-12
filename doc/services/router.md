---
title: Router and HTTPS
description: The global Caddy router, automatic routing from Docker labels and locally-trusted certificates.
---

# Router and HTTPS

[Caddy](https://caddyserver.com/) reverse proxy, via
[caddy-docker-proxy](https://github.com/lucaslorentz/caddy-docker-proxy), routing
HTTP and HTTPS traffic to your services. Routes are built automatically from the
`caddy.*` Docker labels emitted for each service that declares a domain — there
is no static configuration file to maintain.

The router is **global**: a single instance, living outside of any project,
serves every Castor Docker project on the machine. It is not a service of your
compose file and is not declared in `castor.php`.

* one router for all your projects, so ports 80 and 443 are bound once
* projects can run side by side, and the router survives their restarts
* certificates are issued once and shared

## Tasks

```bash
castor docker:router:enable     # create, start, and trust — run once
castor docker:router:status     # is it running, which projects it serves
castor docker:router:logs       # add --follow to tail them
castor docker:router:restart
castor docker:router:disable
```

## It starts and stops with your projects

You do not have to start the router at all: `castor docker:up` starts it when the
project routes a domain and it is not already running, and `castor docker:stop`
— or `castor docker:destroy` — stops it once no routed container is left running
on the machine.

```bash
castor docker:up      # starts the router if this project needs it
castor docker:stop    # stops it, unless another project is still using it
```

A project routing no domain never touches it: it neither starts it nor stops it.

`docker:router:enable` is still there, and is what you want to run once when you
would rather keep a router up whatever happens to your projects — it also copies
the mkcert CA and joins the projects that are already running. Note that a
router left running is still stopped by the last project going down, unless you
turn the autostart off:

```php
#[AsContext(default: true)]
function default_context(): Context
{
    return new Context([
        'router_autostart' => false,
    ]);
}
```

The environment variable wins over the context, for a CI job or a single shell
that must not touch the router of the machine:

```bash
CASTOR_DOCKER_ROUTER_AUTOSTART=0 castor docker:up
```

With the autostart off, nothing starts or stops the router but you:
`docker:router:enable` and `docker:router:disable`.

`castor docker:router:status` shows whether the autostart is on, and which
projects the router currently serves.

## How your services are reached

Each project keeps its **own** compose network. The router is not on it — it
*joins* it: `docker:up` attaches the router to the project network once the
project is up, and `docker:down` detaches it before removing that network.

Projects therefore never share a network with each other, only with the router.
This matters because Docker resolves service names per network: if every project
joined one shared network, two projects both exposing a service named `app`
would collide in the DNS, and a container asking for `app` could reach the other
project's one. Joining from the router side keeps each project's names private
to it.

caddy-docker-proxy resolves `{{upstreams}}` to container IPs rather than names,
so being routable to the project network is all it needs.

Enabling the router while projects are already running is fine:
`docker:router:enable` joins the networks of the containers it finds already
routed.

## Reaching your own domains from inside a container

An application calling its own public API, a worker hitting the front end, a
reverse proxy going back to the backend — all of them want
`https://api.myproject.test` to work *inside* a container, not just in your
browser.

It does, and there is nothing to configure. The plugin knows every domain routed
in the project, and gives each container an `extra_hosts` entry pointing it at
the host gateway, where the ports 80 and 443 the router publishes answer:

```yaml
services:
    worker:
        extra_hosts:
            - 'app.myproject.test:host-gateway'
            - 'api.myproject.test:host-gateway'
```

This is the reason it is needed: the router joins the project network from the
outside, so the project's own DNS knows nothing about its public domains. Going
out through the host gateway works on Linux as well as on Docker Desktop, and
keeps working when the router is not on the project network at all.

Domains routed from an `#[AsDockerComposeBuilder]` function or a listener are
covered too — the list is read from the routing labels, not from the services.

A name without a dot is skipped on purpose. Mapping `localhost` to the gateway
would shadow the loopback entry of `/etc/hosts` and break everything the
container reaches on `127.0.0.1`, and any other bare label collides with the
container names of the project network.

Turn the whole thing off with a context variable:

```php
return new Context([
    'resolve_domains_via_host' => false,
]);
```

The domains are also passed to `docker network connect` as **network aliases**,
so they resolve to the router through the Docker DNS as well. `/etc/hosts` wins
over DNS, so the aliases are what keeps the domains resolvable once
`resolve_domains_via_host` is off.

## Files and containers

* `~/.castor/docker/router/compose.yaml` — the generated router compose file,
  rewritten on every `docker:router:enable`
* `~/.castor/docker/router/certs/` — the mkcert CA, mounted read-write in the
  router as `/certs`
* `castor-docker-router` — the container, exposing ports 80 and 443, with a
  `router-data` named volume holding the issued certificates and the local CA

The router handles HTTP and HTTPS only: raw TCP protocols cannot be
hostname-routed, use [`{service}:expose`](../tasks.md#exposing-a-service-over-tcp)
for those.

### The Docker socket it watches

The routes come from the `caddy.*` labels the router reads on the Docker socket,
so it has to watch the socket of the daemon your projects actually run on —
`/var/run/docker.sock` by default.

When that is not the right one — a CI job installing a daemon of its own, a
rootless daemon, Colima — export `DOCKER_SOCKET_PATH`, or set a `unix://`
`DOCKER_HOST`, before `docker:router:enable`:

```bash
DOCKER_SOCKET_PATH=/run/user/1000/docker.sock castor docker:router:enable
```

Watching the wrong daemon is silent: the router comes up, finds no label to
build a route from, and serves nothing — every domain then answers "connection
refused" on 443. `docker:router:enable` warns when the socket does not exist at
all.

## Certificates

Caddy provisions TLS certificates **on demand**: the first time a domain is
requested, its internal issuer mints a certificate for it. There is nothing to
generate or renew manually.

### With mkcert (recommended)

To make those certificates trusted by your browsers, with no security warning:

1. Install [mkcert](https://github.com/FiloSottile/mkcert)
2. Install the CA in your system trust store: `mkcert -install`
3. (Re)start the router: `castor docker:router:enable`

`docker:router:enable` copies the mkcert root CA into
`~/.castor/docker/router/certs/`, which the router then uses to sign the
on-demand certificates.

### Without mkcert

Caddy falls back to its own local CA. HTTPS still works, but you have to accept
the security warning in your browser — or add Caddy's root CA, stored in the
`router-data` volume, to your trust store.
