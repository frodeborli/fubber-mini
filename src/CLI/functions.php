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
 * The root ArgManager can be configured by binding it in the service container.
 * This allows you to define application-wide options (like -v/--verbose, --config)
 * in one place during bootstrap.
 *
 * Example (in bootstrap or entry point):
 *   use mini\CLI\ArgManager;
 *   use mini\Lifetime;
 *
 *   Mini::$mini->addService(ArgManager::class, Lifetime::Singleton, function() {
 *       return (new ArgManager(0))
 *           ->withSupportedArgs('v', ['verbose', 'config:'], 0);
 *   });
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

    // If ArgManager is registered in container, use it
    if ($container->has(ArgManager::class)) {
        return $container->get(ArgManager::class);
    }

    // Otherwise create and register a default singleton instance
    $container->addService(ArgManager::class, Lifetime::Singleton, function() {
        return new ArgManager(0);
    });

    return $container->get(ArgManager::class);
}
