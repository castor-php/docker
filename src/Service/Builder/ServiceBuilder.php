<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Builder;

final class ServiceBuilder
{
    /** @var array<string, ?string> */
    private array $environment = [];

    /** @var array<string> */
    private array $volumes = [];

    private ?string $image = null;

    /** @var ?array<string, mixed> */
    private ?array $healthcheck = null;

    private ?string $workingDir = null;

    /** @var array<string> */
    private array $labels = [];

    /** @var array<string> */
    private array $profiles = [];

    /** @var array<string, array<mixed>> */
    private array $dependsOn = [];

    private ?string $user = null;

    private ?bool $init = null;

    /** @var array<string> */
    private array $ports = [];

    /** @var array<array<string, string>> */
    private array $configs = [];

    /**
     * The mounted configs whose content is digested into a label, so a change
     * makes compose recreate the container.
     *
     * @var list<string>
     */
    private array $watchedConfigs = [];

    private ?BuildBuilder $build = null;

    /** @var null|array<string>|string */
    private array|string|null $command = null;

    private ?string $restart = null;

    /** @var array<string, array<string, int>|int> */
    private array $ulimits = [];

    /** @var list<string> */
    private array $dns = [];

    /** @var list<string> */
    private array $extraHosts = [];

    /** @var array<string, mixed> */
    private array $deploy = [];

    /**
     * The domains routed to this service, remembered so the generator can make
     * them resolvable from inside the containers (see add_project_extra_hosts()).
     *
     * @var list<string>
     */
    private array $routedDomains = [];

    public function __construct(
        public readonly string $name,
        private readonly ComposeBuilder $composeBuilder,
    ) {}

    public function image(string $image): self
    {
        $this->image = $image;

        return $this;
    }

    /**
     * A null value emits "KEY: null", the compose syntax passing the variable
     * through from the environment castor runs in — which is how a value that
     * changes between invocations stays out of the generated file.
     */
    public function environment(string $key, ?string $value = null): self
    {
        $this->environment[$key] = $value;

        return $this;
    }

