<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Context;

/**
 * A service another one can be linked to, with link(): what the linked service
 * receives is a set of environment variables and the containers it waits for.
 *
 * Compose has nothing that hands the outputs of one service to another under
 * the names an application expects — its provider services prefix every
 * variable with the name of the provider — so the plugin does it: it knows the
 * values when it generates the file.
 *
 *     $event->addService($meilisearch = new MeilisearchService());
 *     $event->addService((new SymfonyService('app'))->link($meilisearch));
 */
interface LinkableServiceInterface extends ServiceInterface
{
    /**
     * The variables a service linked to this one receives.
     *
     * The context is the one the compose file is generated with: a public URL
     * depends on the root domain, which a git worktree prefixes.
     *
     * @return array<string, string>
     */
    public function getLinkEnvironment(Context $context): array;

    /**
     * What a service linked to this one waits for before starting: the compose
     * service, and the depends_on condition — "service_started",
     * "service_healthy" or "service_completed_successfully".
     *
     * @return array<string, string>
     */
    public function getLinkDependencies(): array;
}
