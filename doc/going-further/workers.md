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

Restart a worker after a code change like any other container:

```bash
castor docker:stop app-worker-messenger
castor docker:up app-worker-messenger
castor docker:logs app-worker-messenger
```

> [!TIP]
> Give your consumers a `--time-limit` or `--memory-limit`: the container
> restarts them, which keeps long-running PHP processes healthy.
