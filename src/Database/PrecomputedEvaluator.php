<?php

namespace mini\Database;

use mini\Parsing\SQL\AST\ASTNode;

/**
 * An {@see ExpressionEvaluator} that can short-circuit specific AST nodes to
 * pre-computed values.
 *
 * Aggregate and window functions cannot be evaluated from a single row: their
 * value is computed over a group or a partition and is only known once that
 * group has been consumed. This evaluator lets {@see VirtualDatabase} compute
 * those values up front and then evaluate the surrounding expression
 * (`SUM(price) * 2`, `HAVING COUNT(*) > 1`, `ROW_NUMBER() OVER () * 10`)
 * with the normal expression semantics, three-valued logic included.
 *
 * Lookups are by object identity, so a node belonging to a subquery never
 * matches a value computed for the outer query.
 *
 * Scopes nest: {@see pushValues()}/{@see popValues()} bracket an evaluation so
 * that a scalar subquery evaluated in the middle of an aggregate projection
 * does not clobber the outer scope.
 */
final class PrecomputedEvaluator extends ExpressionEvaluator
{
    /** @var \SplObjectStorage<ASTNode, mixed>[] */
    private array $scopes = [];

    /**
     * @param \SplObjectStorage<ASTNode, mixed> $values
     */
    public function pushValues(\SplObjectStorage $values): void
    {
        $this->scopes[] = $values;
    }

    public function popValues(): void
    {
        \array_pop($this->scopes);
    }

    public function evaluate(ASTNode $node, ?object $row = null, array $context = []): mixed
    {
        $depth = \count($this->scopes);
        if ($depth > 0) {
            $values = $this->scopes[$depth - 1];
            if ($values->contains($node)) {
                return $values[$node];
            }
        }

        return parent::evaluate($node, $row, $context);
    }
}
