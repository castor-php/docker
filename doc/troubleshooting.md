---
title: Troubleshooting
description: Common problems and how to get out of them.
---

# Troubleshooting

## Port conflicts

If ports 80 or 443 are already in use, stop the conflicting service. The router
is global and no longer part of your project compose file, so
`compose.override.yaml` cannot remap its ports: edit
`~/.castor/docker/router/compose.yaml` instead, and keep in mind that
`castor docker:router:enable` regenerates that file.

## Permission issues

Containers run with your user ID to avoid root-owned files, and the plugin
creates the directories the services bind-mount — the shared home directory,
the application directories — before starting anything, because docker would
create the missing ones as `root`.

A directory created that way by an older version, or by a plain
`docker compose up`, stays owned by `root`: the plugin warns about it, and you
take it back with

```bash
sudo chown -R $(id -u):$(id -g) .home
```

## Containers will not start

```bash
castor docker:logs      # what the containers say
castor docker:build     # rebuild, the image may be stale
docker ps               # is the daemon healthy?
```

## The router does not route

1. check it runs: `castor docker:router:status`, and enable it with
   `castor docker:router:enable` if not;
2. check the project appears in the networks that status lists. The router joins
   a project network on `docker:up`: if the project was started while the router
   was down, `castor docker:router:enable` makes it join the running ones;
3. check that your domains resolve to `127.0.0.1` — add them to `/etc/hosts` if
   needed;
4. check that the service declares a domain: without one, no `caddy.*` label is
   emitted and the router ignores the container.

## Certificate warnings in the browser

Caddy falls back to its own local CA when mkcert is not installed. Install
mkcert, run `mkcert -install`, then `castor docker:router:enable` again — see
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
