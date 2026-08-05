<?php

declare(strict_types=1);

namespace Castor\Docker\Event;

use Castor\Context;

/**
 * Dispatched with the compose configuration as a plain array, right before it
 * is written to compose.generated.yaml.
 *
 * This is the escape hatch: the builder models what the plugin's services need,
 * this event reaches everything else — the compose keys it does not cover, the
 * "x-" extension fields, or a wholesale rewrite:
 *
 *     #[AsListener(DockerComposeWriteEvent::class)]
 *     function raw_compose(DockerComposeWriteEvent $event): void
 *     {
 *         $event->compose['services']['app']['deploy']['resources']['limits']['cpus'] = '2';
 *         $event->compose['x-my-tooling'] = ['version' => 1];
 *     }
 *
 * Nothing validates what you put in: the array is dumped as-is, so a mistake
 * here surfaces as a docker compose error rather than a PHP one. Prefer
 * DockerComposeBuilderEvent when the builder can express the change.
 */
final class DockerComposeWriteEvent
{
    /**
     * @param array<string, mixed> $compose
     */
    public function __construct(
        public readonly Context $context,
        public array $compose,
    ) {}
}