    public function volume(string $source, string $target, ?string $mode = null): self
    {
        $volume = "$source:$target";
        if ($mode !== null) {
            $volume .= ":$mode";
        }
        $this->volumes[] = $volume;

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getVolumes(): array
    {
        return $this->volumes;
    }

    public function label(string $key, string $value): self
    {
        $this->labels[] = "$key=$value";

        return $this;
    }

    public function profile(string $profile): self
    {
        $this->profiles[] = $profile;

        return $this;
    }

    /**
     * @param array<mixed> $config
     */
    public function dependsOn(string $serviceName, array $config = []): self
    {
        $this->dependsOn[$serviceName] = $config;

        return $this;
    }

    /**
     * @param array<string>|string $command
     */
    public function healthcheck(array|string $command, string $interval = '5s', string $timeout = '5s', int $retries = 5): self
    {
        $this->healthcheck = [
            'test' => $command,
            'interval' => $interval,
            'timeout' => $timeout,
            'retries' => $retries,
        ];

        return $this;
    }

    public function user(string $user, ?string $group = null): self
    {
        $this->user = $user;

        if ($group !== null) {
            $this->user .= ":$group";
        }

        return $this;
    }

    public function init(bool $init = true): self
    {
        $this->init = $init;

        return $this;
    }

    /**
     * @param array<string>|string|null $command
     */
    public function command(array|string|null $command): self
    {
        $this->command = $command;

        return $this;
    }

    /**
     * Expose the service over HTTP/HTTPS through the Caddy router
     * (caddy-docker-proxy) by emitting the matching Docker labels.
     *
     * The port is required. Left out, caddy-docker-proxy resolves "{{upstreams}}"
     * against whatever the image happens to expose — the first of several, or
     * port 80 when it exposes nothing — which routes to the wrong one silently
     * and answers 502. Naming it is the only way to be sure.
     *
     * @param string|array<string> $domain
     * @param int                  $port   the port the service listens on inside the container
     */
    public function withHttpRouting(string|array $domain, int $port, bool $allowHttpAccess = false): self
    {
        $domains = \is_array($domain) ? $domain : [$domain];
        $upstream = \sprintf('{{upstreams %d}}', $port);

        foreach ($domains as $routedDomain) {
            if (!\in_array($routedDomain, $this->routedDomains, true)) {
                $this->routedDomains[] = $routedDomain;
            }
        }

        // HTTPS site served with a locally-trusted certificate minted on demand
        // by the Caddy router (see CaddyRouterService). Plain HTTP is redirected
        // to HTTPS automatically by Caddy.
        $this->label('caddy', implode(' ', $domains));
        $this->label('caddy.reverse_proxy', $upstream);
        $this->label('caddy.tls', 'internal');
        $this->label('caddy.tls.on_demand', '');

        if ($allowHttpAccess) {
            // Additionally serve the same upstream over plain HTTP, without the
            // automatic redirect to HTTPS.
            $httpDomains = array_map(static fn(string $d): string => "http://{$d}", $domains);
            $this->label('caddy_1', implode(' ', $httpDomains));
            $this->label('caddy_1.reverse_proxy', $upstream);
        }

        return $this;
    }

    public function build(string|BuildBuilder|null $build = null): BuildBuilder
    {
        if ($build instanceof BuildBuilder) {
            $this->build = $build->clone($this);

            return $this->build;
        }

        if ($this->build === null) {
            $this->build = new BuildBuilder($this);
        }

        if (null !== $build) {
            $this->build->context($build);
        }

        return $this->build;
    }

    public function port(int|string $hostPort, int|string $containerPort): self
    {
        $this->ports[] = "{$hostPort}:{$containerPort}";

        return $this;
    }

    /**
     * Mount a config declared with ComposeBuilder::config() at the given path.
     *
     * Compose does not recreate a container when only the content of an inline
     * config changed, so a server that reads its configuration once, at boot,
     * would keep running with the old one until someone thinks of
     * "--force-recreate". Pass $recreateOnChange to stamp a digest of the
     * content in a label: the container definition then changes with the
     * configuration, and "docker:up" is enough.
     *
     * Leave it off for a service that reloads its configuration by itself —
     * the digest would restart it for nothing.
     */
    public function config(string $source, string $target, bool $recreateOnChange = false): self
    {
        $this->configs[] = ['source' => $source, 'target' => $target];

        if ($recreateOnChange) {
            $this->watchedConfigs[] = $source;
        }

        return $this;
    }

    public function workingDir(string $workingDir): self
    {
        $this->workingDir = $workingDir;

        return $this;
    }

    /**
     * The restart policy: "no", "always", "on-failure", "on-failure:10" or
     * "unless-stopped".
     */
    public function restart(string $policy): self
    {
        $this->restart = $policy;

        return $this;
    }

    /**
     * Set a resource limit, either as a single value ("nproc") or as a
     * soft/hard pair ("nofile").
     *
     * @param array<string, int>|int $limit
     */
    public function ulimits(string $name, array|int $limit): self
    {
        $this->ulimits[$name] = $limit;

        return $this;
    }

    public function dns(string ...$servers): self
    {
        foreach ($servers as $server) {
            if (!\in_array($server, $this->dns, true)) {
                $this->dns[] = $server;
            }
        }

        return $this;
    }

    /**
     * Add a host to /etc/hosts inside the container. "host-gateway" resolves to
     * the host itself, on Linux as well as on Docker Desktop.
     */
    public function extraHost(string $host, string $ip): self
    {
        $entry = "{$host}:{$ip}";

        if (!\in_array($entry, $this->extraHosts, true)) {
            $this->extraHosts[] = $entry;
        }

        return $this;
    }

    /**
     * The "deploy" section, merged with what was already set. Compose only
     * honours a subset of it outside of Swarm — resource limits and
     * reservations, which is how a GPU is requested.
     *
     * @param array<string, mixed> $deploy
     */
    public function deploy(array $deploy): self
    {
        $this->deploy = array_replace_recursive($this->deploy, $deploy);

        return $this;
    }

    /**
     * The domains routed to this service by withHttpRouting().
     *
     * @return list<string>
     */
    public function getRoutedDomains(): array
    {
        return $this->routedDomains;
    }

    public function end(): ComposeBuilder
    {
        return $this->composeBuilder;
    }

    /**
     * A digest of every config mounted with $recreateOnChange, so the container
     * definition changes when the configuration does.
     *
     * @return list<string>
     */
    private function configChecksumLabels(): array
    {
        $labels = [];

        foreach ($this->watchedConfigs as $name) {
            $content = $this->composeBuilder->getConfigContent($name);

            if (null === $content) {
                continue;
            }

            $labels[] = \sprintf('castor.config.%s=%s', $name, substr(hash('xxh128', $content), 0, 12));
        }

        return $labels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        if ($this->image !== null) {
            $result['image'] = $this->image;
        }

        if ($this->build !== null) {
            $result['build'] = $this->build->toArray();
        }

        if ($this->workingDir !== null) {
            $result['working_dir'] = $this->workingDir;
        }

        if ($this->user !== null) {
            $result['user'] = $this->user;
        }

        if ($this->init !== null) {
            $result['init'] = $this->init;
        }

        if ($this->restart !== null) {
            $result['restart'] = $this->restart;
        }

        if (!empty($this->environment)) {
            $result['environment'] = $this->environment;
        }

        if (!empty($this->dependsOn)) {
            $result['depends_on'] = $this->dependsOn;
        }

        if (!empty($this->volumes)) {
            $result['volumes'] = $this->volumes;
        }

        if ($this->healthcheck !== null) {
            $result['healthcheck'] = $this->healthcheck;
        }

        $labels = [...$this->labels, ...$this->configChecksumLabels()];

        if (!empty($labels)) {
            $result['labels'] = $labels;
        }

        if (!empty($this->profiles)) {
            $result['profiles'] = $this->profiles;
        }

        if ($this->command !== null) {
            $result['command'] = $this->command;
        }

        if (!empty($this->ports)) {
            $result['ports'] = $this->ports;
        }

        if (!empty($this->dns)) {
            $result['dns'] = $this->dns;
        }

        if (!empty($this->extraHosts)) {
            $result['extra_hosts'] = $this->extraHosts;
        }

        if (!empty($this->configs)) {
            $result['configs'] = $this->configs;
        }

        if (!empty($this->ulimits)) {
            $result['ulimits'] = $this->ulimits;
        }

        if (!empty($this->deploy)) {
            $result['deploy'] = $this->deploy;
        }

        return $result;
    }
}
