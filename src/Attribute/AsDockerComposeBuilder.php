<?php

declare(strict_types=1);

namespace Castor\Docker\Attribute;

/**
 * Marks a function contributing to the generated docker compose configuration.
 *
 * The function receives the ComposeBuilder, and optionally the Context:
 *
 *     #[AsDockerComposeBuilder]
 *     function add_my_service(ComposeBuilder $builder): void
 *     {
 *         $builder->service('my_service')->image('my_image');
 *     }
 *
 * Sugar over listening to DockerComposeBuilderEvent: same phase, same builder,
 * one less indirection. Use the event directly when you need to replace the
 * builder wholesale, or stop propagation.
 *
 * The functions marked with it run after the DockerComposeBuilderEvent
 * listeners, ordered by descending priority among themselves.
 */
#[\Attribute(\Attribute::TARGET_FUNCTION)]
final class AsDockerComposeBuilder
{
    public function __construct(
        public readonly int $priority = 0,
    ) {}
}
