<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Behaviour;

use Castor\Context;
use Castor\Docker\Service\LinkableServiceInterface;
use Castor\Docker\Service\LinkAwareServiceInterface;
use Castor\Docker\Service\Builder\ServiceBuilder;

/**
 * Each link hands the variables of the linked service to every container of
 * this one, and makes them wait for it.
 *
 *     (new SymfonyService('app'))
 *         ->link($postgres)       // DATABASE_URL
 *         ->link($meilisearch)    // MEILISEARCH_URL, MEILISEARCH_API_KEY, …
 */
trait HasLinks
{
    /** @var array<string, LinkableServiceInterface> */
    protected array $links = [];

    public function link(LinkableServiceInterface $service): static
    {
        $this->links[$service->getName()] = $service;

        if ($service instanceof LinkAwareServiceInterface) {
            $service->linkedFrom($this);
        }

        return $this;
    }

    /**
     * @return array<string, LinkableServiceInterface>
     */
    public function getLinks(): array
    {
        return $this->links;
    }

    protected function unlink(LinkableServiceInterface $service): void
    {
        unset($this->links[$service->getName()]);
    }

    /**
     * Two links handing over the same variable — two databases, two
     * DATABASE_URL — is refused rather than won by whichever came last.
     *
     * @return array<string, string>
     */
    protected function getLinkedEnvironment(Context $context): array
    {
        $environment = [];
        $providers = [];

        foreach ($this->links as $service) {
            foreach ($service->getLinkEnvironment($context) as $key => $value) {
                if (isset($providers[$key])) {
                    throw new \LogicException(\sprintf(
                        'The "%s" service is linked to both "%s" and "%s", which both provide the %s variable. Link only one of them.',
                        $this->getName(),
                        $providers[$key],
                        $service->getName(),
                        $key,
                    ));
                }

                $providers[$key] = $service->getName();
                $environment[$key] = $value;
            }
        }

        return $environment;
    }

    /**
     * Every container of this service: an application, its builder and its
     * workers talk to the same database.
     */
    protected function applyLinks(Context $context, ?ServiceBuilder ...$containers): void
    {
        // Resolved first, so a conflict is reported whatever the containers.
        $this->getLinkedEnvironment($context);

        foreach ($containers as $container) {
            if (null === $container) {
                continue;
            }

            foreach ($this->links as $service) {
                foreach ($service->getLinkDependencies() as $dependency => $condition) {
                    $container->dependsOn($dependency, ['condition' => $condition]);
                }

                foreach ($service->getLinkEnvironment($context) as $key => $value) {
                    $container->environment($key, $value);
                }
            }
        }
    }

    abstract public function getName(): string;
}
