# Changelog

## Unreleased

### Added

* The global router starts and stops with the projects. `docker:up` starts it
  when the project routes a domain and it is not already running, and
  `docker:stop` — `docker:destroy` too — stops it once no routed container is
  left running on the machine. `docker:router:enable` was a one-time setup
  nobody remembers on a new machine, and forgetting it is silent: the containers
  come up, the domains answer "connection refused" on 443, and nothing says why.
  A project routing no domain neither starts nor stops it, and a project that
  goes down while another still runs leaves it up.
* The `router_autostart` context variable and the `CASTOR_DOCKER_ROUTER_AUTOSTART`
  environment variable, both turning that off — the environment wins, so a CI
  job or a single shell can leave the router of the machine alone without
  touching the project. With it off, only `docker:router:enable` and
  `docker:router:disable` start and stop the router.

### Changed

* `docker:router:status` reports whether the autostart is on, and the projects
  the router currently serves.

## 0.3.3 - 2026-08-11

### Added

* `castor docker:about` (alias `castor about`), which lists every URL the
  project answers on. Nothing told you what a project serves: the domains live
  in the `caddy` labels of the generated compose file, and finding the address
  of a service meant reading them — or guessing from the root domain. Each URL
  is listed against the service serving it, and against whether that service
  runs. They are read from `compose.generated.yaml`, `compose.yaml` and
  `compose.override.yaml`, so a domain declared by a service, by an
  `#[AsDockerComposeBuilder]` function or straight in your own compose file is
  listed the same way, and the task answers with everything stopped — only the
  running/stopped statuses need a docker daemon.

## 0.3.2 - 2026-08-10

### Fixed

* The router watches the Docker socket of the daemon the projects run on, rather
  than always `/var/run/docker.sock`. It builds its routes from the `caddy.*`
  labels it reads there, and a CI job installing a daemon of its own — as the
  `docker/setup-docker-action` family does — a rootless daemon or Colima all put
  their socket somewhere else. Watching the wrong one is silent: the router comes
  up, finds no label, serves nothing, and every routed domain answers "connection
  refused" on 443 — which then reads as a plain 404 from anything calling a
  project's own API through it. `DOCKER_SOCKET_PATH` is read first, then a
  `unix://` `DOCKER_HOST`; `docker:router:enable` also warns when the socket it
  resolved does not exist.

## 0.3.1 - 2026-08-10

### Fixed

* The `{app}:qa:phpstan`, `{app}:qa:cs` and `{app}:qa:rector` tasks let the
  configuration of the application decide what is analysed. They used to pass a
  path by default — the application directory for PHPStan, its `src/` for the two
  others — and none of these tools treats a path on the command line as a
  restriction of its configured paths: it *replaces* them. PHPStan only falls
  back to `parameters.paths` when the command line names none, PHP CS Fixer
  ignores the finder of its configuration unless asked for
  `--path-mode=intersection`, and Rector does the same with `withPaths()`. So an
  application whose `phpstan.neon` says `paths: [src]` was analysed whole,
  `vendor/` and `var/` included — while PHPStan still reported that
  configuration file as used, because everything else in it did apply — and a
  PHP CS Fixer finder covering `tests/`, `config/` and `migrations/` was cut down
  to `src/`. The tasks now name no path at all when the working directory of the
  application holds a configuration file the tool discovers on its own
  (`phpstan.neon` & co, `.php-cs-fixer.php`, `.php-cs-fixer.dist.php`,
  `rector.php`), and keep the previous fallback for an application that
  configures nothing. Arguments passed to the task still win over both.

## 0.3.0 - 2026-08-07

### Added

* The containers of a project now resolve its own public domains. The router is
  global and joins the project network from the outside, so nothing inside a
  project used to resolve `https://api.myproject.test` — an application calling
  its own API, a worker hitting the front end or a reverse proxy going back to
  the backend all failed, and Docker accepts no wildcard in `extra_hosts` to
  work around it. The plugin knows every routed domain and writes one
  `extra_hosts` entry per domain on every service, pointing at the host gateway
  where the router's ports 80 and 443 answer. The domains are also passed to
  `docker network connect` as network aliases. Turn it off with the
  `resolve_domains_via_host` context data.
