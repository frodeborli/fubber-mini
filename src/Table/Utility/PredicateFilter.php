<?php

namespace mini\Table\Utility;

use mini\Table\Contracts\TableInterface;
use mini\Table\Predicate;
use mini\Table\Types\Operator;

/**
 * Applies a bound Predicate to a TableInterface using filter methods
 *
 * Converts Predicate conditions into table filter calls (eq, lt, etc.),
 * allowing the table to leverage indexes for efficient filtering.
 *
 * Used by JoinTable implementations to apply join conditions.
 */
final class PredicateFilter
{
    /**
     * Apply a fully-bound predicate to a table
     *
     * @throws \LogicException If predicate has unbound parameters
     */
    public static function apply(TableInterface $table, Predicate $predicate): TableInterface
    {
        if (!$predicate->isBound()) {
            throw new \LogicException(
                'Cannot apply predicate with unbound parameters: ' .
                implode(', ', $predicate->getUnboundParams())
            );
        }

        foreach ($predicate->getConditions() as $cond) {
            $column = $cond['column'];
            $value = $cond['value'];

            $table = match ($cond['operator']) {
                Operator::Eq => $table->eq($column, $value),
                Operator::Lt => $table->lt($column, $value),
                Operator::Lte => $table->lte($column, $value),
                Operator::Gt => $table->gt($column, $value),
                Operator::Gte => $table->gte($column, $value),
                Operator::Like => $table->like($column, $value),
                Operator::In => $table->in($column, $value),
            };
        }

        return $table;
    }
}
