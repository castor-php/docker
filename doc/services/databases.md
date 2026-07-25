---
title: Databases
description: PostgreSQL, MySQL, MariaDB and ClickHouse services.
---

# Databases

Every database service implements `DatabaseServiceInterface`, so it can be
handed to an application with `->withDatabaseService()`. The application then
gets a `DATABASE_URL` environment variable and waits for the database to be
healthy before starting:

```php
$postgres = new PostgresService();
$event->addService($postgres);

$event->addService(
    (new SymfonyService('app'))->withDirectory(__DIR__)->withDatabaseService($postgres)
);
```

Each of them also exposes a `{name}:expose` task to reach the server from the
host with a native client — see [tasks](../tasks.md#exposing-a-service-over-tcp).

## PostgresService

```php
(new PostgresService())
    ->withVersion('16')             // PostgreSQL version (default: 16)
```

* **Task:** `castor db:psql` — a psql session
* **Containers:** `postgres`, named volume `postgres_data`
* **Database URL:** `postgresql://app:app@postgres:5432/app?serverVersion=16&charset=utf8`

## MySQLService

```php
(new MySQLService())
    ->withVersion('8')              // MySQL version (default: 8)
    ->withRootPassword('root')      // Root password (default: root)
    ->withDatabase('app')           // Database name (default: app)
```

* **Task:** `castor db:mysql` — a mysql session
* **Containers:** `mysql`, named volume `mysql-data`
* **Database URL:** `mysql://root:root@mysql:3306/app`

## MariaDBService

```php
(new MariaDBService())
    ->withVersion('12.1')           // MariaDB version (default: 12.1)
    ->withRootPassword('root')      // Root password (default: root)
    ->withDatabase('app')           // Database name (default: app)
```

* **Task:** `castor db:mariadb` — a mariadb session
* **Containers:** `mariadb`, named volume `mariadb-data`
* **Database URL:** `mysql://root:root@mariadb:3306/app?serverVersion=mariadb-12.1&charset=utf8mb4`

## ClickhouseService

```php
(new ClickhouseService())
    ->withVersion('25.8')           // ClickHouse version (default: 25.8)
    ->withDatabase('app')           // Database name (default: app)
    ->withCredentials('app', 'app') // User and password (default: app / app)
    ->withBackup()                  // Install Altinity clickhouse-backup in the image
```

* **Task:** `castor db:clickhouse` — a clickhouse-client session
* **Containers:** `clickhouse` and `clickhouse-keeper`, named volume `clickhouse-data`
* **UI:** `https://clickhouse.{root_domain}` when the router is enabled

> [!NOTE]
> ClickHouse is not a `DatabaseServiceInterface`: it is meant to sit next to your
> main database rather than to back `DATABASE_URL`.
