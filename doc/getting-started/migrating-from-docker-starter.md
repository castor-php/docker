---
title: Migrating from docker-starter
description: Move a project built on jolicode/docker-starter over to the Castor Docker plugin.
---

# Migrating from docker-starter

The migration is mostly a deletion. What follows is the whole of
`infrastructure/docker/` and `.castor/` going away, replaced by a `castor.php`
of a few dozen lines.

## What replaces what

| docker-starter | Here |
|---|---|
| `infrastructure/docker/docker-compose.yml` | `compose.generated.yaml`, from your `castor.php` |
| `infrastructure/docker/docker-compose.override.yml` | `compose.override.yaml`, still yours |
| `infrastructure/docker/services/php/Dockerfile` | shipped by the plugin, [extensible](../going-further/custom-dockerfile.md) |
| `infrastructure/docker/services/router/` (Traefik) | the [global Caddy router](../services/router.md) |
| `.castor/docker.php` | `Castor\Docker\` tasks and functions |
| `.castor/context.php` | two or three [context variables](../configuration.md) |
| `.castor/postgres.php` | `PostgresService` and its `postgres:client` task |
| `.castor/qa.php` + `tools/*` | the [QA tasks](../services/php.md#quality-assurance) of `PHPService` |
| `.castor/worktree.php` | a [project name per checkout](../configuration.md#running-two-checkouts-side-by-side) |
| `.castor/init.php` | [`castor docker:service:install`](installing-services.md) |

## Before you start

Work on a branch, and **dump your database first if needed**. The named volumes 
are not carried over: docker-starter names them `postgres-data`, the plugin names 
them `postgres_data`, and the compose project name may change too — so the new
stack starts on an empty database.

```bash
docker compose -p app exec -T postgres pg_dump -U app -Fc app > ../app.dump
```

Replace `app` with your `project_name`. You will restore it at the end.

## 1. Install the plugin

```bash
castor composer require castor-php/docker
```

## 2. Describe the stack

The default docker-starter stack is a PostgreSQL, an nginx + PHP-FPM frontend, a
builder and an optional messenger worker, with the repository root mounted at
`/var/www` and the application living in `application/`. All of it:

```php
<?php

namespace project;

use Castor\Attribute\AsContext;
use Castor\Attribute\AsListener;
use Castor\Context;
use Castor\Docker\Event\RegisterServiceEvent;
use Castor\Docker\Service\PhpMode;
use Castor\Docker\Service\PostgresService;
use Castor\Docker\Service\SymfonyService;

#[AsContext(default: true)]
function default_context(): Context
{
    return new Context([
        'root_domain' => 'app.test',
        'registry' => 'ghcr.io/mycompany/myproject',
    ]);
}

#[AsListener(RegisterServiceEvent::class)]
function register_service(RegisterServiceEvent $event): void
{
    $postgres = (new PostgresService())->withVersion('16');
    $event->addService($postgres);

    $event->addService(
        (new SymfonyService('app'))
            ->withDirectory(__DIR__)               // what is mounted at /var/www
            ->withWorkingDirectory('application')  // where the application lives
            ->withVersion('8.5')
            ->withMode(PhpMode::Fpm)
            ->withDatabaseService($postgres)
            ->withDomain('app.test', 'www.app.test')
            ->addWorker('messenger', 'php -d memory_limit=1G bin/console messenger:consume async --memory-limit=128M')
    );
}
```

A few things map differently:

* **`project_name` and `extra_domains` are gone.** Every domain is an argument of
  `withDomain()`, and the compose project name comes from the `name:` of the
  `compose.yaml` the plugin creates on its first run.
* **`php_version` is a service setting**, `withVersion()`, not a global. Two
  applications can run two versions.
* **`PhpMode::Fpm` keeps nginx + PHP-FPM**, which is what docker-starter serves.
  It is worth trying `PhpMode::FrankenPhp` — the plugin's default — once the
  migration is done, but change one thing at a time.
* **The PHP extensions move out of the Dockerfile** and into `addExtension()`.
  `apcu`, `bcmath`, `curl`, `iconv`, `intl`, `mbstring`, `pgsql`, `uuid`, `xml`
  and `zip` are already there.
* **`DATABASE_URL` is injected** by `withDatabaseService()`, so the `sed` on
  `.env` docker-starter does at install time has nothing left to do. The URL is
  the same one: `postgresql://app:app@postgres:5432/app`.

If you would rather not write it by hand, `castor docker:service:install symfony`
and `castor docker:service:install postgres` write those lines for you — see
[installing services](installing-services.md).

> [!NOTE]
> Workers are on the `default` profile here, not on a `worker` one. `docker:up`
> starts them with the rest of the stack, and there is no `docker:worker:start`
> to remember — `castor app:worker:restart` starts a stopped worker too.

## 3. Drop the Traefik router

The router is **global**: one Caddy instance, living in `~/.castor/docker/`,
serves every Castor Docker project on the machine. `docker:up` starts it,
`docker:stop` stops it once no routed container is left running anywhere.

Delete `infrastructure/docker/docker-compose.dev.yml` and
`infrastructure/docker/services/router/` — the Dockerfile, `generate-ssl.sh`,
`openssl.cnf`, `traefik/` and `certs/` all go — along with the `traefik.*` labels
and the `docker:generate-certificates` task. The plugin mints certificates on
demand from the mkcert CA, so there is nothing to generate ahead of time and
nothing to regenerate when you add a domain.

Two consequences worth knowing:

* **`PROJECT_HTTP_PORT` & co. disappear**, and with them
  `.castor/worktree.php`, its crc32 port hashing and `castor docker:ports`. One
  router binds 80 and 443 for every project at once, so two checkouts no longer
  contend for them. Give each one its own project name and domain instead — see
  [running two checkouts side by side](../configuration.md#running-two-checkouts-side-by-side).
* **`castor docker:about` reads the compose labels**, not the Traefik admin API,
  so it answers with the infrastructure stopped and without a Docker daemon.

See [router and HTTPS](../services/router.md) for the details.

## 4. Drop the Dockerfile, or extend it

`infrastructure/docker/services/php/Dockerfile` has an equivalent in the plugin,
with the same stages. If you never touched it, delete the whole
`infrastructure/docker/services/php/` directory.

If you did, extend the plugin's [Twig Dockerfile](../going-further/custom-dockerfile.md)
rather than copying it back — you override one block and keep the rest:

| docker-starter stage | Block to extend |
|---|---|
| `base` | `php_base` |
| `frontend` | `frontend` |
| `worker` | `worker` |
| `builder` | `builder` |

```dockerfile
# syntax=ghcr.io/castor-php/twig-dockerfile:0.1
{% extends 'Dockerfile' %}   {# 'Dockerfile.frankenphp' in PhpMode::FrankenPhp #}

{% block builder %}
{{ parent() }}
RUN apt-get update && apt-get install -y --no-install-recommends poppler-utils
{% endblock %}
```

Then point the service at it with `->withDockerfile(__DIR__ . '/Dockerfile')`.
The [Dockerfile cookbook](../going-further/dockerfile-cookbook.md) covers the
usual customisations.

Your nginx configuration has a counterpart too: `frontend/etc/nginx/nginx.conf.twig`
is a template with `root`, `http`, `server`, `server_locations` and `events`
blocks, so a project that only changed a `location` extends that instead of
shipping a whole `nginx.conf`.

`.home/` stays exactly as it is — same directory, same role.

## 5. Move the QA tooling

`tools/php-cs-fixer/`, `tools/phpstan/`, `tools/twig-cs-fixer/` and the
`tools/bin/*` shims all go, and so do `qa:install` and `qa:update`. The plugin
installs the tools itself, into `.castor/vendor/.tools/`, and runs them **in the
builder container** so they see the PHP version, the extensions and the
`vendor/` the application actually runs on.

Pin the versions your `tools/*/composer.json` used, on the service:

```php
(new SymfonyService('app'))
    ->withPhpStanVersion('^2.0')
    ->withPhpCsFixerVersion('^3.0')
    ->withPhpTwigCsFixerVersion('^3.0')
    ->addPhpStanExtraDependency('phpstan/phpstan-symfony', '^2.0')
