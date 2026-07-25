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
    ->addWorker('messenger', 'php bin/console messenger:consume async')
    ->withFrankenPhpWorkerMode('public/index.php', num: 4)
```

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

## Quality assurance

```bash
castor app:qa:phpstan   # PHPStan
castor app:qa:cs        # PHP CS Fixer
castor app:qa:rector    # Rector
castor app:qa:twig-cs   # Twig CS Fixer (SymfonyService only)
```

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

`SymfonyService` adds:

* `castor app:symfony` — any Symfony console command
* `castor app:cache-clear`, `castor app:cache-warmup`
* `castor app:db:migrate`, `castor app:db:fixtures`
* `castor app:qa:twig-cs`

## Containers

* `app` — the frontend, FrankenPHP or nginx + PHP-FPM depending on the mode
* `app-builder` — the builder container, on the `builder` profile
* `app-worker-{name}` — one per [background worker](../going-further/workers.md)
