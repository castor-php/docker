---
title: redirection.io
description: Run the redirection.io agent as a reverse proxy in front of your applications.
---

# RedirectionioAgentService

[redirection.io](https://redirection.io/) agent (v3) running as a **reverse
proxy** in front of your applications, so it works with any of them —
FrankenPHP, PHP-FPM, Go, Rust, … — with no module to install in the application
image.

Traffic flows `router -> agent -> application`: the domain is declared on the
agent instead of on the application, which therefore does **not** call
`withDomain()`. One agent handles as many domains as needed, each with its own
project key.

## Configuration

```php
$app = (new SymfonyService('app'))->withDirectory(__DIR__ . '/app');
$api = (new RustService('api'))->withVersion('1.90')->withDirectory(__DIR__ . '/api');

$event->addService($app);
$event->addService($api);

$event->addService(
    (new RedirectionioAgentService())
        ->withProjectKey('default-project-key') // Used by the domains registered without an explicit key
        ->withInstanceName('dev')
        // ->addReverseProxy(string $domain, ServiceInterface|string $target, ?string $projectKey = null, ?int $port = null)
        ->addReverseProxy('app.project.test', $app)
        ->addReverseProxy('legacy.project.test', $app, 'another-project-key')
        ->addReverseProxy('api.project.test', $api)          // forwarded to api:8080, the port of the service
        ->addReverseProxy('front.project.test', 'front', port: 3000)
);
```

`$target` accepts a service instance or a plain service name. The port is the
one the target listens on inside the Docker network: given an **instance** it is
read from the service, so a Rust binary on 8080 or a Node dev server on 3000
needs nothing said here. Given a **name** it defaults to 80, since a name
carries nothing to read — pass `port:` yourself, or hand over the instance.

## The Host header your application receives

The agent decides what `Host` to forward from the address it forwards **to**: an
IP address keeps the one of the original request, a host name replaces it with
itself. Every target here is a compose service reached by name, so left alone
the agent would hand your application `Host: app` — which Symfony rejects as an
untrusted host, and which makes every absolute URL it generates wrong.

`preserve_host` is therefore written on every forward, and your application sees
the domain the visitor asked for. Turn it off if you want the agent's own
behaviour back:

```php
->withPreserveHost(false)                                  // for every domain
->addReverseProxy('legacy.project.test', $app, preserveHost: false)  // for one
```

## Debugging

```php
(new RedirectionioAgentService())->withDebug()
```

Raises the agent log level, and lets it accept a certificate it cannot verify
when calling its API — which is exactly what a self-hosted
[`withApiHost()`](#pointing-the-agent-at-a-self-hosted-api) served by the local
router hands it. The agent image ships the public CA bundle only, and runs on
`scratch`, so there is nowhere to add the local one.

Development only, as the name says.

## Test mode and traffic logs

```php
(new RedirectionioAgentService())
    ->withTestMode()        // instance.test_mode
    ->withLogging(false)    // instance.logging
```

Both write the matching key of the `instance` section of the generated
`agent.yml`, and neither is written unless you call it — the agent keeps its own
default otherwise.

## Applying a configuration change

The generated `agent.yml` is shipped as a compose config, and the agent reads it
once, on boot. Compose does not recreate a container when only the content of a
config changed, so the plugin stamps a digest of it in a
`castor.config.redirectionio-agent` label: the container definition changes with
the configuration, and `castor docker:up` is enough — no `--force-recreate`.

## Pointing the agent at a self-hosted API

The agent talks to the redirection.io SaaS by default. A self-hosted instance —
or the very project this agent runs in — is declared with:

```php
(new RedirectionioAgentService())
    ->withApiHost('https://api.myproject.test/app_dev.php')
    ->withApiTimeout(120)   // seconds, only written along with a host
```

which writes the `api` section of the generated `agent.yml`. Both are absent by
default, and the agent then uses its own defaults.

A URL like `https://api.myproject.test` resolves from inside the agent
container: the plugin makes the project's own domains reachable, see [router and
HTTPS](router.md#reaching-your-own-domains-from-inside-a-container).

## Generated configuration

The agent configuration is generated from those calls and shipped to the
container as an inline compose config, visible in `compose.generated.yaml` under
`configs:` — there is no `agent.yml` to maintain by hand:

```yaml
configs:
    redirectionio-agent:
        content: |
            instance:
                name: dev
                persist: false
            reverse_proxy:
                listen:
                    - 'tcp://0.0.0.0:80'
                trusted_proxies:
                    forwarded: true
                    x_forwarded_for: true
                    x_forwarded_host: true
                    x_forwarded_proto: true
                virtual_hosts:
                    -
                        domains:
                            - app.project.test
                        forward:
                            address: 'app:80'
                            tls: false
                        agent:
                            project_key: default-project-key
```

The router terminates TLS and sets the `X-Forwarded-*` headers, which the agent
is configured to trust, so your application still sees the original host and
scheme.

## Containers

* `redirectionio-agent` — the agent, routed by the router for every registered
  domain and forwarding to the target services
