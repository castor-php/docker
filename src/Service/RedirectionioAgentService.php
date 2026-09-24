<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Context;
use Castor\Docker\Service\Behaviour\HasHttpRouting;
use Castor\Docker\Service\Behaviour\HasName;
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
    use HasName;

    private const CONFIG_PATH = '/etc/redirectionio/agent.yml';

    /** @var list<array{domain: string, target: string, port: int, projectKey: ?string, preserveHost: ?bool}> */
    private array $reverseProxies = [];

    private bool $preserveHost = true;

    private bool $debug = false;

    /** Project key used for the domains registered without an explicit one. */
    private ?string $projectKey = null;

    private string $instanceName = 'dev';

    /** The redirection.io API the agent talks to, the SaaS one when unset. */
    private ?string $apiHost = null;

    private ?int $apiTimeout = null;

    private ?bool $testMode = null;

    private ?bool $logging = null;

    protected function getDefaultName(): string
    {
        return 'redirectionio-agent';
    }

    /**
     * A self-hosted instance, or the very project this agent runs in.
     */
    public function withApiHost(string $apiHost): static
    {
        $this->apiHost = $apiHost;

        return $this;
    }

    public function withApiTimeout(int $apiTimeout): static
    {
        $this->apiTimeout = $apiTimeout;

        return $this;
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
     * On by default. Turn it off to let the agent send the name of the service
     * it forwards to, which is what it does on its own.
     */
    public function withPreserveHost(bool $preserveHost = true): static
    {
        $this->preserveHost = $preserveHost;

        return $this;
    }

    public function withDebug(bool $debug = true): static
    {
        $this->debug = $debug;

        return $this;
    }

    public function withTestMode(bool $testMode = true): static
    {
        $this->testMode = $testMode;

        return $this;
    }

    public function withLogging(bool $logging = true): static
    {
        $this->logging = $logging;

        return $this;
    }

    /**
     * Serve $domain through the agent and forward the traffic to $target, a
     * service instance or a service name.
     *
     * An omitted port is read from the target service, so a Node dev server on
     * 3000 is forwarded to correctly without repeating it here. A service
     * *name* carries nothing to read and keeps the default of 80.
     */
    public function addReverseProxy(string $domain, ServiceInterface|string $target, ?string $projectKey = null, ?int $port = null, ?bool $preserveHost = null): static
    {
        $this->reverseProxies[] = [
            'domain' => $domain,
            'target' => $target instanceof ServiceInterface ? $target->getName() : $target,
            'port' => $port ?? $this->resolveTargetPort($target),
            'projectKey' => $projectKey,
            'preserveHost' => $preserveHost,
        ];

        return $this->withDomain($domain);
    }

    /**
     * getPort() comes from the HasHttpRouting behaviour every service routed
     * over HTTP uses. There is no interface for it, hence the check.
     */
    private function resolveTargetPort(ServiceInterface|string $target): int
    {
        if ($target instanceof ServiceInterface && method_exists($target, 'getPort')) {
            return $target->getPort();
        }

        return 80;
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        // Named after the service, so two agents do not overwrite each other's
        // agent.yml.
        $configName = $this->getName();

        $builder->config($configName, $this->generateConfiguration());

        $service = $builder
            ->service($this->getName())
                ->build(__DIR__ . '/../Resources/redirectionio-agent')->end()
                // The agent reads agent.yml once, on boot, so a change needs
                // the container recreated.
                ->config($configName, self::CONFIG_PATH, recreateOnChange: true)
                ->profile('default')
        ;

        if ($this->debug) {
            $service->command(['/usr/local/bin/redirectionio-agent', '--config', '/etc/redirectionio/agent.yml', '--debug']);
        }

        $this->applyHttpRouting($service);

        return $builder;
    }

    public function getTasks(): iterable
    {
        return [];
    }

    /**
     * The agent.yml served to the container as a compose config.
     */
    private function generateConfiguration(): string
    {
        $virtualHosts = [];

        foreach ($this->reverseProxies as $reverseProxy) {
            $virtualHost = [
                'domains' => [$reverseProxy['domain']],
                'forward' => [
                    'address' => \sprintf('%s:%d', $reverseProxy['target'], $reverseProxy['port']),
                    // Plain HTTP over the Docker network: TLS is terminated by
                    // the router. The scalar form is required, the documented
                    // "tls: { enabled: false }" map is ignored by the agent.
                    'tls' => false,
                    // The target is a compose service reached by name, and the
                    // agent would otherwise forward that name as the Host.
                    'preserve_host' => $reverseProxy['preserveHost'] ?? $this->preserveHost,
                ],
            ];

            // Not resolved in addReverseProxy(), so withProjectKey() can set
            // the fallback at any point.
            $projectKey = $reverseProxy['projectKey'] ?? $this->projectKey;

            if ($projectKey !== null) {
                $virtualHost['agent'] = ['project_key' => $projectKey];
            }

            $virtualHosts[] = $virtualHost;
        }

        $configuration = [
            'instance' => [
                'name' => $this->instanceName,
                'persist' => false,
            ],
            'reverse_proxy' => [
                'listen' => ['tcp://0.0.0.0:80'],
                // The router sets the legacy X-Forwarded-* headers, which the
                // agent ignores by default.
                'trusted_proxies' => [
                    'forwarded' => true,
                    'x_forwarded_for' => true,
                    'x_forwarded_host' => true,
                    'x_forwarded_proto' => true,
                ],
            ],
        ];

        if ($this->testMode !== null) {
            $configuration['instance']['test_mode'] = $this->testMode;
        }

        if ($this->logging !== null) {
            $configuration['instance']['logging'] = $this->logging;
        }

        if ($this->apiHost !== null) {
            $configuration['api'] = ['host' => $this->apiHost];

            if ($this->apiTimeout !== null) {
                $configuration['api']['timeout'] = $this->apiTimeout;
            }
        }

        if ($virtualHosts) {
            $configuration['reverse_proxy']['virtual_hosts'] = $virtualHosts;
        }

        return "# This file is generated by Castor. Do not edit it manually.\n" . yaml_dump($configuration, inline: 6);
    }
}
