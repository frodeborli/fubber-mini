<?php

namespace mini\CLI;

use mini\Mini;

/**
 * ArgManager Service Factory
 *
 * Provides factory method for creating configured ArgManager instances.
 * Follows Mini's service pattern for consistent dependency management.
 */
class ArgManagerService
{
    /**
     * Factory method for creating ArgManager instance
     *
     * Searches for configuration file in order:
     *   1. _config/mini/CLI/ArgManager.php (application config)
     *   2. vendor/fubber/mini/config/mini/CLI/ArgManager.php (framework default)
     *
     * If no config file is found, returns an unconfigured ArgManager(0).
     *
     * @return ArgManager Configured or default ArgManager instance
     */
    public static function factory(): ArgManager
    {
        $container = Mini::$mini;
        $configPath = str_replace('\\', '/', ltrim(ArgManager::class, '\\')) . '.php';
        $configPaths = $container->paths->config ?? null;

        if ($configPaths) {
            $path = $configPaths->findFirst($configPath);
            if ($path) {
                $instance = require $path;
                if ($instance instanceof ArgManager) {
                    return $instance;
                }
            }
        }

        // Fallback: return unconfigured instance
        return new ArgManager(0);
    }
}