* `RustBuilder` and `GoBuilder`: one compiler container for a whole repository,
  holding the toolchain and declaring the applications it compiles with
  `withApp()`. Each application gets its own task namespace — `<app>:build`,
  `<app>:test`, `<app>:cargo` / `<app>:go`, `<app>:qa:clippy`, `<app>:qa:fmt` —
  all running in that one container. A monorepo no longer declares the toolchain
  once per binary, and the build and QA commands no longer run inside a running
  application container.
* `BinaryRunService`, the runtime half of the same model: it runs one compiled
  binary and nothing else, whatever produced it. Attaching a builder with
  `withBuilder()` gives it the image the binary was compiled in, the same mount,
  and the `build` and `watch` tasks.
* `withWorkingDirectory()` on every service mounting a directory. What gets
  mounted, where the commands run and where the binary lives are three different
  things in a monorepo: `withDirectory()` mounts, `withWorkingDirectory()` names
  the sub-directory below it, and `withBinaryPath()` locates the binary. For a
  PHP application the document root of the frontend follows, through the new
  `app_root` build argument.
* `PHPService::withSharedBuilder()` and `withoutBuilder()`, so several
  applications of one repository stop generating identical `-builder`
  containers.
* `RustService::withTarget()`, which adds `--target <triple>` to the build
  command *and* moves the default binary path to `target/<triple>/debug/<name>`,
  plus `withBinaryPath()`, `withBuildCommand()` and `withRunCommand()` on both
  `RustService` and `GoService`.
* Missing compose keys on `ServiceBuilder`: `restart()`, `ulimits()`, `dns()`,
  `extraHost()` and `deploy()`. `environment()` now takes a `null` value, which
  emits `KEY: null` — the compose syntax passing a variable through from the
  environment castor runs in.
* `docker_compose_run()` and `docker_exit_code()` take `environment`,
  `entrypoint` and `ports`. A failing command is now wrapped in a
  `RuntimeException` naming the service and the command, instead of the bare
  `docker compose` error.
* `withName()` on every service that used to hardcode its name — the databases,
  Redis, RabbitMQ, Elasticsearch, ClickHouse, Mailpit and the redirection.io
  agent — so the same one can be registered twice. A second instance was
  impossible before: the two would have collided on the compose service, on the
  named volumes and on the routed domain. Everything a service generates is now
  derived from its name, including the companion containers (`redis-insight`,
  `clickhouse-keeper`), the DSN host and the task namespace. The connect task of
  a database and the Kibana container keep their historical name for the first
  instance, so nothing moves for a project registering one of each.
* Server configuration for `MySQLService` and `MariaDBService`, with
  `withSetting()`, `withSettings()`, `withConfiguration()` and
  `withConfigurationFile()`. The three sources are merged into one file shipped
  as a compose config in `/etc/mysql/conf.d`, so its content lives in the
  generated compose file: no host directory for Docker to create as `root`, and
  no file whose permissions the server might refuse. A configuration file is
  read when the compose file is generated, and a missing one raises instead of
  leaving the server silently unconfigured.
* `RedirectionioAgentService::withApiHost()` and `withApiTimeout()`, writing the
  `api` section of the generated `agent.yml` so the agent can talk to a
  self-hosted instance. Absent by default.
* `ServiceBuilder::config()` takes `recreateOnChange`, stamping a digest of the
  config content in a label. Compose does not recreate a container when only the
  content of an inline config changed, so a server reading its configuration at
  boot kept running with the old one until someone thought of
  `--force-recreate`. Used by the redirection.io agent and by the MySQL-family
  configuration, both of which read theirs once. Left off for anything that
  reloads on its own.
* `castor {app}:worker:restart` and `castor {app}:worker:stop`, on a PHP
  application declaring workers. Both take the worker name — the one passed to
  `addWorker()` — and act on every worker when it is omitted; an unknown name is
  rejected with the list of those declared, rather than quietly acting on all of
  them. `worker:restart` starts a stopped worker, so there is no separate start
  task. The tasks do not exist on an application without workers.