```

**Move your configuration files into the application directory.** docker-starter
keeps `phpstan.neon` and `.php-cs-fixer.php` at the repository root and runs the
tools from `/var/www`; here each task runs in the working directory of its
application, and looks for its configuration there:

| Tool | Files it looks for |
|---|---|
| PHPStan | `.phpstan.neon`, `phpstan.neon`, `.phpstan.neon.dist`, `phpstan.neon.dist`, `.phpstan.dist.neon`, `phpstan.dist.neon` |
| PHP CS Fixer | `.php-cs-fixer.php`, `.php-cs-fixer.dist.php` |
| Rector | `rector.php` |

So `phpstan.neon` moves to `application/phpstan.neon`, and its `paths:` become
relative to it. When a configuration file is there, the task passes **no path at
all** and lets the tool's own configuration decide what to analyse — see
[quality assurance](../services/php.md#quality-assurance) for why that matters.

The tasks are namespaced per application, so `castor cs` becomes
`castor app:qa:cs`, `castor phpstan` becomes `castor app:qa:phpstan`, and
`castor twig-cs` becomes `castor app:qa:twig-cs`. `castor app:qa:rector` comes
for free.

## 6. Port your own tasks

Everything that lived in `.castor/` and called into `docker\` needs its imports
changed, and the helpers take a **string command and an explicit service** rather
than an array and a default:

| docker-starter | Here |
|---|---|
| `docker\docker_compose_run(['bin/console', 'x'])` | `Castor\Docker\docker_compose_run('bin/console x', 'app-builder')` |
| `docker\docker_exit_code(['vendor/bin/phpunit'])` | `Castor\Docker\docker_exit_code('vendor/bin/phpunit', 'app-builder')` |
| `docker\docker_compose_exec(['psql'], service: 'postgres')` | `Castor\Docker\docker_compose(['exec', 'postgres', 'psql'])` |
| `docker\docker_compose([...])` | `Castor\Docker\docker_compose([...])` |
| `docker\build()`, `docker\up()`, `docker\stop()` | `Castor\Docker\build()`, `up()`, `stop()` |
| `docker\get_service_names()` | `Castor\Docker\get_compose_service_names()` |

```php
use function Castor\Docker\docker_compose_run;

