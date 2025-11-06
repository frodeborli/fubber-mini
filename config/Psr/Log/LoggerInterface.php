<?php
/**
 * Default Logger configuration for Mini framework
 *
 * Returns Mini's error_log logger with MessageFormatter interpolation.
 * Applications can override by creating _config/Psr/Log/LoggerInterface.php
 */

use mini\Logger\Logger;

return new Logger();
