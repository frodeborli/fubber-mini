<?php

namespace mini\Services;

use mini\Contracts\DatabaseInterface as DatabaseInterfaceContract;
use mini\Database\PdoDatabase;

/**
 * DatabaseInterface Service Factory
 *
 * Provides configured DatabaseInterface instances that fetch
 * PDO lazily from the container.
 */
class DatabaseInterface
{
    /**
     * Create DatabaseInterface instance
     *
     * Returns PdoDatabase which will fetch PDO from container on-demand.
     * This ensures proper scoping and lazy initialization.
     */
    public static function factory(): DatabaseInterfaceContract
    {
        return new PdoDatabase();
    }
}
