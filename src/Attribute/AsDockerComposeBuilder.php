<?php

declare(strict_types=1);

namespace Castor\Docker\Attribute;

/**
 * Marks a function contributing to the generated docker compose configuration.
 * It receives the ComposeBuilder, and optionally the Context:
 *
 *     #[AsDockerComposeBuilder]
 *     function add_my_service(ComposeBuilder $builder): void
 *     {
 *         $builder->service('my_service')->image('my_image');
 *     }
 *
 * Sugar over listening to DockerComposeBuilderEvent — use the event itself to
 * replace the builder wholesale or to stop propagation. These functions run
 * after its listeners, ordered by descending priority among themselves.
 */
#[\Attribute(\Attribute::TARGET_FUNCTION)]
final class AsDockerComposeBuilder
{
    public function __construct(
        public readonly int $priority = 0,
    ) {}
}
