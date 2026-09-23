# Changelog

## Unreleased

### Added

* [`castor docker:tunnel:start`](tasks.md#castor-dockertunnelstart): public HTTPS URLs through a Cloudflare quick tunnel.
* [`link()`](services/index.md#linking-services): hands a service's connection variables to an application, for every language.
* [`MeilisearchService`](services/infrastructure.md#meilisearchservice), with its dashboard.
* [`MercureService`](services/mercure.md), served by FrankenPHP itself when possible.
* [`RustFSService`](services/object-storage.md), S3-compatible storage with buckets created on start.
* `docker:service:install` installs `meilisearch`, `mercure` and `rustfs`.
* `withHttpRouting()` can be called several times; `ServiceBuilder::entrypoint()`.
* `RabbitMQService::withVersion()`, `healthcheck()` takes a `startPeriod`.
* Healthchecks for ClickHouse, its keeper, RedisInsight and Kibana.
* [`castor docker:doctor`](tasks.md#castor-dockerdoctor) diagnoses the environment.
* [`{service}:dump` and `{service}:restore`](services/databases.md#dumping-and-restoring) on every database.
* [`worktree:create --copy-data`](going-further/worktrees.md#starting-from-the-data-of-a-checkout) copies the databases of the current checkout.

### Changed

* The router keeps the `X-Forwarded-*` headers of private addresses. Run `castor docker:router:restart`.
* The router is no longer [downgraded](services/router.md#keeping-it-up-to-date) by an older plugin; `docker:up` warns when it is outdated.
* Default versions: Redis 8.10, Elasticsearch/Kibana 9.5.3, RabbitMQ 4.3, MySQL 9.7.2, ClickHouse 26.8.
* Elasticsearch: security off, 512 MB heap, disk watermarks off.
* MySQL `DATABASE_URL` has `serverVersion` and `charset=utf8mb4`.
* RedisInsight image is `redis/redisinsight`.

### Fixed

* `DATABASE_URL` `serverVersion` follows `withVersion()`.
* RedisInsight connections were lost (wrong data volume).
* RabbitMQ queues were lost on recreate (random node name).
* Kibana connects to a renamed Elasticsearch.
* Postgres, MySQL and MariaDB healthchecks passed on the init server.

### Deprecated

* `withDatabaseService()` and `withMailerService()`: use `link()`.

### Upgrading

Pin the old version with `withVersion()`, or remove the volume (`castor docker:destroy`).

* Elasticsearch 7 → 9: data unreadable. Pin `7.8.0` or remove the volume.
* MySQL 8.0 → 9.7: run once with `withVersion('8.4')`, or pin `8.0.46`.
* RabbitMQ: starts an empty node. Drain messages first.
* RedisInsight: add your databases again.
* FrankenPHP linked to Mercure: run `castor docker:build`.

## 0.7.1 - 2026-09-22

### Fixed

* Commands in a container wrapped at 80 columns: the terminal size is now passed.

## 0.7.0 - 2026-09-21

### Added

* [Git worktrees](going-further/worktrees.md) get their own stack and domain, with no configuration.
* [`worktree:list`, `worktree:create`, `worktree:delete`](going-further/worktrees.md#managing-the-checkouts), and `--worktree` on every task.
* `docker:about` names the worktree and what it shares with the others.
* Worktrees [share the caches](going-further/worktrees.md#the-caches-are-shared) of the main checkout.
* [`docker:push`](tasks.md#publishing-to-ghcrio) labels images with their repository; `--tag` picks the tag.

### Fixed

* `<service>:expose` restored the forwarders of another project.
* `<service>:expose` says which container holds the port.

### Upgrading

* Run `castor <service>:expose` again for each exposed service.

## 0.6.0 - 2026-09-17

### Added

* [`docker:service:install`](getting-started/installing-services.md#installing-without-questions) takes every question as an option.
* `InputType::Choice` installer inputs take `multiple: true`.

### Changed

* `docker:push` lets `docker buildx bake` read the compose file.

### Fixed

* Postgres 18 data was lost on recreate (wrong volume path).

### Upgrading

* Postgres on its default version: dump the database before upgrading, the volume is empty.

## 0.5.2 - 2026-09-03

### Added

* [`addExtension()`](services/php.md#extensions-built-with-pie) builds extensions with PIE, and takes system packages.
* `withPieVersion()`.

## 0.5.1 - 2026-09-03

### Added

* [`castor docker:stats`](tasks.md#castor-dockerstats): CPU, memory and disk used by the project.
* [`NodeService`](services/node.md), a Node.js application, and its `node` installer.
* `NodeService::withPolling()`, when [nothing reloads](services/node.md#when-nothing-reloads).

### Changed

* `RedirectionioAgentService::addReverseProxy()` reads the port of a service instance.

## 0.5.0 - 2026-09-01

### Changed

* [`PhpMode::FrankenPhp`](services/php.md#runtime-modes) builds every stage on `dunglas/frankenphp`.
* Extensions are named after the installer of the mode.
* FrankenPHP containers start in `/var/www` with `HOME=/home/app`.
* Shipped Dockerfiles have no `# syntax=` line, see [pinning the frontend](going-further/custom-dockerfile.md#pinning-the-frontend).
* New builder blocks `builder_php_dev` and `builder_php_configuration`.

### Upgrading

* FrankenPHP applications rebuild on `dunglas/frankenphp` (ZTS PHP).
* Their extensions use install-php-extensions names: `mysql` is `mysqli` and `pdo_mysql`.

## 0.4.1 - 2026-09-01

### Fixed

* The `docker:push` bake file escapes what it embeds and writes no empty blocks.

## 0.4.0 - 2026-08-28

### Added

* [`PHPService::withPhpIni()`](services/php.md#php-configuration), per scope, without rebuild.
* [`docker_compose_exec()`](tasks.md#in-a-container-already-running).
* [`PHPService::withSudo()`](services/php.md#sudo-in-the-builder).
* [`PHPService::withPackageManager()` and `withNodeVersion()`](services/php.md#nodejs).

### Changed

* `docker_compose_run()` and `docker_exit_code()` take the command as a list.
* Default Node.js is 24, and npm the default package manager.
* The Node version is the `node_version` Twig variable.

### Fixed

* FrankenPHP applications and workers ignored `app-default.ini`.
* The builder builds on Node 25.

### Removed

* The unused `mods-available/app-builder.ini`.

### Upgrading

* Node 24: pin with `withNodeVersion('20')`.
* `yarn` is yarn 1 unless `packageManager` says otherwise.
* FrankenPHP reads `app-default.ini`: override it with `withPhpIni()`.
* `GoBuilder`/`RustBuilder` overrides of `getBuildCommand()`, `cargoCommand()`, `formatCommand()` return tokens.

## 0.3.5 - 2026-08-28

### Changed

* [QA tools](services/php.md#quality-assurance) are installed by the builder's composer.

### Removed

* The `castor-php/php-qa` dependency: require it yourself if you call it.

### Fixed

* `withMailerService()` generated an invalid compose file (#4, @HedicGuibert).
* `ServiceBuilder::dependsOn()` defaults to `service_started`.

## 0.3.4 - 2026-08-12

### Added

* `RedirectionioAgentService::withTestMode()` and `withLogging()`.
* The router [starts and stops with your projects](services/router.md#it-starts-and-stops-with-your-projects); `router_autostart` to turn it off.

### Changed

* `docker:router:status` shows the autostart and the projects served.

## 0.3.3 - 2026-08-11

### Added

* [`castor docker:about`](tasks.md#castor-dockerabout) lists the URLs of the project.

## 0.3.2 - 2026-08-10

### Fixed

* The router watches [the socket of the daemon in use](services/router.md#the-docker-socket-it-watches).

## 0.3.1 - 2026-08-10

### Fixed

* QA tasks pass no path when the tool has its own configuration file.

## 0.3.0 - 2026-08-07

### Added

* Containers [resolve the project's own domains](services/router.md#reaching-your-own-domains-from-inside-a-container).
* [`RustBuilder`](services/rust.md#rustbuilder) and [`GoBuilder`](services/go.md#gobuilder), one compiler container for several applications.
* [`BinaryRunService`](services/rust.md#binaryrunservice), running a compiled binary.
* `withWorkingDirectory()`, for [monorepos](going-further/multiple-applications.md#monorepos).
* [`PHPService::withSharedBuilder()`](services/php.md#sharing-one-builder-container) and `withoutBuilder()`.
* `RustService::withTarget()`, `withBinaryPath()`, `withBuildCommand()`, `withRunCommand()`.
* `ServiceBuilder`: `restart()`, `ulimits()`, `dns()`, `extraHost()`, `deploy()`.
* `environment`, `entrypoint` and `ports` on `docker_compose_run()` and `docker_exit_code()`.
* `withName()` on every service, to [run several instances](services/databases.md#several-instances-of-the-same-database).
* [MySQL and MariaDB server configuration](services/databases.md#configuring-the-mysql-and-mariadb-servers).
* `RedirectionioAgentService::withApiHost()`, `withApiTimeout()` and `withDebug()`.
* `recreateOnChange` on `ServiceBuilder::config()`.
* [`{app}:worker:restart` and `{app}:worker:stop`](going-further/workers.md#driving-them).
* [`castor docker:logs:clear`](tasks.md#castor-dockerlogsclear).
* [Shell completion](tasks.md#completing-a-service-name) on service, container, installer and worker names.
* [`{app}:update`](services/go.md#updating-the-dependencies) on `GoBuilder` applications.
* A [restart policy](going-further/workers.md#keeping-a-consumer-alive) on `addWorker()` and `BinaryRunService::withRestart()`.
* [`RustBuilder::withNightlyFormatter()`](services/rust.md#formatting-on-nightly).
* `get_default_profiles()` reads `docker_profiles`.
* Rust and Go Dockerfiles are extensible Twig templates.

### Fixed

* The redirection.io agent [preserves the `Host`](services/redirectionio.md#the-host-header-your-application-receives).
* `ClickhouseService` UI answered 502 half the time.
* Inline compose configs are no longer interpolated.

### Changed

* `ServiceBuilder::withHttpRouting()` requires the port.
* `{service}:bash` and database sessions work without a terminal.
* [QA tasks](services/php.md#quality-assurance) run in the builder container.
* `GoService` builds from a shipped Dockerfile.
* `GoService` and `RustService` are no longer `final`.
* `docker_exit_code()` forwards `portMapping`.
* `project_name` is no longer overwritten by `compose.yaml`.
* `docker_compose_run()` is quieter; `-v` for the compose output.
* Tasks of a named service are `{service}:{task}`: `db:psql` becomes `postgres:client`.

## 0.2.1 - 2026-07-27

### Added

* [`DockerComposeBuilderEvent`, `DockerComposeWriteEvent` and `#[AsDockerComposeBuilder]`](going-further/extending-the-compose-file.md).

### Changed

* Tasks are registered through `FunctionsResolvedEvent`.
* `castor list` no longer regenerates `compose.generated.yaml`.
* The compose project name is read from `compose.yaml`.

## 0.2.0 - 2026-07-27

### Changed

* The [Caddy router](services/router.md) is global, shared by every project.

### Added

* `docker:router:status`, `docker:router:logs` and `docker:router:restart`.

### Removed

* `CaddyRouterService` and the `router` profile.
* `router:enable`/`router:disable`, renamed `docker:router:enable`/`docker:router:disable`.

### Upgrading

* Run `castor docker:up` then `castor docker:router:enable`.

## 0.1.3 - 2026-07-27

### Fixed

* Re-enabling the router failed on read-only certificates.
* Databases use complete versions.

## 0.1.2 - 2026-07-25

### Fixed

* Bind-mounted host directories are created before docker creates them as `root`.

## 0.1.1 - 2026-07-25

### Fixed

* `compose.yaml` is created without an `#[AsContext]` function.
* `castor list` dropped the project configuration.

## 0.1.0 - 2026-07-25

First release. See the [documentation](index.md).
