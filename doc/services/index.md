---
title: Services
description: Every service shipped with the Castor Docker plugin.
---

# Services

A service describes one or more containers, plus the tasks that come with them.
Register the ones you need on the `RegisterServiceEvent`, and the plugin does the
rest.

| Service | What it gives you |
|---------|-------------------|
| [`PHPService` / `SymfonyService`](php.md) | PHP application served by FrankenPHP or nginx + PHP-FPM, with a builder container and QA tasks |
| [`GoService`](go.md) | Go application built and run from the mounted sources |
| [`RustService`](rust.md) | Cargo application, with clippy and rustfmt tasks |
| [`NodeService`](node.md) | Node.js application — React, Next.js or a plain server, no PHP |
| [`GoBuilder`](go.md#gobuilder) / [`RustBuilder`](rust.md#rustbuilder) | One compiler container for a repository, declaring the applications it builds |
| [`BinaryRunService`](rust.md#binaryrunservice) | Runs one compiled binary, whatever produced it |
| [`PostgresService`](databases.md#postgresservice) | PostgreSQL, with a `postgres:client` task |
| [`MySQLService`](databases.md#mysqlservice) | MySQL, with a `mysql:client` task |
| [`MariaDBService`](databases.md#mariadbservice) | MariaDB, with a `mariadb:client` task |
| [`ClickhouseService`](databases.md#clickhouseservice) | ClickHouse and its keeper |
| [`RedisService`](infrastructure.md#redisservice) | Redis and the RedisInsight UI |
| [`RabbitMQService`](infrastructure.md#rabbitmqservice) | RabbitMQ and its management UI |
| [`ElasticsearchService`](infrastructure.md#elasticsearchservice) | Elasticsearch and Kibana |
| [`MeilisearchService`](infrastructure.md#meilisearchservice) | Meilisearch and its search preview dashboard |
| [`MailpitService`](infrastructure.md#mailpitservice) | SMTP catch-all with a web UI |
| [`MercureService`](mercure.md) | Mercure hub for real-time updates — or none at all, FrankenPHP embeds one |
| [`RustFSService`](object-storage.md) | S3-compatible object storage and its console, with the buckets you declare |
| [`RedirectionioAgentService`](redirectionio.md) | redirection.io agent as a reverse proxy |

The [router](router.md) is not in that list: it is global, shared by every
project, and managed with the `docker:router:*` tasks rather than registered in
`castor.php`.

All of them share the same [fluent configuration
API](../getting-started/configuring-services.md).

## Registering a service

```php
#[AsListener(RegisterServiceEvent::class)]
function register_service(RegisterServiceEvent $event)
{
    $event->addService(new RedisService());
}
```

Keep a variable when another service needs to reference it — a database linked to
an application, or an application behind the redirection.io agent:

```php
$postgres = new PostgresService();
$event->addService($postgres);

$event->addService(
    (new SymfonyService('app'))
        ->withDirectory(__DIR__)
        ->link($postgres)
);
```

## Linking services

What an application needs from a database, a search engine or an object storage
is always the same two things: where to find it and how to authenticate — a
handful of environment variables — and not to start before it is there.
`link()` gives it both:

```php
(new SymfonyService('app'))
    ->link($postgres)       // DATABASE_URL
    ->link($mailpit)        // MAILER_DSN
    ->link($meilisearch)    // MEILISEARCH_URL, MEILISEARCH_API_KEY, …
    ->link($rustfs)         // AWS_ACCESS_KEY_ID, AWS_ENDPOINT_URL_S3, …
    ->link($mercure)        // MERCURE_URL, MERCURE_PUBLIC_URL, MERCURE_JWT_SECRET
```

The variables reach every container of the application — for a PHP one, the
builder and the workers too, since a migration or a message handler talks to
the same database as the pages — and those containers wait for the linked
service with a `depends_on`. They are named after what the libraries read, not
after the service, which is the whole point: an application picks them up with
no configuration, and keeps its `.env` for production. Each service page lists
the variables it hands over.

Every application service links: `PHPService`, `SymfonyService`, `GoService`,
`RustService`, `NodeService` and `BinaryRunService`. `withDatabaseService()` and
`withMailerService()` are the same thing under the names they always had.

Compose has nothing of the sort. Its old `links` exported variables like
`DB_PORT_5432_TCP_ADDR` that no library reads, and its provider services prefix
every variable with the name of the provider — neither can produce
`AWS_ACCESS_KEY_ID` or a `MAILER_DSN` from a service named `mailpit`. The plugin
knows the values when it generates the file, so it writes them there.

Two things are worth knowing:

* the linked variables are real environment variables, which win over the
  `.env` files of Symfony and Laravel — `.env.local` included;
* two linked services handing over the same variable — two databases, two
  `DATABASE_URL` — stop the generation with an error rather than letting one of
  them silently win.

A service of your own becomes linkable by implementing
`LinkableServiceInterface`, see [writing your own
service](../going-further/writing-a-service.md#making-it-linkable).
