---
title: PHP and Symfony
description: Serve a PHP application with FrankenPHP or nginx + PHP-FPM, with a builder container and QA tasks.
---

# PHP and Symfony

`SymfonyService` extends `PHPService` with Symfony-specific tasks (console,
cache, migrations, Twig CS). Use `PHPService` for any other PHP application.

## Configuration

```php
(new SymfonyService('app'))          // Service name, the only constructor argument
    ->withDirectory(__DIR__)         // Application directory (default: '.')
    ->withVersion('8.5')             // PHP version (default: 8.5)
    ->withMode(PhpMode::FrankenPhp)  // PhpMode::FrankenPhp (default) or PhpMode::Fpm
    ->withDatabaseService($databaseService)
    ->withMailerService($mailpitService)
    ->withDomain('app.example.test', 'example.test')
    ->withHttpAccess()               // Also serve plain HTTP, without redirecting to HTTPS
    ->addExtension('redis')          // Adds "php{version}-redis" (fpm) or the FrankenPHP equivalent
    ->addWorker('messenger', 'php bin/console messenger:consume async', 'unless-stopped')
    ->withFrankenPhpWorkerMode('public/index.php', num: 4)
```

## Applications inside a monorepo

`withDirectory()` is what gets **mounted**; `withWorkingDirectory()` names the
application **inside** it. They only coincide when the application owns its
directory:

```php
(new SymfonyService('backend'))
    ->withDirectory(__DIR__)               // mount the repository root
    ->withWorkingDirectory('apps/backend') // the application lives here
```

Composer, the console and the QA tools then run in `/var/www/apps/backend`, and
the document root of the frontend follows — the web server configuration is
built with the application directory, not with a fixed `/var/www/public`.

### Sharing one builder container

Three applications of the same repository do not need three identical
`-builder` containers:

```php
$backend = (new SymfonyService('backend'))
    ->withDirectory(__DIR__)
    ->withWorkingDirectory('apps/backend');

$demo = (new SymfonyService('demo'))
    ->withDirectory(__DIR__)
    ->withWorkingDirectory('apps/demo')
    ->withSharedBuilder($backend);      // no "demo-builder" container
```

`castor demo:install`, `castor demo:composer` and `castor demo:symfony` then run
in `backend-builder`, with the working directory set to `apps/demo`. The shared
builder has to mount a directory containing both applications, which is what
mounting the repository root gives you.

`->withoutBuilder()` generates no builder container at all and runs those tasks
in the application container itself — only enough if it carries the tooling.

## Runtime modes

