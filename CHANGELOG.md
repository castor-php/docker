# Changelog

## Unreleased

### Changed

* `PhpMode::FrankenPhp` runs one PHP for the whole application: every stage is
  built on `dunglas/frankenphp`, so the builder and the workers run the binary
  that serves. `PhpMode::Fpm` stays on the Debian packages.
* Extensions keep their Debian names; those whose package ships several modules
  are translated for install-php-extensions — `pgsql` installs pdo_pgsql too,
  `mysql` mysqli and pdo_mysql, `sqlite3` pdo_sqlite.
* `withPhpIni(..., PhpIniScope::Cli)` writes to `/usr/local/etc/php/conf.d` in
  FrankenPHP mode.
* A FrankenPHP application container starts in `/var/www` with `HOME=/home/app`,
  instead of the `/app` of the upstream image.
* No shipped Dockerfile carries a `# syntax=` line, which kept them from being
  extended. The frontend is pinned by the `BUILDKIT_SYNTAX` build argument every
  generated service passes.
* Two new blocks in the builder stage, `builder_php_dev` and
  `builder_php_configuration`. The NodeSource key is used armoured, so the
  builder installs no gnupg.

## 0.4.1 - 2026-09-01

### Fixed

* The bake file `docker:push` generates escapes what it embeds: quotes,
  backslashes, newlines, tabs, and the `${` and `%{` HCL template markers, which
  were evaluated rather than taken literally.
* That file no longer writes empty `contexts`, `args` or `target` blocks —
  `target = ""` asked bake for a stage named the empty string.

## 0.4.0 - 2026-08-28

### Added

* `PHPService::withPhpIni()`, PHP ini directives per application, scoped to the
  PHP running commands (`PhpIniScope::Cli`), the one serving requests (`Web`) or
  both. Mounted rather than built into the image, so a change costs a
  `docker:up` and not a rebuild, and is read after the defaults.
* `docker_compose_exec()`, running a command in the container a service already
  has up. Same command forms, `workDir` and `environment` as
  `docker_compose_run()`, plus `privileged`.
* `PHPService::withSudo()`, installing the passwordless sudo the Dockerfile
  carried commented out. Off by default, and the gosu binary now follows the
  architecture being built rather than naming amd64.
* `PHPService::withPackageManager()`, choosing `Npm`, `Yarn` or `Pnpm`. Corepack
  stays enabled, so a `packageManager` field in a package.json still wins.
* `PHPService::withNodeVersion()`, the Node of the builder container. Only the
  major is kept, and a version naming none is rejected when the service is
  declared. An application sharing a builder gets that one's version.

### Changed

* `docker_compose_run()` and `docker_exit_code()` take the command as a list of
  tokens, which reach docker untouched. A command given as a string still runs
  through a shell.
* The default Node.js is 24, up from 20. `withNodeVersion('20')` to stay on it.
* The builder no longer pins yarn: the default is npm. Declare a
  `packageManager` field, or pass `PackageManager::Yarn`.
* The Node version is the `node_version` Twig variable of the Dockerfile, not a
  `NODEJS_VERSION` build argument.

### Fixed

* An application served by FrankenPHP reads `app-default.ini`, which it never
  did: its memory limit, error reporting, timezone and opcache settings were the
  bare PHP defaults.
* Workers read it too, which is where their `memory_limit = -1` came from.
* The builder image builds on Node 25, which dropped corepack from the
  distribution: it is installed from npm when the version does not ship it.

### Removed

* An unused copy of `mods-available/app-builder.ini` at the root of the PHP
  build context, which nothing referenced.

### Documentation

* The quality assurance page no longer says composer resolves the tools against
  the PHP running castor; it stopped in 0.3.5.

### Upgrading

Four things change under a project that did nothing:

* The images rebuild on Node 24. Pin the old one with `withNodeVersion('20')`.
* `yarn` is corepack's default, yarn 1, unless a `packageManager` field or
  `PackageManager::Yarn` says otherwise.
* FrankenPHP applications and workers read `app-default.ini`: 512M memory limit,
  `display_errors` on, UTC, opcache sized. `withPhpIni()` overrides it.
* A subclass of `GoBuilder` or `RustBuilder` overriding `getBuildCommand()`,
  `cargoCommand()` or `formatCommand()` has to widen its return type: they
  return tokens now, and `getBuildCommand()` is `string|array`.

## 0.3.5 - 2026-08-28

