<?php

namespace mini;

use mini\CLI\ArgManager;

/**
 * Returns the root ArgManager instance for parsing CLI arguments
 *
 * This is Mini's convenience helper for building CLI applications.
 * Like all Mini helpers, it's optional - you can always use $_SERVER['argv']
 * directly if you prefer.
 *
 * The root ArgManager can be configured via config file following Mini's
 * standard pattern. Mini will search for:
 *   1. _config/mini/CLI/ArgManager.php (application config)
 *   2. vendor/fubber/mini/config/mini/CLI/ArgManager.php (framework default)
 *
 * The config file should return a configured ArgManager instance.
 *
 * Example (_config/mini/CLI/ArgManager.php):
 *   <?php
 *   return (new mini\CLI\ArgManager(0))
 *       ->withSupportedArgs('v', ['verbose', 'config:'], 0);
 *
 * Example (usage in CLI script):
 *   $root = mini\args();
 *   $verbosity = isset($root->opts['v']) ? (is_array($root->opts['v']) ? count($root->opts['v']) : 1) : 0;
 *   $cmd = $root->nextCommand();
 *   // ... handle subcommand
 *
 * @return ArgManager Root argument manager starting at index 0
 */
function args(): ArgManager
{
    $container = Mini::$mini;

    // If already registered, return cached instance
    if ($container->has(ArgManager::class)) {
        return $container->get(ArgManager::class);
    }

    // Register service with factory that loads from config
    // Config file path: mini/CLI/ArgManager.php
    // Searches: _config/mini/CLI/ArgManager.php, then vendor/fubber/mini/config/mini/CLI/ArgManager.php
    $container->addService(ArgManager::class, Lifetime::Singleton, function() use ($container) {
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
    });

    return $container->get(ArgManager::class);
}
