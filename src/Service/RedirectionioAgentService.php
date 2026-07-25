<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Context;
use Castor\Docker\Service\Behaviour\HasHttpRouting;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\yaml_dump;

/**
 * redirection.io agent (v3) running as a reverse proxy in front of the
 * applications, instead of as a sidecar for the nginx/apache modules.
 *
 * Traffic flows router -> agent -> application: the domain is declared on the
 * agent rather than on the application, so the application itself no longer
 * needs to be routed by Caddy:
 *
 *     $app = (new SymfonyService('app'))->withDirectory(__DIR__ . '/app');
 *
 *     (new RedirectionioAgentService())
 *         ->addReverseProxy('app.project.test', $app, 'my-project-key');
 *
 * A single agent handles as many domains as needed, each with its own
 * redirection.io project key.
 */
class RedirectionioAgentService implements ServiceInterface
{
    use HasHttpRouting;
    private const CONFIG_NAME = 'redirectionio-agent';
    private const CONFIG_PATH = '/etc/redirectionio/agent.yml';

    /** @var list<array{domain: string, target: string, port: int, projectKey: ?string}> */
    private array $reverseProxies = [];

    /** Project key used for the domains registered without an explicit one. */
    private ?string $projectKey = null;

    private string $instanceName = 'dev';

    public function getName(): string
    {
        return 'redirectionio-agent';
    }

    public function withProjectKey(string $projectKey): static
    {
        $this->projectKey = $projectKey;

        return $this;
    }

    public function withInstanceName(string $instanceName): static
    {
        $this->instanceName = $instanceName;

        return $this;
    }

    /**
     * Serve $domain through the agent and forward the traffic to $target, which
     * is either a service instance or a service name.
     */
    public function addReverseProxy(string $domain, ServiceInterface|string $target, ?string $projectKey = null, int $port = 80): static
    {
        $this->reverseProxies[] = [
            'domain' => $domain,
            'target' => $target instanceof ServiceInterface ? $target->getName() : $target,
            'port' => $port,
            'projectKey' => $projectKey,
        ];

        return $this->withDomain($domain);
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $builder->config(self::CONFIG_NAME, $this->generateConfiguration());

        $service = $builder
            ->service($this->getName())
                ->build(__DIR__ . '/../Resources/redirectionio-agent')->end()
                ->config(self::CONFIG_NAME, self::CONFIG_PATH)
                ->profile('default')
        ;

        $this->applyHttpRouting($service);

        return $builder;
    }

    public function getTasks(): iterable
    {
        return [];
    }

    /**
     * Build the agent.yml served to the container as a compose config.
     */
    private function generateConfiguration(): string
    {
        $virtualHosts = [];

        foreach ($this->reverseProxies as $reverseProxy) {
            $virtualHost = [
                'domains' => [$reverseProxy['domain']],
                'forward' => [
                    'address' => \sprintf('%s:%d', $reverseProxy['target'], $reverseProxy['port']),
                    // The applications are reached over the Docker network, in
                    // plain HTTP: TLS is terminated by the router. Note the
                    // scalar form is required here, the documented
                    // "tls: { enabled: false }" map is ignored by the agent.
                    'tls' => false,
                ],
            ];

            // Resolved here and not in addReverseProxy(), so the fallback key
            // can be set with withProjectKey() at any point.
            $projectKey = $reverseProxy['projectKey'] ?? $this->projectKey;

            if ($projectKey !== null) {
                $virtualHost['agent'] = ['project_key' => $projectKey];
            }

            $virtualHosts[] = $virtualHost;
        }

        $configuration = [
            'instance' => [
                'name' => $this->instanceName,
                // Rules and certificates are refetched on boot: nothing worth
                // persisting for a development environment.
                'persist' => false,
            ],
            'reverse_proxy' => [
                'listen' => ['tcp://0.0.0.0:80'],
                // The router sits in front of the agent and sets the legacy
                // X-Forwarded-* headers, which the agent ignores by default.
                'trusted_proxies' => [
                    'forwarded' => true,
                    'x_forwarded_for' => true,
                    'x_forwarded_host' => true,
                    'x_forwarded_proto' => true,
                ],
            ],
        ];

        if ($virtualHosts) {
            $configuration['reverse_proxy']['virtual_hosts'] = $virtualHosts;
        }

        return "# This file is generated by Castor. Do not edit it manually.\n" . yaml_dump($configuration, inline: 6);
    }
}