### Changed

* The QA tools are installed by the composer of the builder image rather than
  the one castor embeds, so versions and extensions are resolved against the
  container the tools run in. Installations stay in `.castor/vendor/.tools/`,
  and are redone when the PHP version of the application changes.

### Removed

* The `castor-php/php-qa` dependency. Projects calling its functions —
  `phpstan()`, `php_cs_fixer()` — should require it themselves.

### Fixed

* `withMailerService()` generated a compose file docker refuses to load:
  `services.app.depends_on.mailpit missing property 'condition'`, which stopped
  every task of the project. Reported by @HedicGuibert in #4.
* `ServiceBuilder::dependsOn()` defaults that condition to `service_started`.

## 0.3.4 - 2026-08-12

### Added

* `RedirectionioAgentService::withTestMode()` and `withLogging()`, writing the
  `test_mode` and `logging` keys of the agent. Neither unless asked for.
* The global router starts with `docker:up` and stops with `docker:stop` and
  `docker:destroy`, once no routed container is left running on the machine. A
  project routing no domain does neither.
* The `router_autostart` context variable and the
  `CASTOR_DOCKER_ROUTER_AUTOSTART` environment variable turn that off; the
  environment wins.

### Changed

* `docker:router:status` reports whether the autostart is on, and the projects
  the router serves.

## 0.3.3 - 2026-08-11

### Added

* `castor docker:about` (alias `castor about`), listing every URL the project
  answers on, the service serving it and whether that service runs. Read from
  the three compose files; only the statuses need a daemon.

## 0.3.2 - 2026-08-10

### Fixed

* The router watches the socket of the daemon the projects run on —
  `DOCKER_SOCKET_PATH` first, then a `unix://` `DOCKER_HOST` — rather than always
  `/var/run/docker.sock`. Watching the wrong one was silent: no label, no route,
  "connection refused" on 443. `docker:router:enable` warns when the socket it
  resolved does not exist.

## 0.3.1 - 2026-08-10

### Fixed

* `{app}:qa:phpstan`, `{app}:qa:cs` and `{app}:qa:rector` pass no path when the
  application holds a configuration file the tool discovers itself
  (`phpstan.neon` & co, `.php-cs-fixer.php`, `.php-cs-fixer.dist.php`,
  `rector.php`). A path on the command line *replaces* the configured ones
  rather than restricting them, so an application was analysed whole, `vendor/`
  included. Arguments given to the task still win.

## 0.3.0 - 2026-08-07

### Added

* The containers of a project resolve its own public domains: one `extra_hosts`
  entry per routed domain, pointing at the host gateway, plus network aliases.
  Off with the `resolve_domains_via_host` context data.
* `RustBuilder` and `GoBuilder`, one compiler container per repository declaring
  the applications it compiles with `withApp()`. Each gets its own tasks —
  `<app>:build`, `<app>:test`, `<app>:cargo` / `<app>:go`, `<app>:qa:clippy`,
  `<app>:qa:fmt` — all running in that container.
* `BinaryRunService`, running one compiled binary and nothing else.
  `withBuilder()` gives it the image it was compiled in, the same mount, and the
  `build` and `watch` tasks.
* `withWorkingDirectory()` on every service mounting a directory:
  `withDirectory()` mounts, `withWorkingDirectory()` names the sub-directory,
  `withBinaryPath()` locates the binary. The PHP document root follows, through
  the new `app_root` build argument.
* `PHPService::withSharedBuilder()` and `withoutBuilder()`, so several
  applications of one repository stop generating identical `-builder` containers.
* `RustService::withTarget()`, which also moves the default binary path to
  `target/<triple>/debug/<name>`, plus `withBinaryPath()`, `withBuildCommand()`
  and `withRunCommand()` on `RustService` and `GoService`.
* Missing compose keys on `ServiceBuilder`: `restart()`, `ulimits()`, `dns()`,
  `extraHost()` and `deploy()`. `environment()` takes `null`, which emits
  `KEY: null`.
* `environment`, `entrypoint` and `ports` on `docker_compose_run()` and
  `docker_exit_code()`. A failing command raises a `RuntimeException` naming the
  service and the command.
* `withName()` on every service that hardcoded its name, so the same one can be
  registered twice. The compose service, the volumes, the domain, the companion
  containers, the DSN host and the task namespace all follow it.