* `PhpMode::FrankenPhp` (default) — serves the application with
  [FrankenPHP](https://frankenphp.dev/) (`dunglas/frankenphp` image + Caddy).
  A single process, no PHP-FPM and no nginx.
* `PhpMode::Fpm` — the classic nginx + PHP-FPM stack.

Only the frontend container differs between modes: the builder and worker
containers are identical either way. Known limitation of `PhpMode::FrankenPhp`:
there is no `/php-fpm-status` monitoring endpoint.

## Extensions

These extensions are installed by default: `apcu`, `bcmath`, `curl`, `iconv`,
`intl`, `mbstring`, `pgsql`, `uuid`, `xml`, `zip`. Add more with
`->addExtension('name')` — no custom Dockerfile needed.

## FrankenPHP worker mode

```php
->withFrankenPhpWorkerMode(string $script = 'public/index.php', ?int $num = null, bool $watch = true)
```

Boots `$script` once and keeps it in memory to handle every request, instead of
re-interpreting it per request. Only takes effect in `PhpMode::FrankenPhp`. Your
application needs a compatible runtime to loop over incoming requests — for
Symfony, install
[`runtime/frankenphp-symfony`](https://github.com/php-runtime/frankenphp-symfony)
and point `$script` at `public/index.php`.

`$watch` (on by default) restarts the worker when files under the application
directory change, which is what you want locally.

## Dockerfile extension points

Both services are built from a [Twig
Dockerfile](../going-further/custom-dockerfile.md) you can extend to override
one part of the image and keep the rest. Which file to extend depends on the
mode:

```dockerfile
# syntax=ghcr.io/castor-php/twig-dockerfile:0.1
{% extends 'Dockerfile.frankenphp' %}   {# PhpMode::Fpm: extend 'Dockerfile' #}
```

Then point the service at your file with
`->withDockerfile(__DIR__ . '/Dockerfile')`.

### Blocks

| Block | Stage | Container | What it holds |
|-------|-------|-----------|---------------|
| `php_base` | `php-base` | — | Debian + PHP CLI from sury, with the extensions and the base ini files |
| `frontend` | `frontend` | `app` | FrankenPHP, or nginx + PHP-FPM in `PhpMode::Fpm` |
| `worker` | `worker` | `app-worker-{name}` | `php-base`, nothing more |
| `builder` | `builder` | `app-builder` | Composer, PIE, Node, git, make and the shell completion |

`worker` and `builder` always start `FROM php-base`, so a change in `php_base`
reaches them in both modes. The frontend only does in `PhpMode::Fpm` — in
FrankenPHP mode it starts from the `dunglas/frankenphp` image instead, and needs
its own step.

### Variables

| Variable | Type | Comes from |
|----------|------|------------|
| `php_version` | string | `withVersion()` (default `8.5`) |
| `php_extensions` | list of strings | the default list plus `addExtension()` |
| `app_root` | string | `withWorkingDirectory()`, as a path under `/var/www`; absent when the application owns its directory |
| `frankenphp_worker_file` | string | `withFrankenPhpWorkerMode()`, as a path under `/var/www` |
| `frankenphp_worker_num` | int | its `$num` argument, only when you pass one |
| `frankenphp_worker_watch` | bool | its `$watch` argument |

The three `frankenphp_*` ones only exist when worker mode is on, so guard them
with `{% if frankenphp_worker_file is defined %}`.

### Configuration templates

The `frontend` block renders these with `copy()`. They are Twig templates too,
so a project can extend them instead of rewriting them:

| Mode | Template | Written to | Blocks |
|------|----------|------------|--------|
| `PhpMode::Fpm` | `frontend/etc/nginx/nginx.conf.twig` | `/etc/nginx/nginx.conf` | `root`, `http`, `server`, `server_locations`, `events` |
| `PhpMode::FrankenPhp` | `frontend-frankenphp/Caddyfile.twig` | `/etc/frankenphp/Caddyfile` | none, replace the whole file |

### Files in the build context

`COPY` in your blocks reads from the plugin's `Resources/php` directory, not
from your project — see [where files come
from](../going-further/custom-dockerfile.md#where-files-come-from). What is
there: `base/php-configuration/`, `builder/php-configuration/`,
`frontend/php-configuration/`, `frontend/etc/` and `base/sudo.sh`.

The [Dockerfile cookbook](../going-further/dockerfile-cookbook.md) turns all of
this into concrete recipes.

## Quality assurance

```bash
castor app:qa:phpstan   # PHPStan
castor app:qa:cs        # PHP CS Fixer
castor app:qa:rector    # Rector
castor app:qa:twig-cs   # Twig CS Fixer (SymfonyService only)
```

The tools are **installed on the host by castor** and **run inside the builder
container**, so they see the PHP version, the extensions and the `vendor/` the
application actually runs on — not whichever PHP happens to run castor. PHPStan
resolving a class against the wrong PHP version, or PHP CS Fixer warning that
your host PHP is newer than the one your `composer.json` supports, both go away.

They are installed in `.castor/vendor/.tools/`, which the builder container
mounts at `/castor-tools`. Nothing is added to the image, so changing a tool
version does not mean rebuilding it.

> [!NOTE]
> By default it installs the latest version of each tool, which may change 
> over time. Pin the version with `withPhpStanVersion()` & co if you want to
> keep it stable.

Each application gets its own installation, named after it — `app-phpstan`,
`backend-rector`. Two applications of the same repository pinning different
versions therefore keep one each, instead of reinstalling over each other on
every run, and bumping a version reinstalls in place rather than leaving the
previous one behind.

Each task forwards its arguments, and returns the tool's exit code:

```bash
castor app:qa:phpstan --level=8 src
castor app:qa:cs --dry-run
```

**What gets analysed** is the tool's own business whenever the application
configures it. A `phpstan.neon`, a `.php-cs-fixer.php` or a `rector.php` in the
working directory of the application means the task runs the tool with no path
at all, so the `paths` of your PHPStan configuration, the finder of your PHP CS
Fixer one and the `withPaths()` of your Rector one are what decide.

That is not a detail: none of these tools treats a path on the command line as a
restriction of its configured paths, it *replaces* them. Passing the application
directory by default would analyse `vendor/` and `var/` along with the sources of
an application whose `phpstan.neon` says `paths: [src]` — while still reporting
that configuration file as used, since everything else in it does apply.

Only an application configuring nothing gets a path from the plugin: the
application directory for PHPStan, its `src/` for PHP CS Fixer and Rector, which
write rather than report.

> [!NOTE]
> Composer resolves the tools against the PHP version running castor, not the
> one in the container, so the version it picks has to be able to run on both.
> Pin it with `withPhpStanVersion()` & co if your host PHP is far ahead of the
> one the application runs.

Pin the tool versions and add PHPStan extensions from the service:

```php
(new SymfonyService('app'))
    ->withPhpStanVersion('^2.0')
    ->withPhpCsFixerVersion('^3.0')
    ->withRectorVersion('^2.0')
    ->withPhpTwigCsFixerVersion('^3.0')   // SymfonyService only
    ->addPhpStanExtraDependency('phpstan/phpstan-symfony', '^2.0')
```

## Generated tasks

Shared by both services:

* `castor app:bash` — a bash shell in the builder container
* `castor app:install` — `composer install`
* `castor app:composer` — any Composer command
* `castor app:qa:phpstan`, `castor app:qa:cs`, `castor app:qa:rector`

Only when the application declares [workers](../going-further/workers.md):

* `castor app:worker:restart [worker]` — restart them all, or the one named
* `castor app:worker:stop [worker]` — stop them all, or the one named

`SymfonyService` adds:

* `castor app:symfony` — any Symfony console command
* `castor app:cache-clear`, `castor app:cache-warmup`
* `castor app:db:migrate`, `castor app:db:fixtures`
* `castor app:qa:twig-cs`

## Containers

* `app` — the frontend, FrankenPHP or nginx + PHP-FPM depending on the mode
* `app-builder` — the builder container, on the `builder` profile
* `app-worker-{name}` — one per [background worker](../going-further/workers.md)
