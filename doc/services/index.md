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
| [`GoBuilder`](go.md#gobuilder) / [`RustBuilder`](rust.md#rustbuilder) | One compiler container for a repository, declaring the applications it builds |
| [`BinaryRunService`](rust.md#binaryrunservice) | Runs one compiled binary, whatever produced it |
| [`PostgresService`](databases.md#postgresservice) | PostgreSQL, with a `postgres:client` task |
| [`MySQLService`](databases.md#mysqlservice) | MySQL, with a `mysql:client` task |
| [`MariaDBService`](databases.md#mariadbservice) | MariaDB, with a `mariadb:client` task |
| [`ClickhouseService`](databases.md#clickhouseservice) | ClickHouse and its keeper |
| [`RedisService`](infrastructure.md#redisservice) | Redis and the RedisInsight UI |
| [`RabbitMQService`](infrastructure.md#rabbitmqservice) | RabbitMQ and its management UI |
| [`ElasticsearchService`](infrastructure.md#elasticsearchservice) | Elasticsearch and Kibana |
| [`MailpitService`](infrastructure.md#mailpitservice) | SMTP catch-all with a web UI |
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
        ->withDatabaseService($postgres)
);
```
