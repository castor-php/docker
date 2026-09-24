<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Behaviour;

/**
 * For services naming themselves, so the same one can be registered twice:
 * everything they generate — the compose service, the named volumes, the routed
 * domain — is derived from the name, and the default produces what a hardcoded
 * one used to.
 *
 *     $event->addService(new PostgresService());
 *     $event->addService((new PostgresService())->withName('analytics'));
 */
trait HasName
{
    protected ?string $name = null;

    public function withName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): string
    {
        return $this->name ?? $this->getDefaultName();
    }

    /**
     * Used where a generated name is not derived from the service one — the
     * Kibana container — so only a renamed instance gets a derived name.
     */
    protected function hasDefaultName(): bool
    {
        return $this->getName() === $this->getDefaultName();
    }

    abstract protected function getDefaultName(): string;
}
