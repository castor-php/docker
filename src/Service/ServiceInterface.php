<?php

namespace Castor\Docker\Service;

use Castor\Attribute\AsTask;
use Castor\Context;

interface ServiceInterface
{
    public function getName(): string;

    /**
     * @param array<mixed> $compose The current docker compose configuration.
     *
     * @return array<mixed> The updated docker compose configuration.
     */
    public function updateCompose(Context $context, array $compose): array;

    /**
     * @return iterable<array{'task': AsTask, 'function': \Closure}>
     */
    public function getTasks(): iterable;
}
