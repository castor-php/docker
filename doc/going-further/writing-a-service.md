---
title: Writing your own service
description: Implement ServiceInterface, reuse the behaviour traits and ship an installer.
---

# Writing your own service

A service is a class implementing `ServiceInterface`: it describes containers
and contributes tasks. Nothing else is required to register it.

Writing one is worth it for something reusable, configurable, or with tasks of
its own. To drop a plain container into a single project, or to change one that
is already registered, [extending the compose
file](extending-the-compose-file.md) is a lot less work.

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

[Gotenberg](https://gotenberg.dev) converts HTML and office documents to PDF
behind an HTTP API — a service the plugin does not ship:

```php
namespace project\Service;

use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Behaviour\HasHttpRouting;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;
use Castor\Docker\Service\ServiceInterface;

use function Castor\Docker\docker_compose;

final class GotenbergService implements ServiceInterface
{
    use HasHttpRouting;
    use HasVersion;

    protected function getDefaultVersion(): string
    {
        return '8';
    }

    protected function getDefaultPort(): int
    {
        return 3000;
    }

    public function getName(): string
    {
        return 'gotenberg';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $service = $builder
            ->service($this->getName())
                ->image('gotenberg/gotenberg:' . $this->getVersion())
                ->command('gotenberg --api-timeout=120s')
                ->healthcheck(['CMD', 'curl', '-f', 'http://localhost:3000/health'])
                ->profile('default')
        ;

        $this->applyHttpRouting($service);

        return $builder;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('restart', $this->getName(), 'Restart Gotenberg'),
            'function' => fn() => docker_compose(['restart', $this->getName()]),
        ];
    }
}
```

Register it like any other service:

```php
$event->addService((new GotenbergService())->withDomain('gotenberg.project.test'));
```

## Reusing the behaviour traits

The traits in `Castor\Docker\Service\Behaviour` give you the same fluent API as
the built-in services — see [configuring
services](../getting-started/configuring-services.md) for the full list.
`HasHttpRouting` is the one that saves the most work: it holds the domains, the
HTTP access flag and the port, and `applyHttpRouting()` emits the router labels
for you.

## Making it linkable

An application reaching Gotenberg needs its URL, and nothing else. Implement
`LinkableServiceInterface` and it can be [linked](../services/index.md#linking-services)
like the services the plugin ships:

```php
final class GotenbergService implements LinkableServiceInterface
{
    // …

    public function getLinkEnvironment(Context $context): array
    {
        // The name the Symfony bundle reads.
        return ['GOTENBERG_DSN' => 'http://' . $this->getName() . ':3000'];
    }

    public function getLinkDependencies(): array
    {
        return [$this->getName() => 'service_healthy'];
    }
}
```

```php
$event->addService($gotenberg = new GotenbergService());
$event->addService((new SymfonyService('app'))->link($gotenberg));
```

Name the variables after what the libraries read rather than after the service
— that is what lets an application use them with no configuration. The URL is
the one of the project network: the containers do not trust the certificates of
the router. The context is there for a public URL, which depends on the root
domain, and so on the git worktree.

`getLinkDependencies()` maps a compose service to the condition the linked
application waits for — `service_started`, `service_healthy`, or
`service_completed_successfully` for a one-shot container preparing something,
which is how the applications linked to the [object
storage](../services/object-storage.md) wait for their buckets. A service
needing to know who links to it — the Mercure hub, to allow their origins —
implements `LinkAwareServiceInterface` on top, and `link()` calls its
`linkedFrom()`.

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
        ->withHttpRouting(['console.app.test'], 9001)   // a second site, another port
    ->end()
;
```

Each `withHttpRouting()` is a site of its own: a container answering on two
ports — an API and its console — is served on two domains.

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
    $event->addInstaller(new GotenbergInstaller());
}
```

```php
final class GotenbergInstaller extends AbstractServiceInstaller
{
    public function getName(): string
    {
        return 'gotenberg';
    }

    public function getDescription(): string
    {
        return 'Gotenberg PDF conversion API';
    }

    public function getInputs(): array
    {
        return [
            new Input('version', 'Gotenberg version', InputType::Text, '8'),
        ];
    }

    public function buildStatements(ServiceStatementBuilder $builder, array $answers): void
    {
        $builder->addNewServiceAst(GotenbergService::class)
            ->callMethod('withVersion', [(string) $answers['version']]);
    }

    public function createInstance(array $answers): ServiceInterface
    {
        return (new GotenbergService())->withVersion((string) $answers['version']);
    }
}
```

An input of type `InputType::Choice` is answered with one of its `choices`.
Pass `multiple: true` and it is answered with several at once, as a
`list<string>` — the default preselecting them is a list too:

```php
new Input('extensions', 'Extensions to enable', InputType::Choice, ['redis'], ['redis', 'amqp', 'intl'], multiple: true);
```

`buildStatements()` describes, as an AST, the code to write in the user's
listener — the rewrite preserves the rest of the file. `createInstance()`
returns the live instance used to regenerate the compose file in the same run.
Three optional hooks complete the flow: `prepare()` before the build,
`scaffold()` between build and up, and `postUp()` after the containers start.

Each input is a question and an option of the install command at once, so the
one above installs with `castor docker:service:install gotenberg --with-version=8`
as well as by answering the prompt — nothing to declare for that.
