# Castor Docker Plugin

A powerful Castor plugin that simplifies Docker-based development environments for PHP applications. This plugin automatically generates Docker Compose configurations and provides tasks to manage your infrastructure.

## Features

- 🚀 Automatic Docker Compose configuration generation
- 🔧 Pre-configured services for common infrastructure components
- 🎯 Service-specific tasks for common operations
- 🔒 On-demand, locally-trusted HTTPS (with mkcert support)
- 🌐 Caddy-based reverse proxy with automatic routing from Docker labels
- 📦 Multi-stage Docker builds with registry caching
- 👥 Multi-application support in a single project

## Installation

Add this plugin to your Castor project using Composer:

```bash
castor composer require castor-php/docker
```

## Quick Start

1. Create a `castor.php` file in your project root:

```php
<?php

namespace project;

use Castor\Attribute\AsContext;
use Castor\Attribute\AsListener;
use Castor\Context;
use Castor\Docker\Event\RegisterServiceEvent;
use Castor\Docker\Service\PostgresService;
use Castor\Docker\Service\SymfonyService;

#[AsContext(default: true)]
function default_context(): Context
{
    return new Context([
        'root_domain' => 'myproject.test',
        'registry' => 'ghcr.io/mycompany/myproject'
    ]);
}

#[AsListener(RegisterServiceEvent::class)]
function register_service(RegisterServiceEvent $event)
{
    $postgresService = (new PostgresService())
    ->withVersion('16')             // PostgreSQL version (default: 16);
    $event->addService($postgresService);

    $event->addService(
        (new SymfonyService('app'))
            ->withDirectory(__DIR__)
            ->withDatabaseService($postgresService)
            ->withDomain('myproject.test')
            ->withHttpAccess()
    );
}
```

2. Run Castor to initialize your infrastructure:

```bash
castor docker:build
castor docker:up
```

## Installing Services

Instead of editing your `castor.php` by hand, you can install a service from the
command line — it registers the service in your `RegisterServiceEvent` listener
(creating the listener if there is none) and then builds and starts it:

```bash
# List the installable services
castor docker:service:install

# Install one
castor docker:service:install mariadb
castor docker:service:install symfony
castor docker:service:install rust
```

Depending on the service, you'll be asked a few questions (app name, directory,
PHP version, …). Some services do more on install — a Symfony app is scaffolded
with `composer create-project symfony/skeleton` inside its own builder container,
a Rust app is created with `cargo init` and gets a dependency-free HTTP server to
start from, and if it needs a database you're offered to link an existing one or
install a new one on the spot. Pass `--no-interaction` to accept the defaults, or `--file`
to target a listener file other than `castor.php`.

Removing works the same way — it unregisters the service from your listener
(dropping a now-unused variable and imports) and tears down its containers,
keeping named volumes so the data survives a re-install:

```bash
# List the registered services
castor docker:service:remove

# Remove one
castor docker:service:remove mailpit
```

A database that another service links to is protected — you're asked to remove
or unlink the dependent service first.

Your code is edited with a format-preserving AST rewrite, so only the added or
removed lines change. Other plugins can contribute their own installers by
listening to `RegisterServiceInstallerEvent`.

## Configuring Services

Services take **only their identity in the constructor** — a name for the application services, nothing at all for the others. Everything else is set with fluent `with*()` methods, so you only write the options you actually change:

```php
(new RustService('api'))
    ->withVersion('1.90')
    ->withDirectory(__DIR__ . '/api')
    ->withDomain('api.project.test')
```

Those methods come from a small set of traits in `Castor\Docker\Service\Behaviour`, shared by every service that needs the behaviour. Use them in your own services to get the same API for free:

| Trait | Methods | Used by |
|-------|---------|---------|
| `HasVersion` | `withVersion()`, `getVersion()` | every versioned service |
| `HasDomains` | `withDomain(...$domains)`, `getDomains()` | every routed service |
| `HasHttpAccess` | `withHttpAccess()`, `isHttpAccessAllowed()` | every routed service |
| `HasHttpRouting` | the two above + `withPort()`, `getPort()`, `applyHttpRouting()` | `PHPService`, `GoService`, `RustService`, `RedirectionioAgentService` |
| `HasDirectory` | `withDirectory()`, `getDirectory()` | `PHPService`, `GoService`, `RustService` |
| `HasSharedHomeDirectory` | `withSharedHomeDirectory()`, `getSharedHomeDirectory()` | `PHPService`, `GoService`, `RustService`, `CaddyRouterService` |
| `HasDockerfile` | `withDockerfile()`, `getDockerfile()` | `PHPService` |

