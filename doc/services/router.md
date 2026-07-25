---
title: Router and HTTPS
description: The Caddy router, automatic routing from Docker labels and locally-trusted certificates.
---

# CaddyRouterService

[Caddy](https://caddyserver.com/) reverse proxy, via
[caddy-docker-proxy](https://github.com/lucaslorentz/caddy-docker-proxy), routing
HTTP and HTTPS traffic to your services. Routes are built automatically from the
`caddy.*` Docker labels emitted for each service that declares a domain — there
is no static configuration file to maintain.

The router is **registered automatically**, you only declare it to customise it:

```php
(new CaddyRouterService())
    ->withSharedHomeDirectory('.home')
```

## Tasks

* `castor router:enable` — enable the router, and copy the mkcert CA if available
* `castor router:disable` — disable the router

## Containers

* `router` — the Caddy reverse proxy, named volume `router-data` (issued
  certificates and local CA), exposing ports 80 and 443

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
3. (Re)start the router: `castor router:enable`

`router:enable` copies the mkcert root CA into the router, which then signs the
on-demand certificates with it.

### Without mkcert

Caddy falls back to its own local CA. HTTPS still works, but you have to accept
the security warning in your browser — or add Caddy's root CA, stored in the
`router-data` volume, to your trust store.
