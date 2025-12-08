<?php

namespace mini;

use mini\CLI\ArgManager;

/**
 * CLI Feature - Global Helper Functions
 *
 * These functions provide the public API for the mini\CLI feature.
 */

/**
 * Get or set the current ArgManager instance for CLI argument parsing
 *
 * Returns an unconfigured ArgManager on first call. Use the pattern:
 *   args(args()->withFlag(...)->withSubcommand(...));
 *
 * This same pattern works at every command level - root command and subcommands
 * all configure themselves identically.
 *
 * @param ArgManager|null $args ArgManager instance to set, or null to retrieve
 * @return ArgManager The current ArgManager
 *
 * @example Simple command
 * ```php
 * // bin/myapp
 * args(args()->withFlag('v', 'verbose')->withRequiredValue('o', 'output'));
 *
 * if (args()->getUnparsedArgs()) {
 *     die("Unexpected: " . implode(', ', args()->getUnparsedArgs()));
 * }
 *
 * if (args()->getFlag('verbose')) {
 *     echo "Verbose mode\n";
 * }
 * ```
 *
 * @example Command with subcommands
 * ```php
 * // bin/myapp
 * args(args()->withFlag('v', 'verbose')->withSubcommand('run', 'build'));
 *
 * if (args()->getUnparsedArgs()) {
 *     die("Unexpected: " . implode(', ', args()->getUnparsedArgs()));
 * }
 *
 * if ($sub = args()->nextCommand()) {
 *     args($sub);  // Hand off to subcommand
 *     require __DIR__ . '/commands/' . $sub->getCommand() . '.php';
 * }
 * ```
 *
 * @example Subcommand file (commands/run.php)
 * ```php
 * // Subcommand configures itself - same pattern as root
 * args(args()->withFlag(null, 'fast')->withRequiredValue('t', 'target'));
 *
 * if (args()->getUnparsedArgs()) {
 *     die("Unexpected: " . implode(', ', args()->getUnparsedArgs()));
 * }
 *
 * $fast = args()->getFlag('fast');
 * $target = args()->getOption('target');
 * ```
 */
function args(?ArgManager $args = null): ArgManager
{
    static $instance = null;

    if ($args !== null) {
        $instance = $args;
    }

    // Return unconfigured ArgManager by default
    $instance ??= new ArgManager();

    return $instance;
}
