<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

use Castor\Docker\Installer\Ast\ServiceStatementBuilder;
use Castor\Docker\Service\ServiceInterface;

/**
 * Describes how to install a service: which questions to ask, what code to add
 * to the RegisterServiceEvent listener, the live instance to build/up right
 * away, and any extra install steps.
 *
 * Register installers by listening to {@see \Castor\Docker\Event\RegisterServiceInstallerEvent}.
 */
interface ServiceInstaller
{
    /**
     * Unique key used on the CLI, e.g. "mariadb".
     */
    public function getName(): string;

    public function getDescription(): string;

    /**
     * @return list<Input>
     */
    public function getInputs(): array;

    /**
     * Describe, as an AST, the code to register the service in the listener.
     *
     * @param array<string, mixed> $answers keyed by input name
     */
    public function buildStatements(ServiceStatementBuilder $builder, array $answers): void;

    /**
     * The live service instance, used to regenerate the compose file in-process.
     *
     * @param array<string, mixed> $answers
     */
    public function createInstance(array $answers): ServiceInterface;

    /**
     * Host-side preparation run before the compose file is regenerated and built
     * (e.g. creating the application directory). No-op by default.
     *
     * @param array<string, mixed> $answers
     */
    public function prepare(array $answers): void;

    /**
     * Extra steps run after "build" but before "up" (e.g. scaffolding an app in
     * the service's builder container). No-op by default.
     *
     * @param array<string, mixed> $answers
     */
    public function scaffold(array $answers): void;

    /**
     * Extra steps run after "up" (e.g. migrations). No-op by default.
     *
     * @param array<string, mixed> $answers
     */
    public function postUp(array $answers): void;
}
