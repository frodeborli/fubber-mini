<?php

namespace mini\Table;

/**
 * Marker interface for predicate chains used with or()
 *
 * Implemented by Predicate (the root) and FilteredTable (the chain links).
 * Ensures only valid predicate structures can be passed to or().
 *
 * ```php
 * $p = Predicate::from($users);
 *
 * // These are PredicateInterface:
 * $p                              // Predicate
 * $p->eq('status', 'active')      // FilteredTable -> Predicate
 * $p->eq('a', 1)->lt('b', 2)      // FilteredTable -> FilteredTable -> Predicate
 *
 * // Use with or():
 * $users->or($p->eq('x', 1), $p->eq('y', 2));
 * ```
 */
interface PredicateInterface extends TableInterface
{
    /**
     * Test if a row matches this predicate
     */
    public function test(object $row): bool;
}
