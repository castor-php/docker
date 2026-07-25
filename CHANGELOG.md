# Changelog

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
