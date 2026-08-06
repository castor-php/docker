<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Behaviour;

use Castor\Docker\Service\Builder\ServiceBuilder;

/**
 * For services taking arbitrary environment variables from the project.
 */
trait HasEnvironment
{
    /** @var array<string, ?string> */
    protected array $environment = [];

    /**
     * A null value passes the variable through from the environment castor runs
     * in, instead of freezing it in the generated compose file.
     */
    public function withEnvironment(string $key, ?string $value = null): static
    {
        $this->environment[$key] = $value;

        return $this;
    }

    /**
     * @return array<string, ?string>
     */
    public function getEnvironment(): array
    {
        return $this->environment;
    }

    protected function applyEnvironment(ServiceBuilder $service): void
    {
        foreach ($this->environment as $key => $value) {
            $service->environment($key, $value);
        }
    }
}
