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
     * The content is stored in the generated compose file and mounted in the
     * services referencing it with ServiceBuilder::config(), so a configuration
     * file can be generated from PHP without shipping it in an image.
     *
     * Compose interpolates the file it reads, configs included: an nginx
     * configuration full of $host and $uri would reach the container emptied of
     * them. Every "$" is therefore escaped to "$$" on the way out —
     * $interpolate opts a config that really means ${PROJECT_NAME} back in.
     */
    public function config(string $name, string $content, bool $interpolate = false): self
    {
        $this->configs[$name] = ['content' => $content, 'interpolate' => $interpolate];

        return $this;
    }

    /**
     * As it was passed, before escaping.
     */
    public function getConfigContent(string $name): ?string
    {
        return $this->configs[$name]['content'] ?? null;
    }

    /**
     * Named volumes excluded. The paths may be relative to the project.
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
     * In registration order.
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
        // The project stays on its own network, which the router joins from the
        // outside: a shared one would make the service names of different
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
