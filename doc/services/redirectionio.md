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
        // ->addReverseProxy(string $domain, ServiceInterface|string $target, ?string $projectKey = null, int $port = 80)
        ->addReverseProxy('app.project.test', $app)
        ->addReverseProxy('legacy.project.test', $app, 'another-project-key')
        ->addReverseProxy('api.project.test', $api, port: 8080)
);
```

`$target` accepts a service instance or a plain service name. The port is the
one the target listens on inside the Docker network.

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
