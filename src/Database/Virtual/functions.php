<?php

namespace mini;

use mini\Database\VirtualDatabase;

/**
 * Virtual Database Feature - Global Helper Function
 *
 * Provides vdb() helper for accessing the VirtualDatabase service.
 */

// Register VirtualDatabase service - lazily initialized
Mini::$mini->addService(VirtualDatabase::class, Lifetime::Singleton, fn() => Mini::$mini->loadServiceConfig(VirtualDatabase::class));

/**
 * Get the VirtualDatabase service instance
 *
 * Returns a lazy-loaded VirtualDatabase for querying virtual tables.
 * Configuration is loaded from _config/virtual-database.php on first use.
 *
 * The VirtualDatabase allows you to query non-SQL data sources (CSV files,
 * APIs, generators) using standard SQL syntax.
 *
 * Usage:
 * ```php
 * // Register virtual tables in _config/virtual-database.php
 * vdb()->query("SELECT * FROM countries WHERE continent = ?", ['Europe']);
 * vdb()->queryOne("SELECT * FROM users WHERE id = ?", [123]);
 * vdb()->queryField("SELECT COUNT(*) FROM products");
 * ```
 *
 * @return VirtualDatabase The virtual database service
 */
function vdb(): VirtualDatabase
{
    return Mini::$mini->get(VirtualDatabase::class);
}