* Server configuration for `MySQLService` and `MariaDBService`, with
  `withSetting()`, `withSettings()`, `withConfiguration()` and
  `withConfigurationFile()`, merged into one compose config in
  `/etc/mysql/conf.d`. A missing configuration file raises.
* `RedirectionioAgentService::withApiHost()` and `withApiTimeout()`, writing the
  `api` section of the generated `agent.yml`.
* `recreateOnChange` on `ServiceBuilder::config()`, which stamps a digest of the
  content in a label: compose does not recreate a container when only an inline
  config changed.
* `castor {app}:worker:restart` and `{app}:worker:stop`, on a named worker or on
  all of them; an unknown name is rejected with the list of those declared.
* `castor docker:logs:clear [service]`, truncating the container log files in
  place. Stopped containers and inactive profiles are covered; a `--privileged`
  helper reaches the file when it cannot be written directly.
* Shell completion on every argument naming a container, a service, an installer
  or a worker. Read from the compose files, so no daemon is needed.
* `castor {app}:update` on a `GoBuilder` application: `go get -u ./...`, then
  `go mod tidy` unless `--no-tidy`. Takes a module name, and `--patch` to stay
  inside the minor.
* A restart policy as third argument of `addWorker()`. A consumer reaching its
  `--time-limit` exits successfully, so it wants `unless-stopped` rather than
  `on-failure`. None unless asked for.
* `BinaryRunService::withRestart()`, whose argument defaults to `on-failure`.
  None unless called.
* `RustBuilder::withNightlyFormatter()`, installing the nightly toolchain and
  pointing the `fmt` task at it while everything else stays on stable — most of
  rustfmt's options being unstable.
* `RedirectionioAgentService::withDebug()`, raising the log level and letting the
  agent accept a certificate it cannot verify when calling a self-hosted API.
* `get_default_profiles()` reads the `docker_profiles` context data.
* The Rust and Go Dockerfiles are Twig templates, with `rust_base` / `go_base`
  and `runtime` blocks, extensible like the PHP ones.

### Fixed

* The applications behind `RedirectionioAgentService` receive the `Host` of the
  original request: the agent replaced it with the compose service name, which
  Symfony rejects as untrusted. `preserve_host` is written on every forward;
  `withPreserveHost(false)` restores the agent's behaviour.
* `ClickhouseService` routes its UI to 8123. Without a port Caddy picked 9000
  about half the time, answering 502.
* The content of an inline compose config is escaped against interpolation,
  which stripped an nginx configuration of every `$host` and `$uri`.
  `ComposeBuilder::config()` takes `interpolate: true` to opt back in.

### Changed

* `ServiceBuilder::withHttpRouting()` requires the port. A bare `{{upstreams}}`
  routed to whatever the image exposed first, silently, answering 502. Pass it
  in your own calls.
* `{service}:bash` and the database sessions no longer fail without a terminal:
  the interactive flags are only asked for when they can be honoured.
* The QA tasks run in the builder container, on the PHP and the extensions of
  the application. Each application gets its own tool installation
  (`app-phpstan`), and the tasks return the exit code instead of a `Process`.
* `GoService` builds from a Dockerfile shipped by the plugin, so it can be
  extended and its cache pushed. Tasks and runtime behaviour are unchanged.
* `GoService` and `RustService` are no longer `final`, their properties are
  `protected`, and `getTasks()` is split into one method per task.
* `docker_exit_code()` forwards `portMapping`, which it silently dropped.
* The `project_name` context data is no longer overwritten by the `name:` of
  `compose.yaml`, and `COMPOSE_PROJECT_NAME` is exported so compose uses the
  same project name as the plugin.
* `docker_compose_run()` no longer prints the two compose lines about the
  throwaway container it creates; `-v` brings them back, and `docker_compose()`
  takes a `progress` argument.
* The tasks of a named service are `{service}:{task}`: `db:psql` becomes
  `postgres:client`, `db:mysql` `mysql:client`, `db:mariadb` `mariadb:client`
  and `db:clickhouse` `clickhouse:client`.

### Documentation

