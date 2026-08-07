<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Behaviour;

/**
 * For services naming themselves, so the same one can be registered twice.
 *
 * Infrastructure services used to hardcode their name, which made a second
 * instance impossible: the two would have collided on the compose service, on
 * the named volumes and on the routed domain. withName() overrides it, and
 * everything the service generates is derived from it — the default keeps
 * producing exactly what it produced before.
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
     * Whether this instance still carries the name the service ships with.
     *
     * Used where a generated name is not derived from the service one — the
     * Kibana container — so the historical name is kept for the first instance
     * and only a renamed one gets a derived name.
     */
    protected function hasDefaultName(): bool
    {
        return $this->getName() === $this->getDefaultName();
    }

    abstract protected function getDefaultName(): string;
}
