# Changelog

## Unreleased

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
* `get_default_profiles()` reads the `docker_profiles` context data instead of
  always returning `['default']`.
* The Rust Dockerfile is now a Twig template with `rust_base` and `runtime`
  blocks, and Go gets one with `go_base` and `runtime`. Both are extensible the
  way the PHP ones already were — which is how extra Debian packages, rustup
  components, targets and toolchains are added.

### Changed

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
