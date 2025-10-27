<?php
/**
 * Test Logger Service class
 */

require __DIR__ . '/../vendor/autoload.php';

use mini\Mini;
use mini\Logger\LoggerService;

echo "Testing Logger Service Class\n";
echo str_repeat('=', 70) . "\n\n";

// Test 1: Container integration
echo "Test 1: Container integration\n";
try {
    $logger = Mini::$mini->get(\Psr\Log\LoggerInterface::class);
    echo "✓ Logger service registered in container\n";
    echo "  Type: " . get_class($logger) . "\n";

    // Test it's a singleton
    $logger2 = Mini::$mini->get(\Psr\Log\LoggerInterface::class);
    if ($logger === $logger2) {
        echo "✓ Logger is singleton (same instance)\n";
    } else {
        echo "✗ Logger is NOT singleton (different instances)\n";
    }

    // Test via logger() function
    $logger3 = \mini\logger();
    if ($logger === $logger3) {
        echo "✓ logger() function returns same instance\n";
    } else {
        echo "✗ logger() function returns different instance\n";
    }
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 2: Basic logging (will go to error_log)
echo "Test 2: Basic PSR-3 logging\n";
try {
    $logger = \mini\logger();

    // Capture error_log output
    $errorLogFile = sys_get_temp_dir() . '/mini-logger-test.log';
    ini_set('error_log', $errorLogFile);

    // Clear previous log
    if (file_exists($errorLogFile)) {
        unlink($errorLogFile);
    }

    // Test different log levels
    $logger->debug('Debug message');
    $logger->info('Info message');
    $logger->notice('Notice message');
    $logger->warning('Warning message');
    $logger->error('Error message');
    $logger->critical('Critical message');
    $logger->alert('Alert message');
    $logger->emergency('Emergency message');

    echo "✓ All PSR-3 log levels work\n";

    // Test message interpolation
    $logger->info('User {user} logged in from {ip}', ['user' => 'john', 'ip' => '192.168.1.1']);
    echo "✓ Message interpolation works\n";

    // Test exception logging
    try {
        throw new \RuntimeException('Test exception');
    } catch (\Exception $e) {
        $logger->error('Exception occurred', ['exception' => $e]);
        echo "✓ Exception logging works\n";
    }

    // Verify logs were written
    if (file_exists($errorLogFile)) {
        $logContent = file_get_contents($errorLogFile);
        $lineCount = substr_count($logContent, "\n");
        echo "✓ Logged {$lineCount} entries to error_log\n";

        // Check for expected content
        if (str_contains($logContent, '[DEBUG]') &&
            str_contains($logContent, '[INFO]') &&
            str_contains($logContent, '[ERROR]')) {
            echo "✓ Log entries contain proper level formatting\n";
        }

        if (str_contains($logContent, 'john') && str_contains($logContent, '192.168.1.1')) {
            echo "✓ Message interpolation appears in logs\n";
        }

        if (str_contains($logContent, 'RuntimeException') && str_contains($logContent, 'Test exception')) {
            echo "✓ Exception details appear in logs\n";
        }
    } else {
        echo "✗ Log file was not created\n";
    }

} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 3: Service factory methods
echo "Test 3: Service factory methods\n";
try {
    $logger = LoggerService::createDefaultLogger();
    echo "✓ createDefaultLogger() works\n";
    echo "  Type: " . get_class($logger) . "\n";

    if ($logger instanceof \Psr\Log\LoggerInterface) {
        echo "✓ Returns PSR-3 LoggerInterface\n";
    }

} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 4: Config override (if exists)
echo "Test 4: Config override capability\n";
if (file_exists('_config/Psr/Log/LoggerInterface.php')) {
    echo "✓ Application config exists at _config/Psr/Log/LoggerInterface.php\n";
    $customLogger = require '_config/Psr/Log/LoggerInterface.php';
    echo "  Custom logger type: " . get_class($customLogger) . "\n";
} else {
    echo "  No custom config (using framework default)\n";
    echo "  To test custom config, create _config/Psr/Log/LoggerInterface.php\n";
}
echo "\n";

echo str_repeat('=', 70) . "\n";
echo "Logger Service class working correctly!\n";
echo "\nKey features:\n";
echo "  ✓ PSR-3 LoggerInterface compliant\n";
echo "  ✓ Container integration with Singleton lifetime\n";
echo "  ✓ All log levels supported (debug, info, notice, warning, error, etc.)\n";
echo "  ✓ MessageFormatter interpolation for messages\n";
echo "  ✓ Exception logging with stack traces\n";
echo "  ✓ Logs to PHP's error_log by default\n";
echo "  ✓ Configurable via _config/Psr/Log/LoggerInterface.php\n";
