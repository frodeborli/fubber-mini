<?php

namespace mini;

use mini\CLI\ArgManager;
use mini\CLI\ArgManagerService;
use mini\Mini;

/**
 * CLI Feature - Global Helper Functions
 *
 * These functions provide the public API for the mini\CLI feature.
 */

// Register ArgManager service when this file is loaded (after bootstrap.php)
// Only register if not already registered (allows app to override)
if (!Mini::$mini->has(ArgManager::class)) {
    Mini::$mini->addService(ArgManager::class, Lifetime::Singleton, fn() => ArgManagerService::factory());
}

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
 * Alternative (direct container access):
 *   $root = Mini::$mini->get(mini\CLI\ArgManager::class);
 *
 * @return ArgManager Root argument manager starting at index 0
 */
function args(): ArgManager
{
    return Mini::$mini->get(ArgManager::class);
}