docker_compose_run('bin/console app:import', 'app-builder');

docker_compose_run(
    'bin/replay --verbose',
    service: 'app-builder',
    workDir: '/var/www/application',
    environment: ['APP_DEBUG' => '1'],
);
```

The builder container is named after the application — `app-builder`, not
`builder` — and a failing command raises a `RuntimeException` naming the service
and the command, instead of the bare `docker compose` error. Use
`docker_exit_code()`, which takes the same arguments, when you want the exit
code instead.

Anything you were running through `castor builder -- <command>` has a task now:
`castor app:bash` for a shell, `castor app:composer <args>` for Composer,
`castor app:symfony <args>` for the console.

## 7. Shrink the context

Most of `.castor/context.php` has no reason to exist any more. `user_id` is
computed by the plugin, `docker_compose_files` is replaced by the generated file
and your `compose.override.yaml`, `root_dir` by castor's own, and `macos` /
`power_shell` by nothing at all. What is worth keeping is `root_domain`,
`registry`, and `project_name` if you run several checkouts.

The `test` and `ci` contexts have no built-in equivalent — keep yours if you use
them, minus the `docker_compose_files` juggling:

```php
#[AsContext(name: 'ci')]
function ci_context(): Context
{
    return default_context()->withEnvironment(['COMPOSE_ANSI' => 'never']);
}
```

Where docker-starter swapped compose files per environment, use
[profiles](../tasks.md#profiles) instead: `castor docker:up --profiles default`.

## 8. Restore your data and check

```bash
castor docker:build
castor docker:up
docker compose exec -T postgres pg_restore -U app -d app --clean --if-exists < ../app.dump
castor docker:about
```

`docker:about` lists every URL the project answers on, with the service serving
each and whether it runs. If the router is stopped it says so.

## 9. Delete what is left

```bash
git rm -r infrastructure/ .castor/docker.php .castor/context.php \
          .castor/postgres.php .castor/qa.php .castor/worktree.php \
          .castor/init.php tools/
