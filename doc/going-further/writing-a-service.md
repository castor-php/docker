---
title: Writing your own service
description: Implement ServiceInterface, reuse the behaviour traits and ship an installer.
---

# Writing your own service

A service is a class implementing `ServiceInterface`: it describes containers
and contributes tasks. Nothing else is required to register it.

```php
interface ServiceInterface
{
    public function getName(): string;

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder;

    /** @return iterable<array{'task': AsTask, 'function': \Closure}> */
    public function getTasks(): iterable;
}
```

## A minimal service

```php
namespace project\Service;

use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasHttpRouting;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\ServiceInterface;

use function Castor\Docker\docker_compose;

final class MinioService implements ServiceInterface
{
    use HasHttpRouting;
    use HasVersion;

    protected function getDefaultVersion(): string
    {
        return 'latest';
    }

    protected function getDefaultPort(): int
    {
        return 9001;
    }

    public function getName(): string
    {
        return 'minio';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $service = $builder
            ->volume('minio-data')
            ->service($this->getName())
                ->image('minio/minio:' . $this->getVersion())
                ->command('server /data --console-address :9001')
                ->volume('minio-data', '/data')
                ->profile('default')
        ;

        $this->applyHttpRouting($service);

        return $builder;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('restart', $this->getName(), 'Restart MinIO'),
            'function' => fn() => docker_compose(['restart', $this->getName()]),
        ];
    }
}
```

Register it like any other service:

```php
$event->addService((new MinioService())->withDomain('minio.project.test'));
```

## Reusing the behaviour traits

The traits in `Castor\Docker\Service\Behaviour` give you the same fluent API as
the built-in services — see [configuring
services](../getting-started/configuring-services.md) for the full list.
`HasHttpRouting` is the one that saves the most work: it holds the domains, the
HTTP access flag and the port, and `applyHttpRouting()` emits the router labels
for you.

## The compose builders

`ComposeBuilder` and `ServiceBuilder` write the compose file for you:

```php
$builder
    ->volume('data')                       // a named volume
    ->config('my-config', $yamlContent)    // an inline compose config
    ->service('name')
        ->image('some/image:tag')
        ->build($contextPath)              // ... or a build section
            ->dockerfile($path)
            ->target('frontend')
            ->arg('key', 'value')
            ->withRegistryCache('name')
        ->end()
        ->environment('KEY', 'value')
        ->volume('data', '/var/lib/data')
        ->config('my-config', '/etc/app/config.yml')
        ->healthcheck(['CMD', 'curl', '-f', 'http://localhost/health'])
        ->dependsOn('postgres', ['condition' => 'service_healthy'])
        ->command('some command')
        ->user('1000:1000')
        ->profile('default')
        ->withHttpRouting(['app.test'], 80)
    ->end()
;
```

Inline configs are handy when a container needs a configuration file computed
from PHP: the content lands in `compose.generated.yaml` and is mounted at the
path you give, with no image rebuild. That is how the
[redirection.io agent](../services/redirectionio.md) gets its `agent.yml`.

## Shipping an installer

Implement `ServiceInstaller` — or extend `AbstractServiceInstaller` for the
no-op defaults — and register it on `RegisterServiceInstallerEvent` to make your
service available to `castor docker:service:install`:

```php
#[AsListener(RegisterServiceInstallerEvent::class)]
function register_installers(RegisterServiceInstallerEvent $event): void
{
    $event->addInstaller(new MinioInstaller());
}
```

```php
final class MinioInstaller extends AbstractServiceInstaller
{
    public function getName(): string
    {
        return 'minio';
    }

    public function getDescription(): string
    {
        return 'MinIO object storage';
    }

    public function getInputs(): array
    {
        return [
            new Input('version', 'MinIO version', InputType::Text, 'latest'),
        ];
    }

    public function buildStatements(ServiceStatementBuilder $builder, array $answers): void
    {
        $builder->addNewServiceAst(MinioService::class)
            ->callMethod('withVersion', [(string) $answers['version']]);
    }

    public function createInstance(array $answers): ServiceInterface
    {
        return (new MinioService())->withVersion((string) $answers['version']);
    }
}
```

`buildStatements()` describes, as an AST, the code to write in the user's
listener — the rewrite preserves the rest of the file. `createInstance()`
returns the live instance used to regenerate the compose file in the same run.
Three optional hooks complete the flow: `prepare()` before the build,
`scaffold()` between build and up, and `postUp()` after the containers start.