`HasVersion` and `HasDockerfile` require the service to declare its own fallback with `getDefaultVersion()` / `getDefaultDockerfile()`, and `HasHttpRouting` lets a service override `getDefaultPort()` (`RustService` returns 8080 that way). Defaults are resolved lazily, when the compose file is generated: `PHPService` picks its Dockerfile from the mode, so `->withMode(PhpMode::Fpm)` works whatever the call order.

> **Upgrading:** constructor arguments other than the name are gone, `addDomain()` is now `withDomain()` (variadic) and `allowHttpAccess()` is now `withHttpAccess()`.
>
> ```php
> // before
> (new SymfonyService(name: 'app', directory: __DIR__, version: '8.4', mode: PhpMode::Fpm))
>     ->addDomain('app.test')
>     ->allowHttpAccess()
>
> // after
> (new SymfonyService('app'))
>     ->withDirectory(__DIR__)
>     ->withVersion('8.4')
>     ->withMode(PhpMode::Fpm)
>     ->withDomain('app.test')
>     ->withHttpAccess()
> ```

## Available Services

### SymfonyService

A comprehensive service for Symfony applications with FrankenPHP or PHP-FPM, Composer, and various development tools.

**Configuration:**

```php
(new SymfonyService('app'))          // Service name, the only constructor argument
    ->withDirectory(__DIR__)         // Application directory (default: '.')
    ->withVersion('8.5')             // PHP version (default: 8.5)
    ->withMode(PhpMode::FrankenPhp)  // PhpMode::FrankenPhp (default) or PhpMode::Fpm
    ->withDatabaseService($databaseService)
    ->withDomain('app.example.test', 'example.test')
    ->withHttpAccess()  // Allow HTTP (default is HTTPS only)
    ->addWorker('messenger', 'php bin/console messenger:consume async')
    ->addExtension('redis')  // Adds "php{version}-redis" (fpm) or the equivalent FrankenPHP extension
    ->withFrankenPhpWorkerMode('public/index.php', num: 4) // Only applies in PhpMode::FrankenPhp
```

