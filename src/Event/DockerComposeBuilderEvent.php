<?php

declare(strict_types=1);

namespace Castor\Docker\Event;

use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;

/**
 * Dispatched once every registered service has contributed to the compose
 * configuration, and before it is serialized.
 *
 * Listen to it to add, change or remove anything in the generated file with the
 * builder API — a service the project needs but no ServiceInterface provides, an
 * extra volume, a label on someone else's service:
 *
 *     #[AsListener(DockerComposeBuilderEvent::class)]
 *     function tweak_compose(DockerComposeBuilderEvent $event): void
 *     {
 *         $event->builder
 *             ->service('app')
 *                 ->environment('APP_DEBUG', '1')
 *             ->end()
 *         ;
 *     }
 *
 * It is dispatched before the bind-mounted directories are created, so a mount
 * added here still gets its host directory.
 *
 * For the keys the builder does not model, use DockerComposeWriteEvent, which
 * carries the final array instead.
 */
final class DockerComposeBuilderEvent
{
    public function __construct(
        public readonly Context $context,
        public ComposeBuilder $builder,
    ) {}
}
