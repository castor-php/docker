<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Service\MailpitService;
use Castor\Docker\Service\ServiceInterface;

final class MailpitInstaller extends AbstractServiceInstaller
{
    public function getName(): string
    {
        return 'mailpit';
    }

    public function getDescription(): string
    {
        return 'Mailpit SMTP server with web UI';
    }

    public function buildStatements(ServiceStatementBuilder $builder, array $answers): void
    {
        $builder->addNewServiceAst(MailpitService::class);
    }

    public function createInstance(array $answers): ServiceInterface
    {
        return new MailpitService();
    }
}
