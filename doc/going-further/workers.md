---
title: Background workers
description: Run long-running processes next to your application.
---

# Background workers

Add background processes to a PHP application with `addWorker()`:

```php
(new SymfonyService('app'))
    ->withDirectory(__DIR__)
    ->addWorker('messenger', 'php bin/console messenger:consume async --time-limit=3600', 'unless-stopped')
    ->addWorker('notifications', 'php bin/console app:process-notifications')
```

The third argument is the compose restart policy of that container —
`unless-stopped`, `on-failure`, `always`, `no`. There is none by default: a
worker that exits stays down until the next `castor docker:up`.

Each worker runs in its own container, named `app-worker-{name}`, built from the
`worker` target of the application image. Workers share the application's
volumes, database and mailer configuration, so `DATABASE_URL` and `MAILER_DSN`
are available to them too.

## Driving them

An application that declares workers gets two tasks for them — they do not exist
otherwise:

```bash
castor app:worker:restart              # every worker of the application
castor app:worker:restart messenger    # just that one
castor app:worker:stop                 # every worker
castor app:worker:stop notifications   # just that one
```

The argument is the name you passed to `addWorker()`, not the container name, and
an unknown one is rejected with the list of those declared — rather than
quietly acting on all of them.

`worker:restart` starts a worker that was stopped, so there is no separate start
task: `stop` then `restart` is the round trip. After a code change, restarting is
what picks it up:

```bash
castor app:install && castor app:worker:restart
```

The containers stay reachable by name for everything else:

```bash
castor docker:logs app-worker-messenger
```

## Keeping a consumer alive

Give your consumers a `--time-limit` or a `--memory-limit`: a long-running PHP
process accumulates memory, and letting it stop on its own terms beats letting
it grow.

That only keeps them running if something brings the container back, and that
is the restart policy:

```php
->addWorker('messenger', 'php bin/console messenger:consume async --time-limit=3600', 'unless-stopped')
```

> [!IMPORTANT]
> Reaching a `--time-limit` or a `--memory-limit` is a **successful** exit, so
> `on-failure` does not bring the worker back — it only reacts to a non-zero
> one. Use `unless-stopped` for a consumer meant to run forever.

`unless-stopped` rather than `always`: it honours `castor app:worker:stop`,
where `always` would start the container again the next time the Docker daemon
does.
