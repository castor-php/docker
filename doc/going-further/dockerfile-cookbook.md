---
title: Dockerfile cookbook
description: Concrete recipes for the Twig Dockerfiles shipped by the plugin.
---

# Dockerfile cookbook

Recipes for the images shipped by the plugin. They all assume a
`Dockerfile` next to your `castor.php`, registered on the service:

```php
(new SymfonyService('app'))
    ->withDirectory(__DIR__)
    ->withDockerfile(__DIR__ . '/Dockerfile')
```

and a `castor docker:build` after every change. The mechanism behind them is
described in [custom Dockerfile](custom-dockerfile.md), and the blocks and
variables in [PHP and
Symfony](../services/php.md#dockerfile-extension-points).

Examples extend `Dockerfile.frankenphp`, the default mode. In `PhpMode::Fpm`,
extend `Dockerfile` instead — the block names are the same.

## A system package in every container

`php_base` is the shared ancestor of every container of the application — the
frontend, the builder and the workers, in both modes:

```dockerfile
# syntax=ghcr.io/castor-php/twig-dockerfile:0.1
{% extends 'Dockerfile.frankenphp' %}

{% block php_base %}
    {{ parent() }}

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        poppler-utils \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
{% endblock %}
```

Both base images are Debian, so the same `apt-get` works in either mode.

## A tool only the builder needs

Compilers, linters and CLIs belong to `builder`: the container you get with
`castor app:bash`, and the one your CI runs. Keeping them out of `php_base`
keeps the runtime images small.

```dockerfile
{% block builder %}
    {{ parent() }}

RUN curl -sS https://get.symfony.com/cli/installer | bash -s -- --install-dir=/usr/local/bin
{% endblock %}
```

## PHP settings

[`withPhpIni()`](../services/php.md#php-configuration) sets directives without a
rebuild and is what you want most of the time. At build time, the file goes
where the image reads it — one `conf.d` for every SAPI in `PhpMode::FrankenPhp`,
`mods-available` plus `phpenmod` in `PhpMode::Fpm`. Either way `php_base` is the
block, so the setting reaches every container of the application.

```dockerfile
{% extends 'Dockerfile.frankenphp' %}

{% block php_base %}
    {{ parent() }}

COPY <<EOF /usr/local/etc/php/conf.d/50-project.ini
[PHP]
memory_limit = 512M
max_execution_time = 60
EOF
{% endblock %}
```

The number orders the file against the ones the plugin ships (`app-default` is
30, `app-builder` 40). In `PhpMode::Fpm` the same idea reads:

```dockerfile
{% extends 'Dockerfile' %}

{% block php_base %}
    {{ parent() }}

COPY <<EOF /etc/php/{{ php_version }}/mods-available/project.ini
; priority=50
[PHP]
memory_limit = 512M
max_execution_time = 60
EOF

RUN phpenmod project
{% endblock %}
```

## The fake `sudo` in the builder

The shipped Dockerfile carries this step commented out. Enabling it lets you
`sudo` inside the builder container, which is convenient while debugging:

```dockerfile
{% block builder %}
    {{ parent() }}

COPY base/sudo.sh /usr/local/bin/sudo
RUN curl -L https://github.com/tianon/gosu/releases/download/1.16/gosu-amd64 -o /usr/local/bin/gosu \
    && chmod u+s /usr/local/bin/gosu \
    && chmod +x /usr/local/bin/gosu \
    && chmod +x /usr/local/bin/sudo
{% endblock %}
```

> [!WARNING]
> This is a privilege escalation by design. Keep it for local development, never
> ship an image built this way to production.

## A conditional step

Build arguments are Twig variables, so a flag in `compose.override.yaml` is
enough to make a step optional:

```yaml
services:
    app-builder:
        build:
            args:
                with_blackfire: 'true'
```

```dockerfile
{% block builder %}
    {{ parent() }}

{% if with_blackfire|default(false) %}
RUN curl -sSL https://packages.blackfire.io/binaries/blackfire/latest/blackfire_linux_amd64 -o /usr/local/bin/blackfire \
    && chmod +x /usr/local/bin/blackfire
{% endif %}
{% endblock %}
```

`'true'` is JSON-decoded into a real boolean, and `|default(false)` keeps the
build working for the containers where the argument is not set.

## Custom nginx configuration (`PhpMode::Fpm`)

`nginx.conf` is itself a Twig template, so you can override one of its blocks
rather than maintain a copy. Put your version in the project, say
`docker/nginx.conf.twig`:

```twig
{% extends 'frontend/etc/nginx/nginx.conf.twig' %}

{% block server_locations %}
{{ parent() }}

        location /downloads/ {
            alias /var/www/var/downloads/;
        }
{% endblock %}
```

Your project is not the build context, so expose it as an additional one in
`compose.override.yaml`:

```yaml
services:
    app:
        build:
            additional_contexts:
                project: .
```

And render it over the shipped file:

```dockerfile
# syntax=ghcr.io/castor-php/twig-dockerfile:0.1
{% extends 'Dockerfile' %}

{% block frontend %}
    {{ parent() }}

{{ copy('@project/docker/nginx.conf.twig', '/etc/nginx/nginx.conf') }}
{% endblock %}
```

`copy()` renders the template with the build arguments and inlines the result,
so `{{ php_version }}` and friends work inside `nginx.conf.twig` too.

## Custom Caddyfile (`PhpMode::FrankenPhp`)

The shipped `Caddyfile.twig` has no blocks: to change it, write your own and
render it over the original. The same additional context as above applies:

```dockerfile
{% block frontend %}
    {{ parent() }}

{{ copy('@project/docker/Caddyfile.twig', '/etc/frankenphp/Caddyfile') }}
{% endblock %}
```

Start from
[the shipped one](https://github.com/castor-php/docker/blob/main/src/Resources/php/frontend-frankenphp/Caddyfile.twig)
if you use worker mode: the `frankenphp_worker_*` variables are yours to handle
in your copy.

## A different base image

Drop `{{ parent() }}` and the block is replaced outright — useful when your
company ships its own hardened PHP image:

```dockerfile
{% block php_base %}
FROM registry.example.com/php:{{ php_version }} AS php-base

ENV HOME=/home/app
ENV COMPOSER_MEMORY_LIMIT=-1

WORKDIR /var/www
{% endblock %}
```

Keep the stage name `php-base` and the `WORKDIR`: the other blocks build on
them. In `PhpMode::FrankenPhp` that image is also what serves — it has to carry
the `frankenphp` binary, and the extensions are then yours to install, since the
shipped block is what calls `install-php-extensions`.

## Project files in the image

Sources are bind-mounted at `/var/www`, so you rarely need to copy them in
development. When you do — an entrypoint, a certificate — go through an
additional context:

```dockerfile
COPY --from=project docker/entrypoint.sh /usr/local/bin/entrypoint
```
