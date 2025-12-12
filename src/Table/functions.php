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
