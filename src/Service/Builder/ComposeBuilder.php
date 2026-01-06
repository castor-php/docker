<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Builder;

final class ComposeBuilder
{
    /** @var ServiceBuilder[]  */
    private array $services = [];

    /** @var array<string, array<mixed>>  */
    private array $volumes = [];

    public function __construct() {}

    /**
     * @param array<mixed> $config
     */
    public function volume(string $name, array $config = []): self
    {
        $this->volumes[$name] = $config;

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

        return $compose;
    }
}
