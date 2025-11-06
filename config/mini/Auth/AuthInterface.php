<?php
/**
 * Default AuthInterface configuration for Mini framework
 *
 * Applications MUST provide their own implementation by creating:
 *   _config/mini/Auth/AuthInterface.php
 *
 * Example (_config/mini/Auth/AuthInterface.php):
 *   <?php
 *   return new App\Auth\SessionAuth();
 */

throw new \mini\Exceptions\ConfigurationRequiredException(
    'mini/Auth/AuthInterface.php',
    'authentication implementation (create _config/mini/Auth/AuthInterface.php and return your AuthInterface implementation)'
);
