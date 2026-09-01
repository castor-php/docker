---
title: Custom Dockerfile
description: Extend the images shipped by the plugin instead of rewriting them.
---

# Custom Dockerfile

Extra PHP extensions do not need a custom Dockerfile, use
`->addExtension('name')` instead — see [PHP and Symfony](../services/php.md).

For anything deeper, the Dockerfiles shipped by the plugin are
[Twig templates](https://github.com/castor-php/twig-dockerfile)
built by a BuildKit frontend, so you can extend them and override a single
block instead of copying the whole file:

```dockerfile
# syntax=ghcr.io/castor-php/twig-dockerfile:0.1
{% extends 'Dockerfile' %}

{% block builder %}
    {{ parent() }}
RUN echo "custom builder step"
{% endblock %}
```

Point the service at your file, then rebuild:

```php
(new SymfonyService('app'))
    ->withDirectory(__DIR__)
    ->withDockerfile(__DIR__ . '/Dockerfile')
```

```bash
castor docker:build
```

The blocks and variables a service exposes are listed with the service itself —
today [PHP and Symfony](../services/php.md#dockerfile-extension-points) is the
only one you can point at your own Dockerfile. This page explains the mechanism;
the [cookbook](dockerfile-cookbook.md) has ready-made recipes.

## Blocks and inheritance

`{% extends %}`, `{% block %}`, `{{ parent() }}` and the `include` tag work as
in any Twig template, across Dockerfiles:

* `{{ parent() }}` keeps the shipped content and appends yours — the usual case
* omitting it replaces the block entirely, base image included
* you can extend a Dockerfile that already extends another one:
  `Dockerfile.frankenphp` extends `Dockerfile` and overrides the blocks where
  the two PHP installations differ

Only the blocks you override change, so the plugin can keep improving the rest
of the image without breaking your build.

> [!NOTE]
> Nothing is installed locally: the `# syntax=` line tells Docker to pull the
> frontend and build with it.

## Build arguments are Twig variables

Every build argument of the service is available as a variable in the template.
Values are JSON-decoded when they can be, so a service can pass more than
strings:

```dockerfile
{% for extension in php_extensions %}   {# a real array, not a string #}
        "php{{ php_version }}-{{ extension }}" \
{% endfor %}
```

Use `is defined` for the ones a service only sets in some configurations:

```dockerfile
{% if frankenphp_worker_file is defined %}
```

You can add your own from `compose.override.yaml`, per container:

```yaml
services:
    app:
        build:
            args:
                with_blackfire: 'true'   # decoded as a boolean
```

`castor docker:build` also forwards the `build_args` context variable to every
service — handy for a flag you want to flip from the command line, blunter than
the per-service form above.

## `copy()`, to render a template as a file into the image

`copy(template, target, heredoc = 'EOF')` renders a template with the same
variables and writes the result into the image as a heredoc `COPY` — the file
never has to exist on disk in its final form:

```dockerfile
{{ copy('frontend/etc/nginx/nginx.conf.twig', '/etc/nginx/nginx.conf') }}
```

Pass a third argument when the rendered content itself contains `EOF`.

Configuration files rendered this way are templates too, with their own blocks,
so they can be extended just like a Dockerfile.

## Where files come from

The build context is **not your project**: it is the plugin's resource
directory (`.castor/vendor/castor-php/docker/src/Resources/php` for PHP). That
is what makes `{% extends 'Dockerfile' %}` resolve, and it means a bare
`COPY composer.json ...` in one of your blocks cannot see your application.

To reach your own files, declare an additional build context in
`compose.override.yaml`. Each container of a service has its own `build`
section, so repeat it for the ones you rebuild:

```yaml
services:
    app:
        build:
            additional_contexts:
                project: .
    app-builder:
        build:
            additional_contexts:
                project: .
```

Then refer to it with `@name/` in templates, and `--from=name` in `COPY`:

```dockerfile
{{ copy('@project/docker/nginx.conf.twig', '/etc/nginx/nginx.conf') }}
COPY --from=project docker/entrypoint.sh /usr/local/bin/entrypoint
```

A template loaded from an additional context can still extend one from the
plugin: names without `@` always resolve in the build context.

> [!NOTE]
> Your application directory is bind-mounted at `/var/www` at runtime, so
> copying sources into the image is rarely what you want in development.

## Pinning the frontend

The frontend runs inside your builds, so the plugin pins the version it is
tested against and passes it as the `BUILDKIT_SYNTAX` build argument, on every
service it generates. BuildKit honours that over the `# syntax=` line, so your
own Dockerfile is built with that same pinned version, whatever its first line
says.

That argument is also the only thing pinning it for the Dockerfiles shipped by
the plugin, which carry **no `# syntax=` line at all**. The reason is the rule
above: a template that extends another one cannot hold anything outside its
blocks, and that directive is a comment — text. A template carrying it can be
built and cannot be extended, which is the opposite of what a shipped template
is for. Keep it in mind for your own files too: the Dockerfile you point a
service at is the one being built and keeps its `# syntax=` line, but the moment
another of your Dockerfiles extends it, that line has to go.

To try another one — a release candidate, or a local build of the frontend —
set it in your context:

```php
#[AsContext(default: true)]
function default_context(): Context
{
    return new Context([
        'twig_dockerfile_frontend' => 'twig-dockerfile:local',
    ]);
}
```

## Overriding the compose service

When the change belongs to the compose file rather than to the image, use
`compose.override.yaml` — it is never regenerated:

```yaml
services:
    app:
        environment:
            - XDEBUG_MODE=debug
        extra_hosts:
            - host.docker.internal:host-gateway
```
