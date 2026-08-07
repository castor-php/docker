---
title: Background workers
description: Run long-running processes next to your application.
---

# Background workers

Add background processes to a PHP application with `addWorker()`:

```php
(new SymfonyService('app'))
    ->withDirectory(__DIR__)
    ->addWorker('messenger', 'php bin/console messenger:consume async --time-limit=3600')
    ->addWorker('notifications', 'php bin/console app:process-notifications')
```

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

> [!TIP]
> Give your consumers a `--time-limit` or `--memory-limit`: the container
> restarts them, which keeps long-running PHP processes healthy.
