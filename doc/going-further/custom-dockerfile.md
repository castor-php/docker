---
title: Custom Dockerfile
description: Extend the images shipped by the plugin instead of rewriting them.
---

# Custom Dockerfile

Extra PHP extensions do not need a custom Dockerfile, use
`->addExtension('name')` instead — see [PHP and Symfony](../services/php.md).

For anything deeper, the Dockerfiles shipped by the plugin are
[Twig templates](https://github.com/castor-php/docker/tree/main/twig-dockerfile)
built by a BuildKit frontend, so you can extend them and override a single
block instead of copying the whole file:

```dockerfile
# syntax=ghcr.io/castor-php/twig-dockerfile:latest
{% extends 'Dockerfile' %}

{% block builder %}
    {{ parent() }}
RUN echo "custom builder step"
{% endblock %}
```

Extend `Dockerfile` for `PhpMode::Fpm`, or `Dockerfile.frankenphp` for
`PhpMode::FrankenPhp`. The blocks you can override are `php_base`, `frontend`,
`worker` and `builder`.

Then point your service at it:

```php
(new SymfonyService('app'))
    ->withDirectory(__DIR__)
    ->withDockerfile(__DIR__ . '/Dockerfile')
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
