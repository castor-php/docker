<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Context;

/**
 * A service another one can be linked to with link(), handing it a set of
 * environment variables and the containers to wait for.
 *
 * Compose cannot do it — its provider services prefix every variable with the
 * name of the provider — so the plugin does, knowing the values as it generates
 * the file.
 *
 *     $event->addService($meilisearch = new MeilisearchService());
 *     $event->addService((new SymfonyService('app'))->link($meilisearch));
 */
interface LinkableServiceInterface extends ServiceInterface
{
    /**
     * The context is the one the compose file is generated with: a public URL
     * depends on the root domain, which a git worktree prefixes.
     *
     * @return array<string, string>
     */
    public function getLinkEnvironment(Context $context): array;

    /**
     * The compose service to wait for, and the depends_on condition.
     *
     * @return array<string, string>
     */
    public function getLinkDependencies(): array;
}
