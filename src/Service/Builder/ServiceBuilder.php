<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Builder;

final class ServiceBuilder
{
    /** @var array<string, string> */
    private array $environment = [];

    /** @var array<string> */
    private array $volumes = [];

    private ?string $image = null;

    /** @var ?array<string, mixed> */
    private ?array $healthcheck = null;

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

    private ?BuildBuilder $build = null;

    /** @var null|array<string>|string */
    private array|string|null $command = null;

    public function __construct(
        public readonly string $name,
        private readonly ComposeBuilder $composeBuilder,
    ) {}

    public function image(string $image): self
    {
        $this->image = $image;

        return $this;
    }

    public function environment(string $key, string $value): self
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
     * @param string|array<string> $domain
     */
    public function withTraefikRouting(string $serviceName, string|array $domain, ?int $port = null, bool $allowHttpAccess = false): self
    {
        if (\is_array($domain)) {
            $rule =  'Host(`' . implode('`) || Host(`', $domain) . '`)';
        } else {
            $rule = "Host(`{$domain}`)";
        }

        $this->label('traefik.enable', 'true');
        $this->label("traefik.http.routers.{$serviceName}.rule", $rule);
        $this->label("traefik.http.routers.{$serviceName}.tls", 'true');

        if ($port !== null) {
            $this->label("traefik.http.services.{$serviceName}.loadbalancer.server.port", (string) $port);
        }

        if (!$allowHttpAccess) {
            $this->label("traefik.http.routers.{$serviceName}-unsecure.rule", $rule);
            $this->label("traefik.http.routers.{$serviceName}-unsecure.entrypoints", 'http');
            $this->label("traefik.http.routers.{$serviceName}-unsecure.middlewares", 'redirect-to-https@file');
        } else {
            $this->label("traefik.http.routers.{$serviceName}.entrypoints", 'http,https');
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

    public function end(): ComposeBuilder
    {
        return $this->composeBuilder;
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

        if ($this->user !== null) {
            $result['user'] = $this->user;
        }

        if ($this->init !== null) {
            $result['init'] = $this->init;
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

        if (!empty($this->labels)) {
            $result['labels'] = $this->labels;
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

        return $result;
    }
}