* `castor docker:logs:clear [service]`, emptying the log files docker keeps for
  the containers so `docker:logs` starts from a clean slate. Nothing is
  restarted: the file is truncated in place. Stopped containers and inactive
  profiles are covered; a container whose logging driver is not `json-file` is
  reported as skipped. When the file cannot be written directly — it belongs to
  `root`, and on Docker Desktop it lives inside the VM — a short-lived
  `--privileged` container reaches it.
* Shell completion on every argument naming something, each offering the right
  list: the compose containers on `docker:build`, `docker:up`, `docker:stop`,
  `docker:logs` and `docker:logs:clear`; the services registered in `castor.php`
  on `docker:service:remove`; the available installers on
  `docker:service:install`; and the workers of the application on
  `{app}:worker:restart` and `{app}:worker:stop`. The container names are read
  from the three compose files, so the services a project declares itself are
  offered alongside the generated ones and completion needs no running daemon.
* `castor {app}:update` on every application of a `GoBuilder`, updating the
  module dependencies with `go get -u ./...` and putting `go.mod` and `go.sum`
  back in order with `go mod tidy` — the other half of the operation, which runs
  by default and is skipped with `--no-tidy`. Takes a module name to update a
  single one, and `--patch` to stay inside the current minor version. It runs in
  the builder container, so on the Go version the module is compiled with.
* A restart policy for the PHP workers, as a third argument of `addWorker()`.
  Nothing brought a worker back when it exited, so a consumer given the
  `--time-limit` the documentation recommends ran once and stayed down until the
  next `docker:up`. Reaching that limit is a *successful* exit, so it wants
  `unless-stopped` rather than `on-failure`. There is still no policy unless
  asked for.
* `BinaryRunService::withRestart()`, setting the compose restart policy —
  `on-failure` by default. Nothing watches a binary that exits, so without one
  it stays down until someone notices; `on-failure` brings it back without
  fighting a deliberate `docker:stop` the way `always` would. Absent unless
  asked for.
* `RustBuilder::withNightlyFormatter()`, which installs the nightly toolchain
  with its rustfmt in the image and points the `fmt` task of every application
  at it, leaving `build`, `test`, `cargo` and `qa:clippy` on the default
  toolchain. Most of rustfmt's options are still unstable, so a `rustfmt.toml`
  using any of them is silently ignored by a stable rustfmt — building on stable
  and formatting on nightly is the usual answer. A nightly declared with
  `addRustupToolchain()` is completed with `rustfmt` rather than installed
  twice.
* `RedirectionioAgentService::withDebug()`, raising the agent log level and
  letting it accept a certificate it cannot verify when calling its API — which
  is what a self-hosted `withApiHost()` served by the local router hands it, the
  agent image carrying the public CA bundle only.
* `get_default_profiles()` reads the `docker_profiles` context data instead of
  always returning `['default']`.
* The Rust Dockerfile is now a Twig template with `rust_base` and `runtime`
  blocks, and Go gets one with `go_base` and `runtime`. Both are extensible the
  way the PHP ones already were — which is how extra Debian packages, rustup
  components, targets and toolchains are added.

### Fixed

* The applications behind `RedirectionioAgentService` now receive the `Host` of
  the original request. The agent derives it from the address it forwards to —
  an IP keeps the original, a host name replaces it with itself — and every
  target here is a compose service reached by name, so the application was
  handed `Host: app`. Symfony rejects that as an untrusted host, and every
  absolute URL generated from it was wrong. `preserve_host` is written on every
  forward; `withPreserveHost(false)` restores the agent's own behaviour, per
  agent or per domain.
* `ClickhouseService` routes its UI to port 8123. The image exposes 8123 (HTTP)
  and 9000 (native protocol), and without a port Caddy picked whichever it found
  first — answering 502 about half the time.
* The content of an inline compose config is escaped against interpolation.
  Compose interpolates the file it reads, configs included, so an nginx
  configuration reached the container stripped of every `$host`, `$uri` and
  `$document_root`, with only a "variable is not set" warning to show for it.
  `ComposeBuilder::config()` takes `interpolate: true` for a config that really
  does mean to read `${PROJECT_NAME}` & co.

### Changed

