<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;

class RedirectionioAgentService implements ServiceInterface
{
    public function getName(): string
    {
        return 'redirectionio-agent';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        return $builder
            ->service('redirectionio-agent')
                ->build(__DIR__ . '/../Resources/redirectionio-agent')->end()
                ->profile('default')
            ->end()
        ;
    }

    public function getTasks(): iterable
    {
        return [];
    }
}
