<?php

namespace project;

use Castor\Attribute\AsContext;
use Castor\Attribute\AsListener;
use Castor\Context;
use Castor\Docker\Attribute\AsDockerComposeBuilder;
use Castor\Docker\Event\DockerComposeBuilderEvent;
use Castor\Docker\Event\DockerComposeWriteEvent;
use Castor\Docker\Event\RegisterServiceEvent;
use Castor\Docker\Service\BinaryRunService;
use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\ClickhouseService;
use Castor\Docker\Service\ElasticsearchService;
use Castor\Docker\Service\GoBuilder;
use Castor\Docker\Service\MariaDBService;
use Castor\Docker\Service\MySQLService;
use Castor\Docker\Service\PhpMode;
use Castor\Docker\Service\PostgresService;
use Castor\Docker\Service\RabbitMQService;
use Castor\Docker\Service\RedirectionioAgentService;
use Castor\Docker\Service\RedisService;
use Castor\Docker\Service\RustBuilder;
use Castor\Docker\Service\SymfonyService;

#[AsContext(default: true)]
function default_context(): Context
{
    return new Context([
        'root_domain' => 'project.test',
        'registry' => 'ghcr.io/castor-php/docker-example'
    ]);
}

#[AsListener(RegisterServiceEvent::class)]
function register_service(RegisterServiceEvent $event)
{
    $postgresService = new PostgresService();
    $event->addService($postgresService);

    $mysqlService = new MySQLService();
    $event->addService($mysqlService);

    // This directory is the root of a small monorepo: two PHP applications, a
    // Rust binary and a Go one, all living under one root. Every container
    // therefore mounts __DIR__ rather than its own sub-directory — which is what
    // lets one application read what another produces — and each service names
    // the sub-directory it works in.
    $app1Service = (new SymfonyService('app1'))
        ->withDirectory(__DIR__)
        ->withWorkingDirectory('app1')
        ->withMode(PhpMode::FrankenPhp)
        ->withDatabaseService($postgresService)
        ->addExtension('amqp')
        ->addExtension('redis')
        ->withDomain('app1.project.test', 'project.test', 'localhost')
        ->withHttpAccess()
        ->addWorker('messenger', 'php -d memory_limit=1G bin/console messenger:consume async --time-limit=3600 --memory-limit=128M')
    ;
    $event->addService($app1Service);

    // app2 declares no domain: it is served through the redirection.io agent,
    // which holds the domain and proxies the traffic to it.
    //
    // It also generates no "app2-builder" container: "castor app2:composer",
    // "castor app2:symfony" and the other builder tasks run in "app1-builder",
    // with the working directory set to app2. Three applications of a monorepo
    // do not need three identical builder containers.
    $app2Service = (new SymfonyService('app2'))
        ->withDirectory(__DIR__)
        ->withWorkingDirectory('app2')
        ->withSharedBuilder($app1Service)
        ->withVersion('8.2')
        ->withMode(PhpMode::Fpm)
        ->withDatabaseService($mysqlService)
        ->addExtension('amqp')
        ->addExtension('mysql')
    ;
    $event->addService($app2Service);

    $event->addService(new RabbitMQService());
    $event->addService(new RedisService());
    $event->addService(new ElasticsearchService());
    $event->addService(
        (new RedirectionioAgentService())
            ->addReverseProxy('app2.project.test', $app2Service, 'b02088e2-ef87-4622-8e5e-35d7f553ca9f:707268c4-1e23-4df2-a3d9-1c088e944652')
    );
    $event->addService((new ClickhouseService())->withVersion('25.8'));

    // One compiler container per language, each declaring the applications it
    // builds. The tasks are named after the applications, not after the
    // builder: "castor app3:build" runs "go build" in "go-builder", in
    // /app/go-app.
    $goBuilder = (new GoBuilder('go-builder'))
        ->withVersion('1.25')
        ->withDirectory(__DIR__)
        ->withApp('go-app', 'app3')
    ;
    $event->addService($goBuilder);

    $rustBuilder = (new RustBuilder('rust-builder'))
        ->withVersion('1.90')
        ->withDirectory(__DIR__)
        ->withApp('rust-app', 'app4')
    ;
    $event->addService($rustBuilder);

    // One runtime container per binary. BinaryRunService is language-agnostic:
    // it runs a compiled binary and nothing else. Attaching the builder is what
    // gives it "app3:build" and "app3:watch", and makes it run the image the
    // binary was compiled in — a binary linked against the toolchain's glibc
    // will not start in an unrelated slim image.
    $event->addService(
        (new BinaryRunService('app3', 'go-app/app3'))
            ->withBuilder($goBuilder, 'app3')
            ->withPort(80)
            ->withDomain('app3.project.test')
    );

    $event->addService(
        (new BinaryRunService('app4', 'rust-app/target/debug/app4'))
            ->withBuilder($rustBuilder, 'app4')
            ->withDomain('app4.project.test')
            // A container calling another one through its *public* domain: the
            // router is global and not on the project network, so nothing would
            // resolve this on its own. The plugin generates an extra_hosts entry
            // for every domain of the project, pointing at the host gateway
            // where the router answers — so this URL is the same here, in your
            // browser and in production.
            ->withEnvironment('APP1_URL', 'https://app1.project.test')
    );
}

// The three ways to reach the generated compose file, beyond registering a
// service. See https://castor-php.github.io/docker/going-further/extending-the-compose-file/

/**
 * Add a container the plugin has no service for. Adminer is a plain image with
 * nothing to configure, which is exactly the case this attribute is for: no
 * ServiceInterface to write, no event class to import.
 */
#[AsDockerComposeBuilder]
function add_adminer(ComposeBuilder $builder, Context $context): void
{
    $rootDomain = $context->data['root_domain'] ?? 'castor.local';

    $builder
        ->service('adminer')
            ->image('adminer:5')
            ->withHttpRouting("adminer.{$rootDomain}", 8080)
            ->profile('default')
        ->end()
    ;
}

/**
 * Change a service registered by someone else. Elasticsearch sizes its heap
 * from the host memory by default, which is generous for a development
 * machine running a dozen other containers.
 */
#[AsListener(DockerComposeBuilderEvent::class)]
function cap_elasticsearch_heap(DockerComposeBuilderEvent $event): void
{
    $event->builder
        ->service('elasticsearch')
            ->environment('ES_JAVA_OPTS', '-Xms512m -Xmx512m')
        ->end()
    ;
}

/**
 * Reach a compose key the builder does not model. "deploy" has no builder
 * method, and does not need one: this event carries the final array.
 */
#[AsListener(DockerComposeWriteEvent::class)]
function limit_elasticsearch_memory(DockerComposeWriteEvent $event): void
{
    $event->compose['services']['elasticsearch']['deploy']['resources']['limits']['memory'] = '1g';
}
