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

`docker:router:enable` is a one-time setup: the router keeps running across
project restarts and reboots.

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
