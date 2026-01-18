<?php

namespace mini;

use mini\Database\Session;
use mini\Database\VirtualDatabase;

/**
 * Virtual Database Feature - Global Helper Function
 *
 * Provides vdb() helper for accessing the VirtualDatabase via Session.
 */

// Register VirtualDatabase engine - singleton that holds registered tables
Mini::$mini->addService(VirtualDatabase::class, Lifetime::Singleton, fn() => Mini::$mini->loadServiceConfig(VirtualDatabase::class));

// Register Session - scoped per request/fiber for temp table isolation
Mini::$mini->addService(Session::class, Lifetime::Scoped, fn() => Mini::$mini->get(VirtualDatabase::class)->session());

/**
 * Get a VirtualDatabase session for the current request scope
 *
 * Returns a Session that wraps the VirtualDatabase. Each request/fiber gets
 * its own Session with isolated temporary tables. Use getEngine() to access
 * the underlying VirtualDatabase for table registration.
 *
 * Usage:
 * ```php
 * // Standard querying
 * vdb()->query("SELECT * FROM countries WHERE continent = ?", ['Europe']);
 * vdb()->queryOne("SELECT * FROM users WHERE id = ?", [123]);
 *
 * // Temporary tables (isolated per request/fiber)
 * vdb()->exec("CREATE TEMPORARY TABLE tmp AS SELECT * FROM users WHERE active = 1");
 * vdb()->query("SELECT * FROM tmp");
 *
 * // Access underlying engine for table registration
 * vdb()->getEngine()->registerTable('data', new ArrayTable(...$columns));
 * ```
 *
 * @return Session The session-scoped database interface
 */
function vdb(): Session
{
    return Mini::$mini->get(Session::class);
}
