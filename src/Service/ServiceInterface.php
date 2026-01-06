<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;

interface ServiceInterface
{
    public function getName(): string;

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder;

    /**
     * @return iterable<array{'task': AsTask, 'function': \Closure}>
     */
    public function getTasks(): iterable;
}
