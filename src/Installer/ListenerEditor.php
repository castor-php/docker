<?php

declare(strict_types=1);

namespace Castor\Docker\Installer;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

/**
 * Format-preserving editor for the user's castor.php: it registers services on
 * the RegisterServiceEvent listener (creating the listener when there is none),
 * merging the required "use" imports, and only rewrites the nodes it touches.
 */
final class ListenerEditor
{
    private const EVENT_CLASS = 'Castor\Docker\Event\RegisterServiceEvent';
    private const LISTENER_ATTRIBUTE = 'Castor\Attribute\AsListener';

    /** @var Stmt[] */
    private array $oldStmts;

    /** @var array<int, mixed> */
    private array $oldTokens;

    /** @var Stmt[] */
    private array $newStmts;

    private ?Namespace_ $namespace = null;

    public function __construct(string $source)
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->oldStmts = $parser->parse($source) ?? [];
        $this->oldTokens = $parser->getTokens();

        $traverser = new NodeTraverser(new CloningVisitor());
        /** @var Stmt[] $newStmts */
        $newStmts = $traverser->traverse($this->oldStmts);
        $this->newStmts = $newStmts;

        foreach ($this->newStmts as $statement) {
            if ($statement instanceof Namespace_) {
                $this->namespace = $statement;

                break;
            }
        }
    }

    /**
     * The variable name of the RegisterServiceEvent parameter of the existing
     * listener (so generated code uses the same "$event"), or the default when
     * the listener still has to be created.
     */
    public function getEventVariable(): string
    {
        $listener = $this->findListener();

        if ($listener !== null) {
            foreach ($listener->params as $param) {
                if ($param->type instanceof Name && $this->isEventType($param->type) && $param->var instanceof Variable && \is_string($param->var->name)) {
                    return $param->var->name;
                }
            }
        }

        return 'event';
    }

    /**
     * @param Stmt[] $statements
     */
    public function addStatements(array $statements): void
    {
        $listener = $this->findListener();

        if ($listener !== null) {
            $listener->stmts = [...$listener->stmts, ...$statements];

            return;
        }

        // No listener yet: create one and its two required imports.
        $this->addImports([self::LISTENER_ATTRIBUTE, self::EVENT_CLASS]);

        $listener = new Function_('register_service', [
            'attrGroups' => [new AttributeGroup([
                new Attribute(new Name('AsListener'), [
                    new Arg(new ClassConstFetch(new Name('RegisterServiceEvent'), 'class')),
                ]),
            ])],
            'params' => [new Param(new Variable('event'), type: new Name('RegisterServiceEvent'))],
            'returnType' => new Identifier('void'),
            'stmts' => $statements,
        ]);

        $this->appendToContainer($listener);
    }

    /**
     * @param list<string> $classes
     */
    public function addImports(array $classes): void
    {
        $statements = $this->containerStmts();
        $existing = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Use_) {
                foreach ($statement->uses as $use) {
                    $existing[$use->name->toString()] = true;
                }
            }
        }

        $new = [];

        foreach ($classes as $class) {
            $class = ltrim($class, '\\');

            if (isset($existing[$class])) {
                continue;
            }

            $existing[$class] = true;
            $new[] = new Use_([new UseItem(new Name($class))]);
        }

        if ($new === []) {
            return;
        }

        array_splice($statements, $this->importInsertPosition($statements), 0, $new);
        $this->setContainerStmts($statements);
    }

    /**
     * Ensure the given service class is registered through a variable (so it can
     * be referenced, e.g. to link a database), extracting it from an inline
     * "$event->addService(new X())" if needed. Returns the variable name, or
     * null when the service is not registered in the listener.
     */
    public function ensureServiceVariable(string $class, string $preferredVariable): ?string
    {
        $listener = $this->findListener();

        if ($listener === null) {
            return null;
        }

        $short = substr((string) strrchr('\\' . ltrim($class, '\\'), '\\'), 1);

        // Already assigned to a variable?
        foreach ($listener->stmts as $statement) {
            if (
                $statement instanceof Expression
                && $statement->expr instanceof Assign
                && $statement->expr->var instanceof Variable
                && \is_string($statement->expr->var->name)
                && $this->isNewOf($statement->expr->expr, $short)
            ) {
                return $statement->expr->var->name;
            }
        }

        // Inline in an addService() call -> extract into a variable.
        foreach ($listener->stmts as $index => $statement) {
            if (
                $statement instanceof Expression
                && $statement->expr instanceof MethodCall
                && $statement->expr->name instanceof Identifier
                && $statement->expr->name->name === 'addService'
                && \count($statement->expr->args) === 1
                && ($arg = $statement->expr->args[0]) instanceof Arg
                && $this->isNewOf($arg->value, $short)
            ) {
                $variable = $this->uniqueVariable($listener, $preferredVariable);
                $assign = new Expression(new Assign(new Variable($variable), $arg->value));
                $arg->value = new Variable($variable);
                array_splice($listener->stmts, $index, 0, [$assign]);

                return $variable;
            }
        }

        return null;
    }

    /**
     * Remove the registration of the given service (matched by class, and by the
     * "name:" argument when present) from the listener, dropping a now-unused
     * variable assignment and imports. Returns false when it is not registered.
     *
     * @throws \RuntimeException when the service is referenced by another one (e.g. a linked database)
     */
    public function removeService(string $class, string $name): bool
    {
        $listener = $this->findListener();

        if ($listener === null) {
            return false;
        }

        $short = substr((string) strrchr('\\' . ltrim($class, '\\'), '\\'), 1);

        foreach ($listener->stmts as $index => $statement) {
            if (
                !$statement instanceof Expression
                || !$statement->expr instanceof MethodCall
                || !$statement->expr->name instanceof Identifier
                || $statement->expr->name->name !== 'addService'
                || \count($statement->expr->args) !== 1
                || !($arg = $statement->expr->args[0]) instanceof Arg
            ) {
                continue;
            }

            $value = $arg->value;

            // addService($variable) with a matching "$variable = new X(...)".
            if ($value instanceof Variable && \is_string($value->name)) {
                $assignIndex = $this->findAssignment($listener, $value->name, $short, $name);

                if ($assignIndex === null) {
                    continue;
                }

                if ($this->variableUsedElsewhere($listener, $value->name, [$assignIndex, $index])) {
                    throw new \RuntimeException(\sprintf('"%s" is referenced by another service (e.g. linked as a database); remove or unlink that service first.', $name));
                }

                $assignment = $listener->stmts[$assignIndex];
                $shortNames = $assignment instanceof Expression && $assignment->expr instanceof Assign
                    ? $this->classShortNames($assignment->expr->expr)
                    : [$short];

                $indexes = [$index, $assignIndex];
                rsort($indexes);
                foreach ($indexes as $remove) {
                    array_splice($listener->stmts, $remove, 1);
                }

                $this->removeUnusedImports($shortNames);

                return true;
            }

            // addService(new X(...)) or addService((new X(...))->...()).
            $new = $this->chainBaseNew($value);

            if ($new !== null && $new->class instanceof Name && $new->class->getLast() === $short && $this->newMatchesName($new, $name)) {
                array_splice($listener->stmts, $index, 1);
                $this->removeUnusedImports($this->classShortNames($value));

                return true;
            }
        }

        return false;
    }

    public function getSource(): string
    {
        return (new PrettyPrinter\Standard())->printFormatPreserving($this->newStmts, $this->oldStmts, $this->oldTokens);
    }

    private function chainBaseNew(Expr $expr): ?New_
    {
        while ($expr instanceof MethodCall) {
            $expr = $expr->var;
        }

        return $expr instanceof New_ ? $expr : null;
    }

    private function newMatchesName(New_ $new, string $name): bool
    {
        foreach ($new->args as $arg) {
            if ($arg instanceof Arg && $arg->name instanceof Identifier && $arg->name->name === 'name') {
                return $arg->value instanceof String_ && $arg->value->value === $name;
            }
        }

        return true;
    }

    private function findAssignment(Function_ $listener, string $variable, string $short, string $name): ?int
    {
        foreach ($listener->stmts as $index => $statement) {
            if (
                $statement instanceof Expression
                && $statement->expr instanceof Assign
                && $statement->expr->var instanceof Variable
                && $statement->expr->var->name === $variable
                && ($new = $this->chainBaseNew($statement->expr->expr)) !== null
                && $new->class instanceof Name
                && $new->class->getLast() === $short
                && $this->newMatchesName($new, $name)
            ) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param int[] $exceptIndexes
     */
    private function variableUsedElsewhere(Function_ $listener, string $variable, array $exceptIndexes): bool
    {
        $finder = new NodeFinder();

        foreach ($listener->stmts as $index => $statement) {
            if (\in_array($index, $exceptIndexes, true)) {
                continue;
            }

            if ($finder->findFirst($statement, static fn(Node $node): bool => $node instanceof Variable && $node->name === $variable) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function classShortNames(Node $node): array
    {
        $names = [];

        foreach ((new NodeFinder())->findInstanceOf($node, Name::class) as $name) {
            $names[$name->getLast()] = true;
        }

        return array_keys($names);
    }

    /**
     * @param list<string> $shortNames
     */
    private function removeUnusedImports(array $shortNames): void
    {
        $statements = $this->containerStmts();
        $changed = false;

        foreach ($statements as $index => $statement) {
            if (!$statement instanceof Use_ || \count($statement->uses) !== 1) {
                continue;
            }

            $short = $statement->uses[0]->name->getLast();

            if (\in_array($short, $shortNames, true) && !$this->isClassUsed($short)) {
                unset($statements[$index]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->setContainerStmts(array_values($statements));
        }
    }

    private function isClassUsed(string $short): bool
    {
        $finder = new NodeFinder();

        foreach ($this->containerStmts() as $statement) {
            if ($statement instanceof Use_) {
                continue;
            }

            if ($finder->findFirst($statement, static fn(Node $node): bool => $node instanceof Name && $node->getLast() === $short) !== null) {
                return true;
            }
        }

        return false;
    }

    private function isNewOf(mixed $expr, string $short): bool
    {
        return $expr instanceof New_ && $expr->class instanceof Name && $expr->class->getLast() === $short;
    }

    private function uniqueVariable(Function_ $listener, string $preferred): string
    {
        $used = [$this->getEventVariable() => true];

        foreach ($listener->stmts as $statement) {
            if ($statement instanceof Expression && $statement->expr instanceof Assign && $statement->expr->var instanceof Variable && \is_string($statement->expr->var->name)) {
                $used[$statement->expr->var->name] = true;
            }
        }

        $name = $preferred;
        $suffix = 2;

        while (isset($used[$name])) {
            $name = $preferred . $suffix++;
        }

        return $name;
    }

    private function findListener(): ?Function_
    {
        foreach ($this->containerStmts() as $statement) {
            if ($statement instanceof Function_) {
                foreach ($statement->params as $param) {
                    if ($param->type instanceof Name && $this->isEventType($param->type)) {
                        return $statement;
                    }
                }
            }
        }

        return null;
    }

    private function isEventType(Name $name): bool
    {
        return $name->getLast() === 'RegisterServiceEvent';
    }

    /**
     * @return Stmt[]
     */
    private function containerStmts(): array
    {
        return $this->namespace !== null ? $this->namespace->stmts : $this->newStmts;
    }

    /**
     * @param Stmt[] $statements
     */
    private function setContainerStmts(array $statements): void
    {
        if ($this->namespace !== null) {
            $this->namespace->stmts = $statements;

            return;
        }

        $this->newStmts = $statements;
    }

    private function appendToContainer(Stmt $statement): void
    {
        $statements = $this->containerStmts();
        $statements[] = $statement;
        $this->setContainerStmts($statements);
    }

    /**
     * @param Stmt[] $statements
     */
    private function importInsertPosition(array $statements): int
    {
        $position = 0;

        foreach ($statements as $index => $statement) {
            if ($statement instanceof Use_ || $statement instanceof Declare_) {
                $position = $index + 1;
            }
        }

        return $position;
    }
}
