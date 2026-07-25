<?php

declare(strict_types=1);

namespace Castor\Docker\Service\Behaviour;

/**
 * For services reachable on one or more domains through the router.
 */
trait HasDomains
{
    /** @var list<string> */
    private array $domains = [];

    public function withDomain(string ...$domains): static
    {
        foreach ($domains as $domain) {
            // A domain can only be served once, registering it twice would emit
            // a duplicated router label.
            if (!\in_array($domain, $this->domains, true)) {
                $this->domains[] = $domain;
            }
        }

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getDomains(): array
    {
        return $this->domains;
    }
}
