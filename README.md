# Castor Docker Plugin

A powerful Castor plugin that simplifies Docker-based development environments for PHP applications. This plugin automatically generates Docker Compose configurations and provides tasks to manage your infrastructure.

## Features

- 🚀 Automatic Docker Compose configuration generation
- 🔧 Pre-configured services for common infrastructure components
- 🎯 Service-specific tasks for common operations
- 🔒 SSL certificate generation (with mkcert support)
- 🌐 Traefik-based reverse proxy with automatic routing
- 📦 Multi-stage Docker builds with registry caching
- 👥 Multi-application support in a single project

## Installation

Add this plugin to your Castor project using Composer:

```bash
castor composer require castor-php/docker
```

## Quick Start

1. Create a `castor.php` file in your project root:

```docker/example/castor.php#L1-58
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
    $postgresService = new PostgresService();
    $event->addService($postgresService);

    $event->addService(
        (new SymfonyService(name: 'app', directory: __DIR__))
            ->withDatabaseService($postgresService)
            ->addDomain('myproject.test')
            ->allowHttpAccess()
    );
}
```

2. Run Castor to initialize your infrastructure:

```bash
castor docker:build
castor docker:up
```

## Available Services

### SymfonyService

A comprehensive service for Symfony applications with PHP-FPM, Composer, and various development tools.

**Configuration:**

```php
(new SymfonyService(
    name: 'app',              // Service name
    directory: __DIR__,       // Application directory
    version: '8.5',           // PHP version
))
    ->withDatabaseService($databaseService)
    ->withDockerfile(__DIR__ . '/Dockerfile')
    ->addDomain('app.example.test')
    ->addDomain('example.test')
    ->allowHttpAccess()  // Allow HTTP (default is HTTPS only)
    ->addWorker('messenger', 'php bin/console messenger:consume async')
    ->withRedirectionIoKey('your-key')
```

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
- `app` - PHP-FPM frontend service
- `app-builder` - Builder service for running commands
- `app-worker-{name}` - Worker services for background jobs

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
new MySQLService(
    version: '8',              // MySQL version
    rootPassword: 'root',      // Root password
    database: 'app',           // Database name
)
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
new MariaDBService(
    version: '12.1',           // MariaDB version
    rootPassword: 'root',      // Root password
    database: 'app',           // Database name
)
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
new RedisService()
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
new ElasticsearchService(
    version: '7.8.0',  // Elasticsearch version
)
```

**Docker Services Created:**
- `elasticsearch` - Elasticsearch server
- `kibana` - Kibana web UI
- Named volume: `elasticsearch-data`

**Access:**
- Elasticsearch: `https://elasticsearch.{root_domain}` when router is enabled
- Kibana: `https://kibana.{root_domain}` when router is enabled

### RedirectionioAgentService

Redirection.io agent for managing HTTP redirections.

**Configuration:**

```php
new RedirectionioAgentService()
```

**Docker Services Created:**
- `redirectionio-agent` - Redirection.io agent service

### TraefikRouterService

Traefik reverse proxy for routing HTTP/HTTPS traffic to services.

**Configuration:**

```php
// Automatically registered, but can be customized
new TraefikRouterService(
    sharedHomeDirectory: '.home',
    extraDomains: ['extra.test'],
)
```

**Generated Tasks:**
- `castor router:enable` - Enable the router service
- `castor router:disable` - Disable the router service
- `castor router:generate-certificates` - Generate SSL certificates

**Docker Services Created:**
- `router` - Traefik reverse proxy
- Exposes ports: 80 (HTTP), 443 (HTTPS), 8080 (Traefik dashboard)

**Access:** Traefik dashboard available at `http://localhost:8080`

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
        (new SymfonyService(name: 'app1', directory: __DIR__ . '/app1'))
            ->withDatabaseService($postgresService)
            ->addDomain('app1.project.test')
            ->addDomain('project.test')
    );

    $event->addService(
        (new SymfonyService(name: 'app2', directory: __DIR__ . '/app2', version: '8.2'))
            ->withDatabaseService($mysqlService)
            ->addDomain('app2.project.test')
    );
}
```

### Custom Dockerfile

You can use a custom Dockerfile by extending the base PHP Dockerfile:

```dockerfile
# syntax=ghcr.io/castor-php/twig-dockerfile:latest
{% extends 'Dockerfile' %}

{% block php_extensions %}
    {{ parent() }}
    "php{{ php_version }}-amqp" \
    "php{{ php_version }}-redis" \
    "php{{ php_version }}-mysql" \
{% endblock %}
```

Then reference it in your service:

```php
(new SymfonyService(name: 'app', directory: __DIR__))
    ->withDockerfile(__DIR__ . '/Dockerfile')
```

### Background Workers

Add background worker processes to your application:

```php
(new SymfonyService(name: 'app', directory: __DIR__))
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
(new SymfonyService(name: 'app', directory: __DIR__))
    ->addPhpStanExtraDependency('phpstan/phpstan-symfony', '^2.0')
```

### Docker Profiles

Services can be organized into profiles:

- `default` - Services that start by default
- `router` - Traefik reverse proxy
- `builder` - Build and CI/CD services

Control which profiles to use:

```bash
castor docker:up --profiles default,router
castor docker:build --profiles builder
```

## SSL Certificates

The plugin can generate SSL certificates for local HTTPS development:

### With mkcert (Recommended)

1. Install mkcert: https://github.com/FiloSottile/mkcert
2. Install CA: `mkcert -install`
3. Generate certificates: `castor router:generate-certificates`

### Self-signed Certificates

If mkcert is not available, the plugin will generate self-signed certificates automatically. You'll need to accept the security warning in your browser.

### Regenerate Certificates

```bash
castor router:generate-certificates --force
```

## Environment Variables

The plugin automatically sets these environment variables for your services:

- `PHP_VERSION` - PHP version being used
- `PROJECT_NAME` - Docker Compose project name
- `PROJECT_ROOT_DOMAIN` - Root domain for the project
- `REGISTRY` - Docker registry URL
- `DATABASE_URL` - Database connection string (when using a database service)

## Troubleshooting

### Port Conflicts

If ports 80, 443, or 8080 are already in use:

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
2. Generate certificates: `castor router:generate-certificates`
3. Check that domains resolve to `127.0.0.1` (add to `/etc/hosts` if needed)

## Examples

See the [example](./example/) directory for a complete working example with multiple applications and services.

## License

This plugin is part of the Castor project.
