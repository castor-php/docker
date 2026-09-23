---
title: Databases
description: PostgreSQL, MySQL, MariaDB and ClickHouse services.
---

# Databases

Every database service implements `DatabaseServiceInterface`, so it can be
[linked](index.md#linking-services) to an application. The application then
gets a `DATABASE_URL` environment variable and waits for the database to be
healthy before starting:

```php
$postgres = new PostgresService();
$event->addService($postgres);

$event->addService(
    (new SymfonyService('app'))->withDirectory(__DIR__)->link($postgres)
);
```

Each of them also exposes a `{name}:expose` task to reach the server from the
host with a native client — see [tasks](../tasks.md#exposing-a-service-over-tcp).

They also come with a `{name}:dump` and a `{name}:restore` task, to take the
data out of the database and to put some back in — see
[dumping and restoring](#dumping-and-restoring).

`serverVersion` follows `withVersion()` (`16-alpine` gives `16`). It is omitted
for a tag without a version (`latest`), or an incomplete MariaDB one (`11.8`),
which Doctrine rejects: Doctrine then asks the server.

## PostgresService

```php
(new PostgresService())
    ->withVersion('18.4')           // PostgreSQL version (default: 18.4)
```

* **Tasks:** `castor postgres:client` — a psql session; `postgres:dump` and
  `postgres:restore`
* **Containers:** `postgres`, named volume `postgres_data`
* **Database URL:** `postgresql://app:app@postgres:5432/app?serverVersion=18.4&charset=utf8`

## MySQLService

```php
(new MySQLService())
    ->withVersion('9.7.2')          // MySQL version (default: 9.7.2)
    ->withRootPassword('root')      // Root password (default: root)
    ->withDatabase('app')           // Database name (default: app)
```

* **Configuration:** [`withSetting()` and friends](#configuring-the-mysql-and-mariadb-servers)
* **Tasks:** `castor mysql:client` — a mysql session; `mysql:dump` and
  `mysql:restore`
* **Containers:** `mysql`, named volume `mysql-data`
* **Database URL:** `mysql://root:root@mysql:3306/app?serverVersion=9.7.2&charset=utf8mb4`

## MariaDBService

```php
(new MariaDBService())
    ->withVersion('12.3.2')         // MariaDB version (default: 12.3.2)
    ->withRootPassword('root')      // Root password (default: root)
    ->withDatabase('app')           // Database name (default: app)
```

* **Configuration:** [`withSetting()` and friends](#configuring-the-mysql-and-mariadb-servers)
* **Tasks:** `castor mariadb:client` — a mariadb session; `mariadb:dump` and
  `mariadb:restore`
* **Containers:** `mariadb`, named volume `mariadb-data`
* **Database URL:** `mysql://root:root@mariadb:3306/app?serverVersion=mariadb-12.3.2&charset=utf8mb4`

## ClickhouseService

```php
(new ClickhouseService())
    ->withVersion('26.8')           // ClickHouse version (default: 26.8)
    ->withDatabase('app')           // Database name (default: app)
    ->withCredentials('app', 'app') // User and password (default: app / app)
    ->withBackup()                  // Install Altinity clickhouse-backup in the image
```

* **Tasks:** `castor clickhouse:client` — a clickhouse-client session;
  `clickhouse:dump` and `clickhouse:restore`
* **Containers:** `clickhouse` and `clickhouse-keeper`, named volume `clickhouse-data`
* **UI:** `https://clickhouse.{root_domain}` when the router is enabled

> [!NOTE]
> ClickHouse is not a `DatabaseServiceInterface`: it is meant to sit next to your
> main database rather than to back `DATABASE_URL`.

## Dumping and restoring

Every database service can write its content to a file, and replace it with the
content of one:

```console
$ castor postgres:dump prod.sql.zst            # a compressed SQL dump
$ castor postgres:restore ~/Downloads/prod.dump
$ castor mysql:dump | ssh staging mysql app    # to the standard output
$ curl -s https://example.com/fixtures.sql.gz | castor mysql:restore
```

### Writing a dump

The name of the file says what to write: `.sql` for plain SQL, followed by
`.gz`, `.zst` or `.xz` to compress it. Postgres also writes the custom format of
`pg_dump` in a `.dump`, compressed already and restored in parallel — the one to
pick for a large database. With no file, or `-`, the plain SQL goes to the
standard output, so it can be piped anywhere; the messages of the task go to the
error output, and never end up in the dump.

The dump is meant to be restored somewhere else. It is consistent without
locking the tables, and it names nothing that only exists on this server: no
owner and no privilege for Postgres, no tablespace and no GTID for MySQL.

### Restoring one

A dump is read from a file or from the standard input, whatever produced it:
this plugin, a colleague, a backup of the production. Its compression — gzip,
zstd, xz, bzip2 when the image has it — is told from its first bytes rather
than from its name, so a gzipped `prod.sql` or a dump piped in with no name at
all is read the same way. Postgres goes on to recognise its own archives, the
custom format and the tar one, and hands them to `pg_restore` rather than to
`psql`.

The content of the database is replaced, not merged: the dump lands in a
database that starts empty.

Postgres restores into a scratch database first, and swaps it with the current
one once the dump is fully loaded. A dump that fails half-way — truncated by a
download, or written for another schema — leaves the database as it was, and
the swap itself takes an instant. It does need the room for both databases
while it runs.

The archives are restored without their owners and privileges, which name
roles that do not exist here. A plain SQL dump cannot be told to skip them, so
`psql` carries on past an error, the way it always does, and the task tells how
many statements failed. Most often they are the `OWNER TO` of a production
role, and they do no harm.

MySQL and MariaDB have no way to rename a database, so the dump is restored in
place, and a dump that fails leaves the database half restored. The dumps of a
recent MariaDB open with a line only MariaDB understands, which is dropped on
the way into MySQL — but a MariaDB schema using what MySQL does not have, such
as the `utf8mb4_uca1400_ai_ci` collation of MariaDB 11, still fails there.

### What uses the database meanwhile

A restore stops every running container that depends on the database — the
applications given it with `link()`, their workers, and any
service of your own declaring a `depends_on` — and starts them again once it is
done.

Left running, they would keep connections open on a database being dropped,
which Postgres refuses, or read one half restored. And a worker losing its
connection exits: without a restart policy, which is the default, it stays down
until the next `docker:up`. The connections still open when the database goes —
a client of yours, on an [exposed port](../tasks.md#exposing-a-service-over-tcp)
— are closed.

### Where it runs

Both tasks run the tools of the server's own image — `pg_dump`, `mysqldump`,
`mariadb-dump` — in a throwaway container, so they are at the version of the
server and nothing has to be installed on your machine. The container mounts
the directory of the file rather than streaming it through docker, which would
be several times slower, and hands the file it writes back to you.

A database that is not running is started for the task. A dump puts it back the
way it was found; a dump to the standard output refuses instead, since what
starting it prints would end up in the dump.

### ClickHouse

ClickHouse writes no SQL dump holding a whole database: `clickhouse:dump` writes
a `.zip` archive with `BACKUP DATABASE`, and `clickhouse:restore` reads one. The
archive holds the data parts themselves, so restoring is a copy rather than a
replay of inserts, and it takes the replicated tables along with their keeper.

The archive has to be one of a database named like the one of the service —
`app` unless `withDatabase()` says otherwise. `withBackup()` has nothing to do
with it: it installs Altinity's `clickhouse-backup`, for backups to a remote
storage.

## Configuring the MySQL and MariaDB servers

Both read every `*.cnf` of `/etc/mysql/conf.d` on top of their built-in
defaults, and the plugin ships one file there:

```php
(new MySQLService())
    ->withSetting('max_connections', 500)
    ->withSetting('innodb_buffer_pool_size', '1G')
    ->withSetting('slow_query_log', true)          // ON / OFF for a boolean
    ->withSetting('skip-name-resolve')             // a flag that takes no value
    ->withSettings(['sort_buffer_size' => '4M'])   // several at once
```

which generates:

```ini
# This file is generated by Castor. Do not edit it manually.
[mysqld]
max_connections = 500
innodb_buffer_pool_size = 1G
slow_query_log = ON
skip-name-resolve
sort_buffer_size = 4M
```

For what `withSetting()` cannot express — another section, a comment you want to
keep — append a raw block, or a file of your project:

```php
->withConfiguration("[client]\ndefault-character-set = utf8mb4")
->withConfigurationFile(__DIR__ . '/docker/my.cnf')
```

All three merge into the same file, settings first, in the order you declared
them.

The result is shipped as a **compose config**, not as a bind mount: the content
ends up in `compose.generated.yaml`, so there is no host directory for Docker to
create as `root`, and no file whose permissions the server might refuse — MySQL
ignores a world-writable `.cnf`.

The server reads it once, at boot, and compose does not recreate a container
when only the content of a config changed. The plugin stamps a digest of it in a
`castor.config.{name}-config` label, so `castor docker:up` picks a change up on
its own — no `--force-recreate`.

`withConfigurationFile()` reads the file when the compose file is generated, and
raises if the path does not exist. A mistyped path is reported there and then,
rather than leaving the server silently unconfigured. It also means editing that
file takes effect on the next Castor run, followed by a restart of the
container.

## Several instances of the same database

`withName()` overrides the name a service gives itself, which is what lets you
register it twice:

```php
$main = new PostgresService();
$analytics = (new PostgresService())->withName('analytics');

$event->addService($main);
$event->addService($analytics);

$event->addService((new SymfonyService('app'))->link($analytics));
```

The container, the named volume (`analytics_data`), the connection string
(`postgresql://app:app@analytics:5432/app`) and the expose task
(`castor analytics:expose`) all follow the name.

Every task of a service is named `{service}:{task}`, so the two instances get two
full task sets rather than fighting over one: `castor postgres:client` and
`castor analytics:client`, `castor postgres:expose` and
`castor analytics:expose`.