**PHP Runtime Modes:**
- `PhpMode::FrankenPhp` (default) - Serves the app with [FrankenPHP](https://frankenphp.dev/) (`dunglas/frankenphp` image + Caddy). Single process, no PHP-FPM/nginx.
- `PhpMode::Fpm` - Serves the app with the classic nginx + PHP-FPM stack.

Only the frontend (HTTP-serving) container differs between modes — the builder and worker containers are identical either way. Known limitation of `PhpMode::FrankenPhp`: there is no `/php-fpm-status` monitoring endpoint.

> **Removed in favour of the agent:** `withRedirectionIoKey()` and the nginx `libnginx-mod-redirectionio` module are gone. redirection.io is now plugged in with [`RedirectionioAgentService`](#redirectionioagentservice), which works the same way for every mode and every language.

**Extensions:**

By default the following PHP extensions are installed: `apcu`, `bcmath`, `curl`, `iconv`, `intl`, `mbstring`, `pgsql`, `uuid`, `xml`, `zip`. Add more with `->addExtension('name')` — no custom Dockerfile needed anymore.

**FrankenPHP Worker Mode:**

`->withFrankenPhpWorkerMode(string $script = 'public/index.php', ?int $num = null, bool $watch = true)` boots `$script` once and keeps it in memory to handle every request (like Octane), instead of re-interpreting it per request. Only takes effect in `PhpMode::FrankenPhp`. Your application needs a compatible runtime to loop over incoming requests from that script — for Symfony, install [`runtime/frankenphp-symfony`](https://github.com/php-runtime/frankenphp-symfony) and point `$script` at `public/index.php`. `$watch` (enabled by default) restarts the worker automatically when files under the app directory change, which is what you want for local development.

**Generated Tasks:**
- `castor app:bash` - Open a bash shell in the PHP container
- `castor app:install` - Install Composer dependencies
- `castor app:composer` - Run Composer commands
- `castor app:symfony` - Run Symfony console commands
- `castor app:cache-clear` - Clear application cache
- `castor app:cache-warmup` - Warm up application cache
- `castor app:qa:phpstan` - Run PHPStan static analysis
- `castor app:qa:cs` - Run PHP CS Fixer
- `castor app:qa:rector` - Run Rector refactoring
- `castor app:qa:twig-cs` - Fix Twig coding style
- `castor app:db:migrate` - Run database migrations
- `castor app:db:fixtures` - Load database fixtures

**Docker Services Created:**
- `app` - FrankenPHP or PHP-FPM frontend service (depending on `mode`)
- `app-builder` - Builder service for running commands
- `app-worker-{name}` - Worker services for background jobs

### RustService

A service for Cargo applications. The sources are mounted in the container, `cargo build` runs inside it, and the resulting debug binary is used as the container command.

The image is the official `rust` one plus the `clippy` and `rustfmt` components (the official images ship the *minimal* rustup profile, which leaves them out). The crate registry lives in the shared home directory (`CARGO_HOME=/home/app/.cargo`), so a given crate is downloaded once for the whole project instead of once per service or per rebuild. Build artifacts stay in the project's own `target/` directory.

**Configuration:**

```php
(new RustService('api'))            // Service name — must match the crate name,
                                    // the container runs target/debug/<name>
    ->withVersion('1.90')           // Rust version, tag of the official rust image (default: 1)
    ->withDirectory(__DIR__ . '/api')
    ->withPort(8080)                // Port the application listens on (default: 8080)
    ->withDomain('api.project.test')
    ->withHttpAccess()              // Also serve plain HTTP, without redirecting to HTTPS
```

**Generated Tasks:**
- `castor api:build` - Build the application (`cargo build`)
- `castor api:restart` - Restart the service
- `castor api:watch` - Rebuild and restart on every change to a `.rs`, `Cargo.toml` or `Cargo.lock` file
- `castor api:test` - Run the test suite (`cargo test`)
- `castor api:cargo` - Run any cargo command in the container
- `castor api:bash` - Open a bash shell in the Rust container
- `castor api:qa:clippy` - Run Clippy
- `castor api:qa:fmt` - Run rustfmt

**Docker Services Created:**
- `api` - The Rust application

### PostgresService

PostgreSQL database service.

**Configuration:**

```php
new PostgresService()
```

**Generated Tasks:**
- `castor db:psql` - Connect to PostgreSQL database

**Docker Services Created:**
- `postgres` - PostgreSQL 16 server
- Named volume: `postgres_data`

**Database URL:** `postgresql://app:app@postgres:5432/app?serverVersion=16&charset=utf8`

### MySQLService

MySQL database service.

**Configuration:**

```php
(new MySQLService())
    ->withVersion('8')              // MySQL version (default: 8)
    ->withRootPassword('root')      // Root password (default: root)
    ->withDatabase('app')           // Database name (default: app)
```

**Generated Tasks:**
- `castor db:mysql` - Connect to MySQL database

**Docker Services Created:**
- `mysql` - MySQL server
- Named volume: `mysql-data`

**Database URL:** `mysql://root:root@mysql:3306/app`

### MariaDBService

MariaDB database service.

**Configuration:**

```php
(new MariaDBService())
    ->withVersion('12.1')           // MariaDB version (default: 12.1)
    ->withRootPassword('root')      // Root password (default: root)
    ->withDatabase('app')           // Database name (default: app)
```

**Generated Tasks:**
- `castor db:mariadb` - Connect to MariaDB database

**Docker Services Created:**
- `mariadb` - MariaDB server
- Named volume: `mariadb-data`

**Database URL:** `mysql://root:root@mariadb:3306/app?serverVersion=mariadb-12.1&charset=utf8mb4`

### RedisService

Redis cache server with RedisInsight web UI.

**Configuration:**

```php
(new RedisService())
    ->withVersion('5')              // Redis version (default: 5)
```

**Docker Services Created:**
- `redis` - Redis 5 server
- `redis-insight` - RedisInsight web UI (accessible via router)
- Named volumes: `redis-data`, `redis-insight-data`

**Access:** RedisInsight available at `https://redis.{root_domain}` when router is enabled

### RabbitMQService

RabbitMQ message broker with management UI.

**Configuration:**

```php
new RabbitMQService()
```

**Docker Services Created:**
- `rabbitmq` - RabbitMQ server with management plugin
- Named volume: `rabbitmq-data`

**Access:** Management UI available at `https://rabbitmq.{root_domain}` when router is enabled
**Default Credentials:** `guest:guest`

### ElasticsearchService

Elasticsearch search engine with Kibana.

**Configuration:**

```php
(new ElasticsearchService())
    ->withVersion('7.8.0')          // Elasticsearch version (default: 7.8.0)
```

**Docker Services Created:**
- `elasticsearch` - Elasticsearch server
- `kibana` - Kibana web UI
- Named volume: `elasticsearch-data`

**Access:**
- Elasticsearch: `https://elasticsearch.{root_domain}` when router is enabled
- Kibana: `https://kibana.{root_domain}` when router is enabled

### RedirectionioAgentService

[redirection.io](https://redirection.io/) agent (v3) running as a **reverse proxy** in front of your applications, so it works with any of them — FrankenPHP, PHP-FPM, Go, Rust, … — with no module to install in the application image.

Traffic flows `router -> agent -> application`: the domain is declared on the agent instead of on the application, which therefore does **not** call `withDomain()` any more. One agent handles as many domains as needed, each with its own project key.

**Configuration:**

```php
$app = (new SymfonyService('app'))->withDirectory(__DIR__ . '/app');
$api = (new RustService('api'))->withVersion('1.90')->withDirectory(__DIR__ . '/api');

$event->addService($app);
$event->addService($api);

$event->addService(
    (new RedirectionioAgentService())
        ->withProjectKey('default-project-key') // Used by the domains registered without an explicit key
        ->withInstanceName('dev')
        // ->addReverseProxy(string $domain, ServiceInterface|string $target, ?string $projectKey = null, int $port = 80)
        ->addReverseProxy('app.project.test', $app)
        ->addReverseProxy('legacy.project.test', $app, 'another-project-key')
        ->addReverseProxy('api.project.test', $api, port: 8080)
);
```

The agent configuration is generated from those calls and shipped to the container as an inline compose config (visible in `compose.generated.yaml` under `configs:`), so there is no `agent.yml` to maintain by hand.

**Docker Services Created:**
- `redirectionio-agent` - The agent, routed by Caddy for every registered domain and forwarding to the target services

> **Upgrading:** the nginx module integration (`PHPService::withRedirectionIoKey()` + `libnginx-mod-redirectionio`) has been removed. Replace `->withRedirectionIoKey($key)` and the application's `->withDomain($domain)` with a single `->addReverseProxy($domain, $service, $key)` on the agent.

### CaddyRouterService

[Caddy](https://caddyserver.com/) reverse proxy (via [caddy-docker-proxy](https://github.com/lucaslorentz/caddy-docker-proxy)) for routing HTTP/HTTPS traffic to services. Routes are built automatically from the `caddy.*` Docker labels emitted for each service that declares a domain — no static configuration file to maintain.

TLS certificates are minted **on demand** by Caddy's internal issuer the first time a domain is hit, so there is nothing to generate ahead of time. If the [mkcert](https://github.com/FiloSottile/mkcert) root CA is installed on your host, `castor router:enable` copies it into the router so those certificates are trusted by your browsers.

**Configuration:**

```php
// Automatically registered, but can be customized
(new CaddyRouterService())
    ->withSharedHomeDirectory('.home')
```

**Generated Tasks:**
- `castor router:enable` - Enable the router service (and copy the mkcert CA if available)
- `castor router:disable` - Disable the router service

**Docker Services Created:**
- `router` - Caddy reverse proxy
- Named volume: `router-data` (issued certificates and local CA)
- Exposes ports: 80 (HTTP), 443 (HTTPS)

The router handles HTTP/HTTPS only — raw TCP protocols can't be hostname-routed.
Non-backend services (databases, Redis, RabbitMQ, Elasticsearch, ClickHouse,
Mailpit) therefore expose an opt-in `<service>:expose` task instead:

```bash
# Publish the service's port on the host (postgres → localhost:5432)
castor postgres:expose

# Pick a different host port (e.g. to avoid a clash with a local server)
castor mysql:expose 3307

# Stop exposing it
castor postgres:expose --stop
```

Each task runs a small `socat` forwarder container that publishes the port on
demand and forwards to the service over the project network — nothing is opened
on the host until you ask. The forwarders are tagged as part of the compose
project, so `castor docker:destroy` removes them along with everything else.
Under the hood the task calls the `expose_service_port()` helper, which your own
services can reuse.

Exposed services are remembered: `castor docker:stop` takes the forwarders down,
and the next `castor docker:up` brings them back automatically — you only expose
a service once, not on every restart (until you `--stop` it).

## Docker Tasks

The plugin provides several Docker management tasks:

### `castor docker:build`

Builds all Docker images.

**Options:**
- `--service` - Build a specific service
- `--profiles` - Specify profiles to build

**Example:**
```bash
castor docker:build
castor docker:build --service app
castor docker:build --profiles builder
```

### `castor docker:up`

Starts the infrastructure.

**Options:**
- `--service` - Start a specific service
- `--profiles` - Specify profiles to start
- `--build` - Build images before starting

**Example:**
```bash
castor docker:up
castor docker:up --build
castor docker:up --service app
```

### `castor docker:stop`

Stops running containers.

**Options:**
- `--service` - Stop a specific service
- `--profiles` - Specify profiles to stop

**Example:**
```bash
castor docker:stop
castor docker:stop --service app
```

### `castor docker:logs`

Displays container logs.

**Options:**
- `--service` - Show logs for a specific service
- `--profiles` - Specify profiles

**Example:**
```bash
castor docker:logs
castor docker:logs --service app
```

### `castor docker:ps`

Lists all containers with their status.

**Example:**
```bash
castor docker:ps
```

### `castor docker:destroy`

Removes all containers, volumes, and networks.

**Options:**
- `--force` / `-f` - Skip confirmation prompt

**Example:**
```bash
castor docker:destroy
castor docker:destroy --force
```

⚠️ **Warning:** This permanently deletes all data including volumes!

### `castor docker:push`

Pushes Docker images cache to the configured registry.

**Options:**
- `--dry-run` - Show what would be pushed without actually pushing

**Example:**
```bash
castor docker:push
castor docker:push --dry-run
```

## Configuration

### Context Variables

Configure your infrastructure in the default context:

```php
#[AsContext(default: true)]
function default_context(): Context
{
    return new Context([
        'root_domain' => 'myproject.test',    // Root domain for all services
        'registry' => 'ghcr.io/org/project',  // Docker registry for caching
    ]);
}
```

### Castor Variables

Additional variables can be set in your Castor configuration:

- `php_version` - Default PHP version (default: from service config)
- `registry` - Docker registry for image caching
- `build_args` - Additional build arguments for Docker builds

## Generated Files

The plugin automatically generates and manages these files:

### `compose.yaml`

Main Docker Compose file that includes:
- `compose.generated.yaml` - Auto-generated service definitions (DO NOT EDIT)
- `compose.override.yaml` - Your local customizations

**Example `compose.yaml`:**

```docker/example/compose.yaml#L1-8
# This is your docker-compose file. It has been generated by Castor, but you can edit it if needed.
name: castor-docker-demo
include:
   - path:
         - compose.generated.yaml # This file is generated you should not remove or edit this line / file.
         - compose.override.yaml # This file is for your local overrides of existing services.

# Here you can also add your own services.
```

### `compose.override.yaml`

Use this file for local environment customizations:

```docker/example/compose.override.yaml#L1-9
# This file is for your local overrides. It is not generated by Castor.
services:
    app:
        environment:
            - CUSTOM_ENV_VAR=value
```

## Advanced Usage

### Multiple Applications

You can run multiple applications in the same infrastructure:

```php
#[AsListener(RegisterServiceEvent::class)]
function register_service(RegisterServiceEvent $event)
{
    $postgresService = new PostgresService();
    $mysqlService = new MySQLService();
    
    $event->addService($postgresService);
    $event->addService($mysqlService);

    $event->addService(
        (new SymfonyService('app1'))
            ->withDirectory(__DIR__ . '/app1')
            ->withDatabaseService($postgresService)
            ->withDomain('app1.project.test', 'project.test')
    );

    $event->addService(
        (new SymfonyService('app2'))
            ->withDirectory(__DIR__ . '/app2')
            ->withVersion('8.2')
            ->withDatabaseService($mysqlService)
            ->withDomain('app2.project.test')
    );
}
```

### Custom Dockerfile

Extra PHP extensions don't need a custom Dockerfile anymore, use `->addExtension('name')` instead (see [SymfonyService](#symfonyservice)).

For deeper customization, you can still provide a custom Dockerfile by extending the base PHP Dockerfile (or `Dockerfile.frankenphp` if you use `PhpMode::FrankenPhp`) and overriding any other block:

```dockerfile
# syntax=ghcr.io/castor-php/twig-dockerfile:latest
{% extends 'Dockerfile' %}

{% block builder %}
    {{ parent() }}
RUN echo "custom builder step"
{% endblock %}
```

Then reference it in your service:

```php
(new SymfonyService('app'))->withDirectory(__DIR__)
    ->withDockerfile(__DIR__ . '/Dockerfile')
```

### Background Workers

Add background worker processes to your application:

```php
(new SymfonyService('app'))->withDirectory(__DIR__)
    ->addWorker('messenger', 'php bin/console messenger:consume async --time-limit=3600')
    ->addWorker('notifications', 'php bin/console app:process-notifications')
```

Each worker runs as a separate Docker container.

### Quality Assurance Tools

The plugin integrates with common PHP QA tools:

```bash
# Run PHPStan
castor app:qa:phpstan

# Fix code style
castor app:qa:cs

# Run Rector
castor app:qa:rector

# Fix Twig templates
castor app:qa:twig-cs
```

Configure versions in your service:

```php
(new SymfonyService('app'))->withDirectory(__DIR__)
    ->addPhpStanExtraDependency('phpstan/phpstan-symfony', '^2.0')
```

### Docker Profiles

Services can be organized into profiles:

- `default` - Services that start by default
- `router` - Caddy reverse proxy
- `builder` - Build and CI/CD services

Control which profiles to use:

```bash
castor docker:up --profiles default,router
castor docker:build --profiles builder
```

## SSL Certificates

The Caddy router provisions TLS certificates **on demand**: the first time a
domain is requested, Caddy's internal issuer mints a certificate for it. There
is no certificate to generate or renew manually.

### With mkcert (Recommended)

To make those certificates trusted by your browsers (no security warning):

1. Install mkcert: https://github.com/FiloSottile/mkcert
2. Install the CA in your system trust store: `mkcert -install`
3. (Re)start the router: `castor router:enable`

`router:enable` copies the mkcert root CA into the router, which then signs the on-demand certificates with it.

### Without mkcert

If mkcert is not available, Caddy falls back to its own local CA. HTTPS still
works, but you'll need to accept the security warning in your browser (or add
Caddy's root CA — stored in the `router-data` volume — to your trust store).

## Environment Variables

The plugin automatically sets these environment variables for your services:

- `PHP_VERSION` - PHP version being used
- `PROJECT_NAME` - Docker Compose project name
- `PROJECT_ROOT_DOMAIN` - Root domain for the project
- `REGISTRY` - Docker registry URL
- `DATABASE_URL` - Database connection string (when using a database service)

## Troubleshooting

### Port Conflicts

If ports 80 or 443 are already in use:

1. Stop the conflicting services
2. Or modify the router port mappings in your `compose.override.yaml`

### Permission Issues

The plugin runs containers with your user ID to avoid permission issues. If you encounter problems:

1. Check that the `.home` directory is writable
2. Verify volume mount permissions

### Containers Won't Start

1. Check logs: `castor docker:logs`
2. Verify builds: `castor docker:build`
3. Check Docker daemon: `docker ps`

### Router Not Working

1. Enable the router: `castor router:enable`
2. For locally-trusted certificates, install mkcert (`mkcert -install`) and re-run `castor router:enable`
3. Check that domains resolve to `127.0.0.1` (add to `/etc/hosts` if needed)

## Examples

See the [example](./example/) directory for a complete working example with multiple applications and services.

## License

This plugin is part of the Castor project.
