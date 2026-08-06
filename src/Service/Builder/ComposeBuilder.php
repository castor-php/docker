<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Builder;

final class ComposeBuilder
{
    /** @var ServiceBuilder[]  */
    private array $services = [];

    /** @var array<string, array<mixed>>  */
    private array $volumes = [];

    /** @var array<string, array{content: string, interpolate: bool}>  */
    private array $configs = [];

    public function __construct() {}

    /**
     * @param array<mixed> $config
     */
    public function volume(string $name, array $config = []): self
    {
        $this->volumes[$name] = $config;

        return $this;
    }

    /**
     * Declare an inline compose config: its content is stored in the generated
     * compose file and mounted in the services referencing it with
     * ServiceBuilder::config(), so a configuration file can be generated from
     * PHP without shipping it in an image.
     *
     * Compose interpolates the file it reads, content of the configs included:
     * an nginx configuration full of $host, $uri and $document_root would reach
     * the container emptied of them, with only a "variable is not set" warning
     * to show for it. Every "$" is therefore escaped to "$$" on the way out.
     * Pass $interpolate to opt a config back into interpolation, when it really
     * does mean to read ${PROJECT_NAME} & co.
     */
    public function config(string $name, string $content, bool $interpolate = false): self
    {
        $this->configs[$name] = ['content' => $content, 'interpolate' => $interpolate];

        return $this;
    }

    /**
     * The content of a declared config, as it was passed — before escaping.
     */
    public function getConfigContent(string $name): ?string
    {
        return $this->configs[$name]['content'] ?? null;
    }

    /**
     * The host paths bind-mounted by the services, named volumes excluded.
     * They may be relative to the project directory.
     *
     * @return list<string>
     */
    public function getBindMountSources(): array
    {
        $sources = [];

        foreach ($this->services as $service) {
            foreach ($service->getVolumes() as $volume) {
                $source = explode(':', $volume)[0];

                if ('' === $source || isset($this->volumes[$source])) {
                    continue;
                }

                $sources[$source] = true;
            }
        }

        return array_keys($sources);
    }

    /**
     * Every domain routed to a service of this project, in registration order.
     *
     * @return list<string>
     */
    public function getRoutedDomains(): array
    {
        $domains = [];

        foreach ($this->services as $service) {
            foreach ($service->getRoutedDomains() as $domain) {
                $domains[$domain] = true;
            }
        }

        return array_keys($domains);
    }

    /**
     * @return array<string, ServiceBuilder>
     */
    public function getServices(): array
    {
        return $this->services;
    }

    public function service(string $name): ServiceBuilder
    {
        if (!isset($this->services[$name])) {
            $this->services[$name] = new ServiceBuilder($name, $this);
        }

        return $this->services[$name];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        // The project stays on its own network: the global router joins it from
        // the outside (see connect_router_to_network()) instead of every project
        // joining a shared one, which would make service names of different
        // projects collide in the Docker DNS.
        $compose = [
            'services' => [],
            'volumes' => $this->volumes,
        ];

        foreach ($this->services as $name => $serviceBuilder) {
            $compose['services'][$name] = $serviceBuilder->toArray();
        }

        foreach ($this->configs as $name => $config) {
            $compose['configs'][$name] = [
                'content' => $config['interpolate'] ? $config['content'] : str_replace('$', '$$', $config['content']),
            ];
        }

        return $compose;
    }
}
