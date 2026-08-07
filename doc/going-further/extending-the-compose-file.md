---
title: Extending the compose file
description: Add or change anything in the generated compose file, with the builder attribute or the two generation events.
---

# Extending the compose file

`compose.generated.yaml` is rebuilt from your `castor.php` on every run: editing
it is pointless, and the plugin says so at the top of the file. Three extension
points let you change what it contains.

Which one to reach for:

| You want to | Use |
|---|---|
| add a container, or tweak one | [`#[AsDockerComposeBuilder]`](#asdockercomposebuilder) |
| the same, with control over ordering | [`DockerComposeBuilderEvent`](#dockercomposebuilderevent) |
| set a compose key the builder does not model | [`DockerComposeWriteEvent`](#dockercomposewriteevent) |
| ship something reusable, with its own tasks | [a service](writing-a-service.md) |

A one-off container belongs here. Something you want to configure, reuse across
projects or give tasks to is a [service](writing-a-service.md).

## `#[AsDockerComposeBuilder]`

The shortest way in. Mark a function, receive the
[`ComposeBuilder`](#the-composebuilder), do what you need:

```php
use Castor\Docker\Attribute\AsDockerComposeBuilder;
use Castor\Docker\Service\Builder\ComposeBuilder;

#[AsDockerComposeBuilder]
function add_adminer(ComposeBuilder $builder): void
{
    $builder
        ->service('adminer')
            ->image('adminer:5')
            ->withHttpRouting('adminer.myproject.test', 8080)
            ->profile('default')
        ->end()
    ;
}
```

The function may take the `Context` as a second parameter, to read the project
configuration:

```php
#[AsDockerComposeBuilder]
function add_adminer(ComposeBuilder $builder, Context $context): void
{
    $rootDomain = $context->data['root_domain'] ?? 'castor.local';

    $builder->service('adminer')->withHttpRouting("adminer.{$rootDomain}", 8080)->end();
}
```

Pass a `priority` to order several of them — highest first, `0` by default:

```php
#[AsDockerComposeBuilder(priority: 10)]
```

Only plain functions carry it: methods and closures are not discovered.

## `DockerComposeBuilderEvent`

The same phase, as an event. It carries the builder and the context, and is
dispatched **before** the attributed functions run — so use it when you need to
act ahead of them, replace the builder wholesale, or stop propagation.

```php
use Castor\Attribute\AsListener;
use Castor\Docker\Event\DockerComposeBuilderEvent;

#[AsListener(DockerComposeBuilderEvent::class)]
function cap_elasticsearch_heap(DockerComposeBuilderEvent $event): void
{
    $event->builder
        ->service('elasticsearch')
            ->environment('ES_JAVA_OPTS', '-Xms512m -Xmx512m')
        ->end()
    ;
}
```

`service()` returns the existing service when there is one, and creates it
otherwise — which is how you change a service someone else registered, and also
means a typo silently adds an empty service rather than failing.

Both this event and the attribute run before the plugin creates the
bind-mounted host directories, so a mount added here still gets its directory
created — and does not end up owned by `root` after docker creates it.

## `DockerComposeWriteEvent`

Dispatched with the configuration as a plain array, right before it is written.
This is the escape hatch for everything the builder does not model — `deploy`,
`logging`, `ulimits`, `secrets`, the `x-` extension fields:

```php
use Castor\Docker\Event\DockerComposeWriteEvent;

#[AsListener(DockerComposeWriteEvent::class)]
function limit_elasticsearch_memory(DockerComposeWriteEvent $event): void
{
    $event->compose['services']['elasticsearch']['deploy']['resources']['limits']['memory'] = '1g';
}
```

Nothing validates the result: the array is dumped as-is, so a mistake here
surfaces as a `docker compose` error rather than a PHP one. Prefer the builder
when it can express the change.

## The ComposeBuilder

`ComposeBuilder` declares the top-level keys:

```php
$builder->volume('my-data');                    // a named volume
$builder->config('my.conf', $content);          // an inline config, mounted with ServiceBuilder::config()
$builder->service('name');                      // get or create a service, returns a ServiceBuilder
```

`withHttpRouting()` takes the port as its **second, required** argument. Left
out, caddy-docker-proxy would resolve `{{upstreams}}` against whatever the image
happens to expose — the first of several, or port 80 when it exposes nothing —
which routes to the wrong one silently and answers 502:

```php
$builder->service('adminer')->withHttpRouting('adminer.myproject.test', 8080);
```

`ServiceBuilder` covers what the plugin's own services need — `image()`,
`build()`, `environment()`, `volume()`, `port()`, `command()`, `user()`,
`init()`, `workingDir()`, `label()`, `profile()`, `dependsOn()`,
`healthcheck()`, `config()`, `restart()`, `ulimits()`, `dns()`, `extraHost()`,
`deploy()` and `withHttpRouting()` — and `end()` returns you to the
`ComposeBuilder`. Anything it does not cover is a
[`DockerComposeWriteEvent`](#dockercomposewriteevent) away.

```php
$builder
    ->service('clickhouse')
        ->restart('on-failure:10')
        ->ulimits('nofile', ['soft' => 262144, 'hard' => 262144])
        ->dns('1.1.1.1', '8.8.8.8')
        ->extraHost('host.docker.internal', 'host-gateway')
        ->deploy(['resources' => ['reservations' => ['devices' => [['capabilities' => ['gpu']]]]]])
    ->end()
;
```

`environment()` takes a `null` value, which emits `KEY: null` — the compose
syntax passing the variable through from the environment castor runs in. Use it
for a value that changes between invocations, rather than freezing it in the
generated file:

```php
->environment('GIT_WORKTREE')          // KEY: null, read from your shell
->environment('APP_ENV', 'dev')        // APP_ENV: dev, written in the file
```

### Inline configs

Compose interpolates the file it reads, the content of the configs included — an
nginx configuration would reach the container stripped of every `$host`, `$uri`
and `$document_root`, with only a "variable is not set" warning to show for it.
Every `$` is therefore escaped on the way out. Pass `interpolate: true` when a
config really does mean to read `${PROJECT_NAME}` & co:

```php
$builder->config('app.conf', 'project = ${PROJECT_NAME}', interpolate: true);
```

Compose also does not recreate a container when only the content of a config
changed, so a server that reads its configuration at boot keeps running with the
old one until someone thinks of `--force-recreate`. Ask for a digest of the
content in a label, and the container definition changes with the configuration:

```php
$builder->service('agent')->config('agent.yml', '/etc/agent.yml', recreateOnChange: true);
```

Leave it off for a server that reloads its configuration by itself — the digest
would restart it for nothing.

## Order of execution

1. every registered [service](../services/index.md) contributes, in registration order
2. `DockerComposeBuilderEvent` listeners, by descending `AsListener` priority
3. `#[AsDockerComposeBuilder]` functions, by descending `priority`
4. the host directories of the bind mounts are created
5. the [`extra_hosts` of the project domains](../services/router.md#reaching-your-own-domains-from-inside-a-container)
   are added to every service
6. `DockerComposeWriteEvent` listeners, by descending priority
7. `compose.generated.yaml` is written

Step 5 runs after the listeners on purpose, so a domain routed by one of them is
resolvable too — and before the write event, so that event remains the last word
on the file.

Priorities order listeners within a step, not across steps: an
`#[AsDockerComposeBuilder(priority: 100)]` still runs after every
`DockerComposeBuilderEvent` listener.

## A worked example

The [example project](https://github.com/castor-php/docker/blob/main/example/castor.php)
uses all three: the attribute adds Adminer, the builder event caps the
Elasticsearch heap, and the write event puts a memory limit on it through
`deploy`.
