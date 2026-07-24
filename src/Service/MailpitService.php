<?php

declare(strict_types=1);

namespace Castor\Docker\Service;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\Docker\Service\Builder\ComposeBuilder;

use function Castor\Docker\expose_service_port;

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
        $rootDomain = $context->data['root_domain'] ?? 'castor.local';

        return $builder
            ->service('mailpit')
                ->image('axllent/mailpit:' . $this->version)
                ->withHttpRouting("mailpit.{$rootDomain}", 8025)
                ->profile('default')
            ->end()
        ;
    }

    public function getTasks(): iterable
    {
        yield [
            'task' => new AsTask('expose', $this->getName(), description: 'Expose the mailpit service (SMTP) over TCP on the host (--stop to stop)'),
            'function' => function (
                #[AsArgument(description: 'Host port to expose on (defaults to the service port)')]
                ?int $port = null,
                bool $stop = false,
            ): void {
                expose_service_port($this->getName(), 1025, $port, $stop);
            },
        ];
    }

    public function getMailerDSN(): string
    {
        return 'smtp://mailpit:1025';
    }
}
