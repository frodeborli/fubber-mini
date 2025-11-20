<?php

namespace mini;

use mini\Database\DatabaseInterface;
use mini\Database\PDOService;
use PDO;

// Register services
Mini::$mini->addService(PDO::class, Lifetime::Scoped, fn() => Mini::$mini->loadServiceConfig(PDO::class));
Mini::$mini->addService(DatabaseInterface::class, Lifetime::Scoped, fn() => Mini::$mini->loadServiceConfig(DatabaseInterface::class));

/**
 * Get the database service instance
 *
 * Returns a lazy-loaded DatabaseInterface for executing queries.
 * Configuration is loaded from _config/database.php on first use.
 *
 * @return DatabaseInterface The database service
 */
function db(): DatabaseInterface {
    return Mini::$mini->get(DatabaseInterface::class);
}