* [Multiple applications](https://castor-php.github.io/docker/going-further/multiple-applications/)
  covers the monorepo shape, and `example/` is one.

## 0.2.1 - 2026-07-27

### Added

* `DockerComposeBuilderEvent`, dispatched with the `ComposeBuilder` once every
  service has contributed and before the file is serialized.
* `DockerComposeWriteEvent`, dispatched with the configuration as an array right
  before it is written: the escape hatch for the compose keys the builder does
  not model.
* `#[AsDockerComposeBuilder]`, sugar over the first event, with a `priority`.
* Documentation for the three, in [extending the compose
  file](https://castor-php.github.io/docker/going-further/extending-the-compose-file/),
  with an example of each in `example/`.

### Changed

* The plugin no longer builds its task commands from castor's internal API:
  tasks are handed over as `TaskDescriptor` through `FunctionsResolvedEvent`,
  leaving the event dispatcher as the only internal API in use.
* `castor list` no longer regenerates `compose.generated.yaml` from the bare
  context castor boots it on.
* The compose project name is read from the `name` of `compose.yaml` rather than
  from the context data.

### Documentation

* More detail on the Dockerfile blocks and on the PHP service.

## 0.2.0 - 2026-07-27

### Changed

* The Caddy router is global: one instance in `~/.castor/docker/router/`, shared
  by every project, instead of a `router` service per project. Ports 80 and 443
  are bound once and the router survives project restarts.
* It joins the network of each project on `docker:up` and leaves it before
  `docker:down`, so projects keep their own network and never collide in the
  Docker DNS.
* It runs the upstream `caddy-docker-proxy` image and receives its Caddyfile as
  a compose config, so enabling it no longer depends on a project's `vendor/`.
* The mkcert CA lives in `~/.castor/docker/router/certs/`, which the global
  router can read.

### Added

* `docker:router:status`, `docker:router:logs` and `docker:router:restart`.
* `docker:router:enable` joins the networks of the projects already running.

### Removed

* `CaddyRouterService` and the `router` compose profile.
* `router:enable` and `router:disable`, renamed `docker:router:enable` and
  `docker:router:disable`.

### Upgrading

The per-project router of a previous version may still hold ports 80 and 443.
It is no longer in the generated compose file, so `docker:up` removes it as an
orphan — run it before enabling the global one:

```bash
castor docker:up                # drops the old per-project router container
castor docker:router:enable     # starts the global one
```

Should a container still hold those ports, remove it with `docker rm -f <name>`.

## 0.1.3 - 2026-07-27

### Fixed

* Remove the router certificates before writing them: they may be read-only,
  which fails the copy when the router is re-enabled.
* Set complete versions for the databases, some dependencies expecting one.

## 0.1.2 - 2026-07-25

### Fixed

* Create the host directories bind-mounted by the services before docker does —
  it creates a missing one as `root`, leaving the containers unable to write in
  it. One left from an earlier run and not writable is reported instead.

## 0.1.1 - 2026-07-25

### Fixed

* Create `compose.yaml` on a project declaring no `#[AsContext]` function:
  castor dispatches `ContextCreatedEvent` only for a declared context, so a
  fresh project never got its compose file and every `docker:*` task failed.
* Stop `castor list` from regenerating `compose.generated.yaml` from a bare
  context, which dropped the project configuration.

## 0.1.0 - 2026-07-25

First release.

### Services

* PHP and Symfony applications, served by FrankenPHP or nginx + PHP-FPM, with a
  builder container, background workers, FrankenPHP worker mode and QA tasks
  (PHPStan, PHP CS Fixer, Rector, Twig CS Fixer)
* Go and Rust applications, built and run from the mounted sources, with a watch
  task rebuilding on change
* PostgreSQL, MySQL, MariaDB and ClickHouse, linkable to an application with
  `withDatabaseService()`
* Redis, RabbitMQ, Elasticsearch and Mailpit
* redirection.io agent (v3), running as a reverse proxy in front of the
  applications
* Caddy router, building its routes from the Docker labels of the services and
  serving HTTPS with on-demand, locally-trusted certificates

### Tasks

* `docker:build`, `docker:up`, `docker:stop`, `docker:logs`, `docker:ps`,
  `docker:destroy` and `docker:push`
* `docker:service:install` and `docker:service:remove`, registering a service in
  your `castor.php` with a format-preserving AST rewrite
* `{service}:expose`, forwarding a TCP service to the host and remembering it
  across restarts
* One task set per registered service

### Notes

* Services are configured with fluent `with*()` methods, provided by the
  behaviour traits in `Castor\Docker\Service\Behaviour`
* The Dockerfiles shipped by the plugin are rendered by
  [twig-dockerfile](https://github.com/castor-php/twig-dockerfile), pinned to
  `0.1`
* Documentation: <https://castor-php.github.io/docker/>
