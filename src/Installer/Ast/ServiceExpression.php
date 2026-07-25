<?php

declare(strict_types=1);

namespace Castor\Docker\Installer\Ast;

use PhpParser\BuilderFactory;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;

/**
 * A single "(new SomeService(...))->method(...)->..." expression being built,
 * plus how it should be registered on the event (inline or via a variable).
 */
final class ServiceExpression
{
    private Expr $expr;

    private ?string $assignVariable = null;

    /**
     * @param array<int|string, mixed> $arguments
     */
    public function __construct(
        private readonly BuilderFactory $factory,
        string $class,
        array $arguments,
    ) {
        $shortName = substr((string) strrchr('\\' . ltrim($class, '\\'), '\\'), 1);
        $this->expr = new New_(new Name($shortName), $this->buildArgs($arguments));
    }

    /**
     * Chain a fluent method call, e.g. ->callMethod('withDomain', ['blog.test']).
     *
     * @param array<int|string, mixed> $arguments
     */
    public function callMethod(string $name, array $arguments = []): self
    {
        $this->expr = new MethodCall($this->expr, new Identifier($name), $this->buildArgs($arguments));

        return $this;
    }

    /**
     * Register the service through a named variable ($postgres = new ...) so it
     * can be referenced later (e.g. to link a database). Inline otherwise.
     */
    public function assignTo(string $variable): self
    {
        $this->assignVariable = ltrim($variable, '$');

        return $this;
    }

    public function getAssignedVariable(): ?string
    {
        return $this->assignVariable;
    }

    /**
     * @return Stmt[]
     */
    public function toStatements(string $eventVariable): array
    {
        if ($this->assignVariable !== null) {
            $variable = new Variable($this->assignVariable);

            return [
                new Expression(new Assign($variable, $this->expr)),
                new Expression($this->factory->methodCall(new Variable($eventVariable), 'addService', [$variable])),
            ];
        }

        return [
            new Expression($this->factory->methodCall(new Variable($eventVariable), 'addService', [$this->expr])),
        ];
    }

    /**
     * @param array<int|string, mixed> $arguments
     *
     * @return Arg[]
     */
    private function buildArgs(array $arguments): array
    {
        $args = [];

        foreach ($arguments as $key => $value) {
            $expr = $value instanceof Expr ? $value : $this->factory->val($value);
            $args[] = new Arg($expr, name: \is_string($key) ? new Identifier($key) : null);
        }

        return $args;
    }
}
