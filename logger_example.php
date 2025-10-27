<?php
/**
 * Example usage of mini\logger() system
 *
 * This demonstrates the PSR-3 Logger implementation with
 * error_log output, MessageFormatter interpolation, and container integration.
 */

require_once 'vendor/autoload.php';

use function mini\{bootstrap, logger};

bootstrap();

echo "=== Mini Framework Logger System Demo ===\n\n";

// Configure error_log to show output (for demo purposes)
$logFile = sys_get_temp_dir() . '/mini-demo.log';
ini_set('error_log', $logFile);
echo "Logs are being written to: {$logFile}\n\n";

// Basic logger usage
echo "1. Basic Logger Operations:\n";
$logger = logger();

// Different log levels
$logger->debug('Debug information for developers');
$logger->info('User {username} logged in', ['username' => 'john_doe']);
$logger->notice('Unusual activity detected');
$logger->warning('Low disk space: {percent}% remaining', ['percent' => 15]);
$logger->error('Database connection failed');
$logger->critical('Payment gateway is down');
$logger->alert('Server is under heavy load');
$logger->emergency('System is shutting down');

echo "✓ Logged messages at all PSR-3 levels\n\n";

// Logging with context
echo "2. Context and Interpolation:\n";
$logger->info('Order #{order_id} placed by {customer}', [
    'order_id' => 12345,
    'customer' => 'Alice Smith'
]);

$logger->warning('Failed login attempt from IP {ip} for user {user}', [
    'ip' => '192.168.1.100',
    'user' => 'admin'
]);

echo "✓ Messages interpolated with context variables\n\n";

// Exception logging
echo "3. Exception Logging:\n";
try {
    throw new \RuntimeException('Payment processing failed', 500);
} catch (\Exception $e) {
    $logger->error('Exception in payment processing', ['exception' => $e]);
    echo "✓ Exception logged with full stack trace\n\n";
}

// Structured logging
echo "4. Structured Logging:\n";
$logger->info('API request', [
    'method' => 'POST',
    'endpoint' => '/api/users',
    'status' => 201,
    'duration_ms' => 125
]);

echo "✓ Structured data logged for analysis\n\n";

// Show log contents
echo "5. Log File Contents:\n";
echo str_repeat('-', 70) . "\n";
if (file_exists($logFile)) {
    $contents = file_get_contents($logFile);
    echo $contents;
} else {
    echo "Log file not found\n";
}
echo str_repeat('-', 70) . "\n\n";

// Show logger info
$loggerInfo = [
    'class' => get_class($logger),
    'interface' => $logger instanceof \Psr\Log\LoggerInterface ? 'PSR-3 compliant' : 'Not PSR-3',
];

echo "Logger system ready for use!\n";
echo "- Logger class: {$loggerInfo['class']}\n";
echo "- Interface: {$loggerInfo['interface']}\n";
echo "- Default output: PHP's error_log\n";
echo "- Message interpolation: MessageFormatter (with fallback)\n";
echo "- Configurable via _config/Psr/Log/LoggerInterface.php\n";
