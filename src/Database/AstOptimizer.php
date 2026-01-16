<?php

namespace mini\Database;

use mini\Parsing\SQL\AST\{
    ASTNode,
    BinaryOperation,
    UnaryOperation,
    BetweenOperation,
    InOperation,
    IsNullOperation,
    LikeOperation,
    LiteralNode
};

/**
 * Optimizes SQL AST for correct evaluation and performance
 *
 * Key transformations:
 * - Rewrites negated predicates using De Morgan's laws
 * - Ensures correct three-valued logic (NULL handling)
 */
class AstOptimizer
{
    /**
     * Optimize an AST node and its children
     */
    public function optimize(ASTNode $node): ASTNode
    {
        return $this->rewriteNegations($node);
    }

    /**
     * Rewrite negated predicates using De Morgan's laws
     *
     * SQL has three-valued logic: True, False, Unknown (NULL).
     * NOT only inverts True ↔ False; Unknown stays Unknown.
     *
     * Using set complement (except) incorrectly includes NULL rows.
     * De Morgan transformations give correct NULL semantics:
     *
     *   NOT BETWEEN low AND high  →  col < low OR col > high
     *   NOT (a > b)               →  a <= b
     *   NOT (a AND b)             →  NOT a OR NOT b
     *   NOT (a OR b)              →  NOT a AND NOT b
     *   NOT NOT a                 →  a
     */
    private function rewriteNegations(ASTNode $node): ASTNode
    {
        // NOT BETWEEN → col < low OR col > high
        if ($node instanceof BetweenOperation && $node->negated) {
            $expr = $this->rewriteNegations($node->expression);
            $low = $this->rewriteNegations($node->low);
            $high = $this->rewriteNegations($node->high);

            return new BinaryOperation(
                new BinaryOperation($expr, '<', $low),
                'OR',
                new BinaryOperation($expr, '>', $high)
            );
        }

        // NOT IN (a, b, c) → col <> a AND col <> b AND col <> c
        // Only for literal lists - subqueries stay as NOT IN
        if ($node instanceof InOperation && $node->negated && !$node->isSubquery()) {
            $expr = $this->rewriteNegations($node->left);
            $values = array_map(fn($v) => $this->rewriteNegations($v), $node->values);

            if (empty($values)) {
                // NOT IN () is always true - return literal 1 (SQL true)
                return new LiteralNode(1, 'number');
            }

            // Build: col <> v1 AND col <> v2 AND ...
            $result = new BinaryOperation($expr, '<>', $values[0]);
            for ($i = 1; $i < count($values); $i++) {
                $result = new BinaryOperation(
                    $result,
                    'AND',
                    new BinaryOperation($expr, '<>', $values[$i])
                );
            }
            return $result;
        }

        // NOT (expression) - apply De Morgan or flip comparison
        if ($node instanceof UnaryOperation && strtoupper($node->operator) === 'NOT') {
            $inner = $this->rewriteNegations($node->expression);

            // NOT NOT a → a
            if ($inner instanceof UnaryOperation && strtoupper($inner->operator) === 'NOT') {
                return $inner->expression;
            }

            // NOT (comparison) → flipped comparison
            if ($inner instanceof BinaryOperation) {
                $flipped = $this->flipComparison($inner->operator);
                if ($flipped !== null) {
                    return new BinaryOperation($inner->left, $flipped, $inner->right);
                }

                // NOT (a AND b) → NOT a OR NOT b
                if (strtoupper($inner->operator) === 'AND') {
                    return $this->rewriteNegations(new BinaryOperation(
                        new UnaryOperation('NOT', $inner->left),
                        'OR',
                        new UnaryOperation('NOT', $inner->right)
                    ));
                }

                // NOT (a OR b) → NOT a AND NOT b
                if (strtoupper($inner->operator) === 'OR') {
                    return $this->rewriteNegations(new BinaryOperation(
                        new UnaryOperation('NOT', $inner->left),
                        'AND',
                        new UnaryOperation('NOT', $inner->right)
                    ));
                }
            }

            // NOT IS NULL → IS NOT NULL (toggle the negated flag)
            if ($inner instanceof IsNullOperation) {
                return new IsNullOperation($inner->expression, !$inner->negated);
            }

            // NOT LIKE → LIKE with negated flag (toggle)
            if ($inner instanceof LikeOperation) {
                return new LikeOperation($inner->left, $inner->pattern, !$inner->negated);
            }

            // NOT BETWEEN already handled above, but if inner is non-negated BETWEEN
            if ($inner instanceof BetweenOperation && !$inner->negated) {
                // Transform to NOT BETWEEN then let recursion handle it
                return $this->rewriteNegations(new BetweenOperation(
                    $inner->expression,
                    $inner->low,
                    $inner->high,
                    true
                ));
            }

            // NOT IN → toggle negated flag and let recursion handle
            if ($inner instanceof InOperation && !$inner->negated) {
                $negatedIn = new InOperation($inner->left, $inner->values, true);
                $negatedIn->subquery = $inner->subquery ?? null;
                return $this->rewriteNegations($negatedIn);
            }

            // Can't simplify further - return NOT with optimized inner
            return new UnaryOperation('NOT', $inner);
        }

        // Recurse into binary operations
        if ($node instanceof BinaryOperation) {
            return new BinaryOperation(
                $this->rewriteNegations($node->left),
                $node->operator,
                $this->rewriteNegations($node->right)
            );
        }

        // Recurse into other node types that have children
        if ($node instanceof BetweenOperation) {
            return new BetweenOperation(
                $this->rewriteNegations($node->expression),
                $this->rewriteNegations($node->low),
                $this->rewriteNegations($node->high),
                $node->negated
            );
        }

        if ($node instanceof InOperation) {
            // For subqueries, values is a SubqueryNode, not an array
            $values = $node->isSubquery()
                ? $node->values  // Keep SubqueryNode as-is
                : array_map(fn($v) => $this->rewriteNegations($v), $node->values);
            $optimized = new InOperation(
                $this->rewriteNegations($node->left),
                $values,
                $node->negated
            );
            return $optimized;
        }

        if ($node instanceof IsNullOperation) {
            return new IsNullOperation(
                $this->rewriteNegations($node->expression),
                $node->negated
            );
        }

        if ($node instanceof LikeOperation) {
            return new LikeOperation(
                $this->rewriteNegations($node->left),
                $this->rewriteNegations($node->pattern),
                $node->negated
            );
        }

        if ($node instanceof UnaryOperation) {
            return new UnaryOperation(
                $node->operator,
                $this->rewriteNegations($node->expression)
            );
        }

        // Leaf nodes and unhandled types pass through unchanged
        return $node;
    }

    /**
     * Flip a comparison operator for NOT transformation
     */
    private function flipComparison(string $op): ?string
    {
        return match (strtoupper($op)) {
            '>' => '<=',
            '>=' => '<',
            '<' => '>=',
            '<=' => '>',
            '=' => '<>',
            '<>', '!=' => '=',
            default => null,
        };
    }
}
