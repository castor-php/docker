---
title: Cache, queue and search
description: Redis, RabbitMQ, Elasticsearch and Mailpit services.
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

## MailpitService

An SMTP server that catches every mail your application sends and shows it in a
web UI. Hand it to an application with `->withMailerService()` and the
application gets a `MAILER_DSN` pointing at it:

```php
$mailpit = new MailpitService();
$event->addService($mailpit);

$event->addService(
    (new SymfonyService('app'))->withDirectory(__DIR__)->withMailerService($mailpit)
);
```

```php
(new MailpitService())
    ->withVersion('latest')         // Mailpit version (default: latest)
```

* **Containers:** `mailpit`
* **UI:** `https://mailpit.{root_domain}` when the router is enabled
