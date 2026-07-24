<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;

class MailpitService implements ServiceInterface
{
    public function __construct(
        private string $version = 'latest',
    ) {}

    public function getName(): string
    {
        return 'mailpit';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $projectName = $context->data['project_name'] ?? 'app';
        $rootDomain = $context->data['root_domain'] ?? 'castor.local';

        return $builder
            ->service('mailpit')
                ->image('axllent/mailpit:' . $this->version)
                ->withTraefikRouting("{$projectName}-mailpit", "mailpit.{$rootDomain}", 8025)
                ->profile('default')
            ->end()
        ;
    }

    public function getTasks(): iterable
    {
        return [];
    }

    public function getMailerDSN(): string
    {
        return 'smtp://mailpit:1025';
    }
}
