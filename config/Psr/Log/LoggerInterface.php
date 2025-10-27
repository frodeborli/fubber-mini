<?php
/**
 * Default Logger configuration for Mini framework
 *
 * This file is used as a fallback if the application doesn't provide
 * its own _config/Psr/Log/LoggerInterface.php file.
 *
 * Config file naming: Class name with namespace separators replaced by slashes.
 * \Psr\Log\LoggerInterface::class → _config/Psr/Log/LoggerInterface.php
 *
 * Default logger logs to PHP's error_log with MessageFormatter interpolation.
 * Supports all PSR-3 log levels with timestamp, level, and exception formatting.
 *
 * Applications can override by creating _config/Psr/Log/LoggerInterface.php
 * and returning their own PSR-3 LoggerInterface instance.
 *
 * Example _config/Psr/Log/LoggerInterface.php:
 *
 *   // Use Monolog
 *   use Monolog\Logger;
 *   use Monolog\Handler\StreamHandler;
 *
 *   $logger = new Logger('app');
 *   $logger->pushHandler(new StreamHandler('/path/to/app.log', Logger::DEBUG));
 *   return $logger;
 *
 *   // Use file logger
 *   class FileLogger implements \Psr\Log\LoggerInterface {
 *       use \Psr\Log\LoggerTrait;
 *
 *       public function log($level, $message, array $context = []): void {
 *           $timestamp = date('Y-m-d H:i:s');
 *           $entry = "[{$timestamp}] [{$level}] {$message}\n";
 *           file_put_contents('/path/to/app.log', $entry, FILE_APPEND);
 *       }
 *   }
 *   return new FileLogger();
 *
 *   // Use database logger
 *   class DbLogger implements \Psr\Log\LoggerInterface {
 *       use \Psr\Log\LoggerTrait;
 *
 *       public function log($level, $message, array $context = []): void {
 *           mini\db()->insert('logs', [
 *               'level' => $level,
 *               'message' => $message,
 *               'context' => json_encode($context),
 *               'created_at' => date('Y-m-d H:i:s')
 *           ]);
 *       }
 *   }
 *   return new DbLogger();
 *
 *   // Or use framework's default
 *   return mini\Logger\LoggerService::createDefaultLogger();
 */

return mini\Logger\LoggerService::createDefaultLogger();
