<?php

declare(strict_types=1);

namespace Castor\Docker\Installer\Ast;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Expression;
use PhpParser\ParserFactory;

/**
 * Small helpers to inject non-literal expressions into the AST builder — e.g.
 * "__DIR__ . '/blog'" (raw) or a reference to a registered service variable (var).
 */
final class Ast
{
    /**
     * Parse a raw PHP expression (e.g. "__DIR__ . '/blog'") into an AST node.
     */
    public static function raw(string $expression): Expr
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $statements = $parser->parse('<?php ' . $expression . ';');

        if (($statements[0] ?? null) instanceof Expression) {
            return $statements[0]->expr;
        }

        throw new \InvalidArgumentException(\sprintf('"%s" is not a valid PHP expression.', $expression));
    }

    /**
     * A reference to a variable, e.g. Ast::var('postgres') => $postgres.
     */
    public static function var(string $name): Variable
    {
        return new Variable(ltrim($name, '$'));
    }
}