* **`ServiceBuilder::withHttpRouting()` requires the port.** Without one it
  emitted a bare `{{upstreams}}`, which caddy-docker-proxy resolves against
  whatever the image happens to expose — the first of several, or port 80 when
  it exposes nothing — routing to the wrong port silently and answering 502.
  Every call now names it, `RedisService` included: the RedisInsight UI listens
  on 5540 and was relying on that guess. Pass the port to any
  `withHttpRouting()` of your own.
* `{service}:bash`, and the database sessions, no longer fail outright when the
  environment has no terminal. They asked castor for an interactive context
  unconditionally, and that throws a `LogicException` on a pipe, in CI or under
  an agent — so `castor app:bash < script.sh` could not work. The interactive
  flags are now only requested when they can be honoured; without a terminal the
  command still runs on whatever is piped into it.
* **The QA tasks now run inside the builder container.** `{app}:qa:phpstan`,
  `{app}:qa:cs`, `{app}:qa:rector` and `{app}:qa:twig-cs` used to run on the
  host, against whichever PHP happens to run castor — a different version, and
  different extensions, from the one the application runs on. The tools are
  still installed by castor in `.castor/vendor/.tools/`, but that directory is
  now mounted at `/castor-tools` in the builder container and the tools are
  executed there. Each application gets its own installation — `app-phpstan` —
  so two applications of one repository pinning different versions no longer
  reinstall over each other on every run. The tasks return the tool's exit code
  instead of its `Process`.
* `GoService` builds from a Dockerfile shipped by the plugin instead of running
  the `golang` image directly, so it can be extended and its build cache pushed
  like every other service. Its generated `build` section is new; the tasks and
  the runtime behaviour are unchanged.
* `GoService` and `RustService` are no longer `final`, their properties are
  `protected`, and `getTasks()` is split into one method per task, so a subclass
  can replace, remove or add a single task without redeclaring the others. The
  behaviour traits' properties are `protected` too.
* `docker_exit_code()` now forwards `portMapping` to `docker_compose_run()`,
  which it silently dropped.
* The `project_name` context data is no longer overwritten by the `name:` of
  `compose.yaml`, which is the precedence `get_project_name()` documents and
  never applied. The plugin also exports `COMPOSE_PROJECT_NAME`, so docker
  compose uses the same project name it does — without it compose built in the
  project named by the file while the plugin looked for containers, networks and
  `${PROJECT_NAME}-<service>` images in the one named by the context. Together
  they make a second checkout of a repository — a git worktree — a matter of
  overriding `project_name` and `root_domain` in its context.
* `docker_compose_run()` no longer prints the two lines compose emits for the
  throwaway container it creates — `Container app-builder-run-8c9d8bef
  Creating`, then `Created` — in front of the output of the command asked for.
  They come back with `-v`, where knowing which container ran is the point.
  `docker_compose()` takes a `progress` argument for the same purpose.
* **The tasks of a named service are now called `{service}:{task}`.** The
  database sessions moved out of the shared `db:` namespace and are named after
  what they do rather than after the client they run: `db:psql` is now
  `postgres:client`, `db:mysql` is `mysql:client`, `db:mariadb` is
  `mariadb:client` and `db:clickhouse` is `clickhouse:client`. Registering the
  same service twice therefore gives two full task sets — `postgres:client`
  next to `analytics:client` — instead of a `db:` namespace where the second
  instance had to be spelled differently.

### Documentation

