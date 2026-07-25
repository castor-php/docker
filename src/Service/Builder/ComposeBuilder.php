<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Builder;

final class ComposeBuilder
{
    /** @var ServiceBuilder[]  */
    private array $services = [];

    /** @var array<string, array<mixed>>  */
    private array $volumes = [];

    /** @var array<string, string>  */
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
     */
    public function config(string $name, string $content): self
    {
        $this->configs[$name] = $content;

        return $this;
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
        $compose = [
            'services' => [],
            'volumes' => $this->volumes,
        ];

        foreach ($this->services as $name => $serviceBuilder) {
            $compose['services'][$name] = $serviceBuilder->toArray();
        }

        foreach ($this->configs as $name => $content) {
            $compose['configs'][$name] = ['content' => $content];
        }

        return $compose;
    }
}
