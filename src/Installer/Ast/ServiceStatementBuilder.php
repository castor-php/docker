<?php

declare(strict_types=1);

namespace Castor\Docker\Installer\Ast;

use PhpParser\BuilderFactory;
use PhpParser\Node\Stmt;

/**
 * Fluent helper an installer uses to describe the code it wants added to the
 * RegisterServiceEvent listener, as an AST rather than a string:
 *
 *     $builder->addNewServiceAst(MariaDBService::class)
 *         ->callMethod('withVersion', ['12.1']);
 *
 *     $builder->getStatements(); // AST for "$event->addService((new MariaDBService())->withVersion('12.1'));"
 *     $builder->getImports();    // ['Castor\Docker\Service\MariaDBService']
 */
final class ServiceStatementBuilder
{
    private readonly BuilderFactory $factory;

    /** @var ServiceExpression[] */
    private array $expressions = [];

    /** @var array<string, true> */
    private array $imports = [];

    public function __construct(
        private readonly string $eventVariable = 'event',
    ) {
        $this->factory = new BuilderFactory();
    }

    /**
     * @param array<int|string, mixed> $arguments constructor arguments (named when the key is a string)
     */
    public function addNewServiceAst(string $class, array $arguments = []): ServiceExpression
    {
        $this->imports[ltrim($class, '\\')] = true;

        return $this->expressions[] = new ServiceExpression($this->factory, $class, $arguments);
    }

    /**
     * Register an extra class import (e.g. for an enum used in an argument).
     */
    public function addImport(string $class): self
    {
        $this->imports[ltrim($class, '\\')] = true;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getImports(): array
    {
        return array_keys($this->imports);
    }

    /**
     * @return ServiceExpression[]
     */
    public function getExpressions(): array
    {
        return $this->expressions;
    }

    /**
     * @return Stmt[]
     */
    public function getStatements(): array
    {
        $statements = [];

        foreach ($this->expressions as $expression) {
            foreach ($expression->toStatements($this->eventVariable) as $statement) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }
}
