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
 * Example:
 *   $root = mini\args();
 *   $cmd = $root->nextCommand();
 *   $cmd = $cmd->withSupportedArgs('v', ['verbose'], 1);
 *   echo $cmd->opts['v'] ? 'Verbose mode' : 'Normal mode';
 *   echo "Argument: " . $cmd->args[0];
 *
 * @return ArgManager Root argument manager starting at index 0
 */
function args(): ArgManager
{
    return new ArgManager(0);
}