```

And in `.gitignore`, replace

```gitignore
/infrastructure/docker/docker-compose.override.yml
/infrastructure/docker/services/router/certs/*.pem
```

with

```gitignore
/compose.generated.yaml
/.castor/vendor/
```

Keep `compose.yaml` and `compose.override.yaml` in the repository:
`compose.generated.yaml` is rewritten on every run, the other two are yours.

## 10. Update the CI

The `hadolint` job has nothing left to lint. The rest is renames:

```yaml
- name: Build and start the infrastructure
  run: |
      castor docker:build
      castor docker:up
      castor app:install

- name: Check PHP coding standards
  run: castor app:qa:cs --dry-run

- name: Run PHPStan
  run: castor app:qa:phpstan
```

`DS_PHP_VERSION` and `DS_REGISTRY` become whatever your context reads — the
`registry` variable, and `withVersion()` for the PHP version.

## Task equivalence

| docker-starter | Here |
|---|---|
| `castor about` | `castor docker:about` |
| `castor build`, `up`, `stop`, `logs`, `ps`, `destroy`, `push` | same, under `docker:` |
| `castor builder -- <cmd>` | `castor app:bash`, `castor app:composer` |
| `castor docker:generate-certificates` | — the router mints them on demand |
| `castor docker:ports` | — one router, fixed ports |
| `castor docker:worker:start` / `stop` | `castor app:worker:restart` / `stop` |
| `castor pg` | `castor postgres:client` |
| `castor install` | `castor app:install` |
| `castor cache-clear`, `cache-warmup` | `castor app:cache-clear`, `app:cache-warmup` |
| `castor migrate`, `fixtures` | `castor app:db:migrate`, `app:db:fixtures` |
| `castor cs`, `phpstan`, `twig-cs` | `castor app:qa:cs`, `app:qa:phpstan`, `app:qa:twig-cs` |
| `castor init:symfony`, `init:sylius` | `castor docker:service:install symfony` |

And the ones you gain: `castor docker:logs:clear`, `castor postgres:expose` and
its equivalent on every database and broker, `castor app:qa:rector`, the
`docker:router:*` tasks, and shell completion on every service name.

## What you keep writing yourself

Four docker-starter tasks have no equivalent yet, because they are about your
application rather than about the infrastructure. They are a handful of lines in
your own `castor.php`:

```php
use Castor\Attribute\AsTask;

use function Castor\Docker\build;
use function Castor\Docker\docker_compose_run;
use function Castor\Docker\docker_exit_code;
use function Castor\Docker\up;

#[AsTask(description: 'Builds and starts the stack, then installs the application')]
function start(): void
{
    build();
    up();
    install();
    docker_compose_run('bin/console doctrine:migrations:migrate -n --allow-no-migration', 'app-builder');
}

#[AsTask(description: 'Installs the PHP and Node dependencies')]
function install(): void
{
    docker_compose_run('composer install -n --prefer-dist --optimize-autoloader', 'app-builder');

    if (is_file(__DIR__ . '/application/yarn.lock')) {
        docker_compose_run('yarn install --immutable', 'app-builder');
    } elseif (is_file(__DIR__ . '/application/package-lock.json')) {
        docker_compose_run('npm ci', 'app-builder');
    }

    if (is_file(__DIR__ . '/application/importmap.php')) {
        docker_compose_run('bin/console importmap:install', 'app-builder');
    }
}

#[AsTask(description: 'Runs PHPUnit', namespace: 'app:qa')]
function phpunit(): int
{
    return docker_exit_code('vendor/bin/phpunit', 'app-builder');
}

#[AsTask(description: 'Runs a security audit', namespace: 'app:qa')]
function security_audit(): int
{
    return docker_exit_code('composer audit', 'app-builder');
}
```

`castor app:install` already runs `composer install`, so keep your own `install`
only if you have Node dependencies or an importmap to install alongside it.

Node and Yarn are in the builder image either way, so nothing else is needed for
the frontend part.
