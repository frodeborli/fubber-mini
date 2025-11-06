<?php
/**
 * Default ArgManager configuration for Mini framework
 *
 * Returns an unconfigured ArgManager instance (no options defined).
 * Applications can override this by creating their own config file at:
 *   _config/mini/CLI/ArgManager.php
 *
 * Example custom config (_config/mini/CLI/ArgManager.php):
 *   <?php
 *   return (new mini\CLI\ArgManager(0))
 *       ->withSupportedArgs('v', ['verbose', 'config:', 'help'], 0);
 */

return new mini\CLI\ArgManager(0);
