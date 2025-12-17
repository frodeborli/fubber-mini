<?php

namespace mini;

use Collator;

// Register Collator service - uses application locale by default
Mini::$mini->addService(Collator::class, Lifetime::Singleton, fn() => Mini::$mini->loadServiceConfig(
    Collator::class,
    new Collator(Mini::$mini->locale)
));

/**
 * Get the application's Collator instance for locale-aware string comparison
 *
 * Used by table sorting and LIKE operations for consistent collation behavior.
 * Configure via config/Collator.php or defaults to Mini::$mini->locale.
 *
 * @return Collator
 */
function collator(): Collator
{
    return Mini::$mini->get(Collator::class);
}

/**
 * Empty predicate constant for building filter conditions
 *
 * Since Predicate is immutable, a single shared instance is safe.
 *
 * ```php
 * use const mini\p;
 *
 * $users->or(
 *     p->eq('status', 'active'),
 *     p->eq('status', 'pending')
 * );
 *
 * // Chain multiple conditions (AND)
 * p->eq('role', 'admin')->gte('level', 5)
 * ```
 */
const p = new Table\Predicate();
