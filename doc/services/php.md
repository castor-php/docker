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
    ->withNodeVersion('22')          // Node.js in the builder container (default: 24)
    ->withSudo()                     // Passwordless sudo in the builder, off by default
    ->withMode(PhpMode::FrankenPhp)  // PhpMode::FrankenPhp (default) or PhpMode::Fpm
    ->withDatabaseService($databaseService)
    ->withMailerService($mailpitService)
    ->withDomain('app.example.test', 'example.test')
    ->withHttpAccess()               // Also serve plain HTTP, without redirecting to HTTPS
    ->addExtension('redis')          // An extension, in every container of the application
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

The mode decides which PHP the whole application runs on, not only the one
serving it: in `PhpMode::FrankenPhp` the builder and the workers run the PHP of
the FrankenPHP image, and in `PhpMode::Fpm` they run the Debian packages the
frontend serves with. One installation either way — a version, an extension or
an ini directive cannot be in the container running your tests and missing from
the one serving your pages.

Known limitation of `PhpMode::FrankenPhp`: there is no `/php-fpm-status`
monitoring endpoint.

## Extensions

These extensions are installed by default: `apcu`, `bcmath`, `curl`, `iconv`,
`intl`, `mbstring`, `pgsql`, `uuid`, `xml`, `zip`. Add more with
`->addExtension('name')` — no custom Dockerfile needed. They are installed once,
in the stage every container is built on, so the list is the same in the
application, in the builder and in the workers.

