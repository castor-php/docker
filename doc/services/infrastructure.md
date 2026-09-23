---
title: Cache, queue and search
description: Redis, RabbitMQ, Elasticsearch, Meilisearch and Mailpit services.
---

# Cache, queue and search

## RedisService

Redis with the RedisInsight web UI.

```php
(new RedisService())
    ->withVersion('8.10')           // Redis version (default: 8.10)
```

* **Containers:** `redis`, `redis-insight`, named volumes `redis-data` and
  `redis-insight-data`
* **UI:** `https://redis.{root_domain}` when the router is enabled
* **Task:** `castor redis:expose` — reach Redis from the host

In RedisInsight, add the database `redis:6379`; it is kept in its volume.

## RabbitMQService

RabbitMQ with the management plugin.

```php
(new RabbitMQService())
    ->withVersion('4.3')            // RabbitMQ version (default: 4.3)
```

* **Containers:** `rabbitmq`, named volume `rabbitmq-data`
* **UI:** `https://rabbitmq.{root_domain}` when the router is enabled, as
  `guest` / `guest`
* **Task:** `castor rabbitmq:expose` — reach AMQP (5672) from the host

The image is `rabbitmq:{version}-management-alpine`. The node is always
`rabbit@localhost`, so its data survives a recreate.

## ElasticsearchService

Elasticsearch with Kibana.

```php
(new ElasticsearchService())
    ->withVersion('9.5.3')          // Elasticsearch and Kibana version (default: 9.5.3)
```

* **Containers:** `elasticsearch`, `kibana`, named volume `elasticsearch-data`
* **UI:** `https://elasticsearch.{root_domain}` and `https://kibana.{root_domain}`
  when the router is enabled
* **URL:** `http://elasticsearch:9200` from the other containers
* **Task:** `castor elasticsearch:expose` — reach the HTTP API from the host

Development settings: security off (plain HTTP, no credentials), 512 MB heap
(raise it through
[`ES_JAVA_OPTS`](../going-further/extending-the-compose-file.md#dockercomposebuilderevent)),
disk watermarks off.

## MeilisearchService

Meilisearch, with the search preview dashboard it serves on its own URL in
development mode. Link it to an application to hand it the URL and the key:

```php
$meilisearch = new MeilisearchService();
$event->addService($meilisearch);

$event->addService(
    (new SymfonyService('app'))->withDirectory(__DIR__)->link($meilisearch)
);
```

```php
(new MeilisearchService())
    ->withVersion('v1.54')          // Image tag (default: v1.54)
    ->withMasterKey('…')            // default: castor-meilisearch-master-key
```

The ecosystem does not agree on a name, so the application gets both
conventions — the one of the Symfony bundle, and the one of Laravel Scout:

| Variable | Value | Read by |
|----------|-------|---------|
| `MEILISEARCH_URL` | `http://meilisearch:7700` | `meilisearch/search-bundle`, the Symfony AI store |
| `MEILISEARCH_API_KEY` | the master key | same |
| `MEILISEARCH_HOST` | `http://meilisearch:7700` | Laravel Scout |
| `MEILISEARCH_KEY` | the master key | same |
| `MEILISEARCH_PUBLIC_URL` | `https://meilisearch.{root_domain}` | a search running in the browser |

The URL the application uses is the one of the project network: the containers
do not trust the certificates of the router, the browser does. `SCOUT_DRIVER` is
left to the application, it is a choice of its own.

The tag is a minor, which Meilisearch publishes as a floating tag, so patches
come with the next pull. Meilisearch refuses to open a database written by
another version — even one patch apart — so the service runs with
`MEILI_UPGRADE_DB` on: the data is migrated instead of the server staying down
until the volume is dropped. There is no way back, though: a database opened by
a newer version stays on it.

The dashboard asks for a key, kept in the tab only: paste the master key.

* **Containers:** `meilisearch`, named volume `meilisearch-data`
* **UI:** `https://meilisearch.{root_domain}` when the router is enabled
* **Task:** `castor meilisearch:expose` — reach the HTTP API from the host

## MailpitService

An SMTP server that catches every mail your application sends and shows it in a
web UI. [Link](index.md#linking-services) it to an application and the
application gets a `MAILER_DSN` pointing at it:

```php
$mailpit = new MailpitService();
$event->addService($mailpit);

$event->addService(
    (new SymfonyService('app'))->withDirectory(__DIR__)->link($mailpit)
);
```

```php
(new MailpitService())
    ->withVersion('latest')         // Mailpit version (default: latest)
```

* **Containers:** `mailpit`
* **UI:** `https://mailpit.{root_domain}` when the router is enabled
