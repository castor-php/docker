---
title: Troubleshooting
description: Common problems and how to get out of them.
---

# Troubleshooting

## Port conflicts

If ports 80 or 443 are already in use, either stop the conflicting service, or
remap the router ports in your `compose.override.yaml`:

```yaml
services:
    router:
        ports: !override
            - "8080:80"
            - "8443:443"
```

## Permission issues

Containers run with your user ID to avoid root-owned files. If you still hit
permission errors:

1. check that the `.home` directory is writable;
2. check the ownership of the mounted application directory.

## Containers will not start

```bash
castor docker:logs      # what the containers say
castor docker:build     # rebuild, the image may be stale
docker ps               # is the daemon healthy?
```

## The router does not route

1. enable it: `castor router:enable`;
2. check that your domains resolve to `127.0.0.1` — add them to `/etc/hosts` if
   needed;
3. check that the service declares a domain: without one, no `caddy.*` label is
   emitted and the router ignores the container.

## Certificate warnings in the browser

Caddy falls back to its own local CA when mkcert is not installed. Install
mkcert, run `mkcert -install`, then `castor router:enable` again — see
[router and HTTPS](services/router.md#certificates).

## The generated compose file looks wrong

`compose.generated.yaml` is rewritten on every Castor run from your
`castor.php`. If it does not match what you expect, the fastest way to see the
result of a change is:

```bash
castor docker:ps                 # any task regenerates the file
docker compose config            # what Docker actually understands
```

Never edit `compose.generated.yaml` itself: use `compose.override.yaml`.