* [Multiple applications](https://castor-php.github.io/docker/going-further/multiple-applications/)
  covers the monorepo shape, and the `example/` project is now one: two PHP
  applications sharing a builder, a Rust and a Go binary each built by their
  language's builder, and a container calling another through its public domain.

## 0.2.1 - 2026-07-27

### Added

* `DockerComposeBuilderEvent`, dispatched with the `ComposeBuilder` once every
  service has contributed and before the file is serialized: add a container the
  plugin has no service for, or change one that is already registered.
* `DockerComposeWriteEvent`, dispatched with the configuration as a plain array
  right before it is written: the escape hatch for the compose keys the builder
  does not model — `deploy`, `logging`, `ulimits`, the `x-` extension fields.
* `#[AsDockerComposeBuilder]`, sugar over the first event: the function receives
  the `ComposeBuilder`, optionally the `Context`, and takes a `priority`.
* Documentation for the three of them, in [extending the compose
  file](https://castor-php.github.io/docker/going-further/extending-the-compose-file/),
  and an example of each in the `example/` project.

### Changed

* The plugin no longer builds its task commands from castor's internal API: the
  tasks of a service are handed over as `TaskDescriptor` through
  `FunctionsResolvedEvent`, and castor builds them. `ExpressionLanguage`,
  `Slugger`, `TaskCommand` and the console `Application` are no longer reached
  into, which leaves the event dispatcher as the only internal API still in use.
* `castor list` no longer regenerates `compose.generated.yaml`. Castor boots it
  on a bare context by design, so the file was rewritten without the project
  configuration and had to be repaired by the next command.
* The docker compose project name is read from the `name` of `compose.yaml`
  rather than from the context data, so it is correct on a project declaring no
  `#[AsContext]` function — where it used to fall back to the directory name.

### Documentation

* More detail on the Dockerfile blocks and on the PHP service.

## 0.2.0 - 2026-07-27

### Changed

* The Caddy router is now **global**: a single instance, living in
  `~/.castor/docker/router/` and shared by every project on the machine,
  replaces the `router` service that each project used to declare. Ports 80 and
  443 are bound once, projects run side by side, and the router survives their
  restarts.
* The router joins the network of each project on `docker:up`, and leaves it
  before `docker:down` removes it. Projects keep their own network and never
  share one, so two projects exposing a service under the same name no longer
  collide in the Docker DNS.
* The router is no longer built from a Dockerfile: it runs the upstream
  `caddy-docker-proxy` image and receives its base Caddyfile as a compose
  config, so enabling it no longer depends on a project's `vendor/` directory
  being present.
* The mkcert CA now lives in `~/.castor/docker/router/certs/` instead of the
  project shared home directory, which the global router could not read.

### Added

* `docker:router:status`, `docker:router:logs` and `docker:router:restart`
* `docker:router:enable` joins the networks of the projects that are already
  running, instead of routing nothing until their next `docker:up`

### Removed

* `CaddyRouterService`, and the `router` compose profile with it. The router is
  no longer registered in `castor.php`
* `router:enable` and `router:disable`, renamed to `docker:router:enable` and
  `docker:router:disable`

### Upgrading

The per-project router of a previous version may still hold ports 80 and 443.
It is no longer declared in the generated compose file, so `docker:up` removes
it as an orphan — run it before enabling the global router:

```bash
castor docker:up                # drops the old per-project router container
castor docker:router:enable     # starts the global one
```

Should a container still hold those ports, remove it with
`docker rm -f <name>`.

## 0.1.3 - 2026-07-27

### Fixed

* Remove certificates before writing them when router is reenabled : they may be readonly and copy on a existing readonly file will fail
* Set complete versions for databases, has some dependencies expect a complete version

## 0.1.2 - 2026-07-25

### Fixed

* Create the host directories bind-mounted by the services — the shared home
  directory, the application directories, any other mount inside the project —
  before docker does. Docker creates a missing bind mount source as `root`,
  which then leaves the containers unable to write in it. A directory left over
  from an earlier run and not writable is reported instead.

## 0.1.1 - 2026-07-25

### Fixed

* Create `compose.yaml` on a project that declares no `#[AsContext]` function.
  Castor only dispatches `ContextCreatedEvent` when it instantiates a declared
  context, so a fresh project never got its compose file and every `docker:*`
  task failed on the missing file.
* Stop `castor list` from regenerating `compose.generated.yaml` from a bare
  context, which dropped the project configuration: the services exposing a UI
  fell back to the default root domain, and the containers to the default user
  id.

## 0.1.0 - 2026-07-25

First release.

### Services

* PHP and Symfony applications, served by FrankenPHP or nginx + PHP-FPM, with a
  builder container, background workers, FrankenPHP worker mode and QA tasks
  (PHPStan, PHP CS Fixer, Rector, Twig CS Fixer)
* Go and Rust applications, built and run from the mounted sources, with a
  watch task rebuilding on change
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
