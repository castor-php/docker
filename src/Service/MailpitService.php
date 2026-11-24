<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Context;
use Castor\Docker\Service\Behaviour\HasName;
use Castor\Docker\Service\Behaviour\HasVersion;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\expose_task;

use const Castor\Docker\DEFAULT_ROOT_DOMAIN;

class MailpitService implements LinkableServiceInterface
{
    use HasName;
    use HasVersion;

    protected function getDefaultVersion(): string
    {
        return 'latest';
    }

    protected function getDefaultName(): string
    {
        return 'mailpit';
    }

    public function updateCompose(Context $context, ComposeBuilder $builder): ComposeBuilder
    {
        $rootDomain = $context->data['root_domain'] ?? DEFAULT_ROOT_DOMAIN;

        $name = $this->getName();

        return $builder
            ->service($name)
                ->image('axllent/mailpit:' . $this->getVersion())
                ->withHttpRouting("{$name}.{$rootDomain}", 8025)
                ->profile('default')
            ->end()
        ;
    }

    public function getTasks(): iterable
    {
        yield expose_task($this, 1025, 'SMTP');
    }

    public function getMailerDSN(): string
    {
        return 'smtp://' . $this->getName() . ':1025';
    }

    public function getLinkEnvironment(Context $context): array
    {
        return ['MAILER_DSN' => $this->getMailerDSN()];
    }

    /**
     * Started is enough: nothing sends a mail while booting.
     */
    public function getLinkDependencies(): array
    {
        return [$this->getName() => 'service_started'];
    }
}