Names are the Debian ones, which is what `PhpMode::Fpm` installs
(`php{version}-{name}` from sury). `PhpMode::FrankenPhp` installs with
[`install-php-extensions`](https://github.com/mlocati/docker-php-extension-installer),
whose catalogue names one module at a time where a Debian package sometimes
ships several — the plugin translates the ones that differ:

| Written | Installed in `PhpMode::FrankenPhp` |
|---------|------------------------------------|
| `mysql` | `mysqli`, `pdo_mysql` |
| `pgsql` | `pgsql`, `pdo_pgsql` |
| `sqlite3` | `sqlite3`, `pdo_sqlite` |

Anything else reaches the installer as written, so an extension it does not know
fails the build with the name you gave it.

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
| `php_base` | `php-base` | — | The PHP every container runs: `dunglas/frankenphp`, or Debian + sury in `PhpMode::Fpm`, with the extensions and the base ini files |
| `frontend` | `frontend` | `app` | The Caddyfile and the server command, or nginx + PHP-FPM in `PhpMode::Fpm` |
| `worker` | `worker` | `app-worker-{name}` | `php-base`, nothing more |
| `builder` | `builder` | `app-builder` | Composer, PIE, Node, git, make and the shell completion |

Every other stage starts `FROM php-base`, in both modes, so a step added there
reaches the whole application. The `builder` block has two inner blocks for what
depends on the PHP installation, already overridden in FrankenPHP mode:
`builder_php_dev` (the `-dev` package, which the FrankenPHP image carries
already) and `builder_php_configuration` (where the builder ini file goes).

### Variables

| Variable | Type | Comes from |
|----------|------|------------|
| `php_version` | string | `withVersion()` (default `8.5`) |
| `php_extensions` | list of strings | the default list plus `addExtension()` |
| `node_version` | string | `withNodeVersion()` (default `24.x`), only in the builder stage |
| `package_manager` | string | `withPackageManager()` (default `npm`), only in the builder stage |
| `sudo` | string | `withSudo()`, only in the builder stage and only when it is on |
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

## Node.js

The builder container ships Node, installed from the NodeSource repository, so
`castor app:bash` can run whatever your application builds its assets with.
Nothing else is assumed: this plugin declares no asset task and no package
manager, and `app:install` installs the PHP dependencies only.

```php
(new SymfonyService('app'))
    ->withNodeVersion('22')
    ->withPackageManager(PackageManager::Pnpm)   // Npm (default), Yarn or Pnpm
```

NodeSource publishes one repository per major version, so the major is all that
is used: `22`, `22.x` and `v22.11.0` all name the same one. A version naming no
major is rejected when the service is declared, rather than failing the build.

Corepack is enabled whichever package manager you pick, so `npm`, `yarn` and
`pnpm` are all reachable and a `packageManager` field in your `package.json` is
honoured either way. `withPackageManager()` only decides what a project
declaring no such field finds ready to run: npm comes with node and needs
nothing prepared, yarn is pinned to its current stable, pnpm is activated
through corepack.

> [!NOTE]
> Node 25 dropped corepack from its distribution. The image installs it from npm
> when the version you asked for does not ship it, so none of this depends on
> the major you run.

> [!NOTE]
> Only the builder stage installs Node — it is where the build commands run.
> An application sharing the builder of another one
> ([see above](#sharing-one-builder-container)) therefore gets the Node version
> of that one, since it is that image which carries it.

## PHP configuration

```php
use Castor\Docker\Service\PhpIniScope;

(new SymfonyService('app'))
    ->withPhpIni(['memory_limit' => '1G'])
    ->withPhpIni(['memory_limit' => '-1', 'max_execution_time' => 0], PhpIniScope::Cli)
    ->withPhpIni(['opcache.validate_timestamps' => false], PhpIniScope::Web)
```

The directives are written to an ini file **mounted into the containers**, not
built into the image, so changing one costs a `castor docker:up` and not a
rebuild. The containers concerned are recreated when the file changes, so the
new value is actually in effect rather than sitting in the compose file.

The scope says which PHP they reach, and the two rarely want the same settings:

| Scope | Containers | The PHP that |
|-------|------------|--------------|
| `PhpIniScope::Cli` | the builder, the workers | runs your commands |
| `PhpIniScope::Web` | the application | serves your requests |
| `PhpIniScope::All` (default) | both | |

Values are written the way php.ini reads them, so `true` and `false` come out as
`On` and `Off` rather than as `1` and the empty string. The file is loaded as
`99-castor.ini`, after everything the image ships, so what you set here wins
over the defaults below.

> [!NOTE]
> An application [sharing the builder](#sharing-one-builder-container) of
> another one has no builder of its own to configure, so a `Cli` scope only
> reaches its workers. Set the builder's directives on the application that owns
> it.

### What the images already set

This plugin ships two ini files, and your directives are read after both:

| File | Priority | Applies to |
|------|----------|------------|
| `app-default.ini` | 30 | every container |
| `app-fpm.ini` | 40 | the application container, in FPM mode |

`app-default.ini` sets a 512M memory limit, no time limit, `display_errors` on,
UTC, a writable phar and opcache sizes; `app-fpm.ini` then takes the application
container down to 128M with a 30 second limit, the shape of a web request.
[Extend the Dockerfile](#dockerfile-extension-points) if you would rather change
them at build time than mount over them.

## Sudo in the builder

```php
(new SymfonyService('app'))->withSudo()
```

Installs a passwordless `sudo` in the builder container: a two line script around
[gosu](https://github.com/tianon/gosu), so anything running in that container
becomes root in it without knowing anything. It is there for the everyday
development need — installing a package to try something out, taking back a file
the container wrote as another user — and it is off unless you ask.

> [!WARNING]
> Ask for it on developer machines only. An image carrying this has no root
> barrier left inside it, so anything that reaches the container has it too.
> The builder container is not published by these tasks, but a pipeline reusing
> the image would carry the sudo with it.

## Quality assurance

```bash
castor app:qa:phpstan   # PHPStan
castor app:qa:cs        # PHP CS Fixer
castor app:qa:rector    # Rector
castor app:qa:twig-cs   # Twig CS Fixer (SymfonyService only)
```

The tools are **installed and run inside the builder container**, by the
composer of the image, so they see the PHP version, the extensions and the
`vendor/` the application actually runs on — not whichever PHP happens to run
castor. Composer resolves a package against the platform it runs on, so
installing from the host is how you end up with a tool the container cannot run,
or one older than the application deserves; a dependency requiring an extension
of the image — `ext-amqp`, `ext-pgsql` — cannot be installed from a host that
does not have it either. PHPStan resolving a class against the wrong PHP
version, or PHP CS Fixer warning that your host PHP is newer than the one your
`composer.json` supports, both go away.

They are installed in `.castor/vendor/.tools/`, which the builder container
mounts at `/castor-tools`. The directory being on the host, an installation
outlives the containers; nothing is added to the image, so changing a tool
version does not mean rebuilding it. An installation is redone when the tools it
requires change, and when the PHP version of the application does — the version
the tool was resolved against.

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
> Composer resolves the tools in the container, against the PHP version and the
> extensions of the application, so nothing has to be able to run on your host.
> Pin a version with `withPhpStanVersion()` & co when you want it stable rather
> than latest.

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
