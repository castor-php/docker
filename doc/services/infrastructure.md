---
title: Cache, queue and search
description: Redis, RabbitMQ, Elasticsearch, Meilisearch and Mailpit services.
---

# Cache, queue and search

## RedisService

Redis with the RedisInsight web UI.

```php
(new RedisService())
    ->withVersion('5')              // Redis version (default: 5)
```

* **Containers:** `redis`, `redis-insight`, named volumes `redis-data` and
  `redis-insight-data`
* **UI:** `https://redis.{root_domain}` when the router is enabled
* **Task:** `castor redis:expose` — reach Redis from the host

## RabbitMQService

RabbitMQ with the management plugin.

```php
new RabbitMQService()
```

* **Containers:** `rabbitmq`, named volume `rabbitmq-data`
* **UI:** `https://rabbitmq.{root_domain}` when the router is enabled
* **Task:** `castor rabbitmq:expose` — reach AMQP (5672) from the host

## ElasticsearchService

Elasticsearch with Kibana.

```php
(new ElasticsearchService())
    ->withVersion('7.8.0')          // Elasticsearch version (default: 7.8.0)
```

* **Containers:** `elasticsearch`, `kibana`, named volume `elasticsearch-data`
* **UI:** `https://elasticsearch.{root_domain}` and `https://kibana.{root_domain}`
  when the router is enabled
* **Task:** `castor elasticsearch:expose` — reach the HTTP API from the host

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
