<?php
/**
 * Test mini\Logger\Logger PSR-3 implementation
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Logger\Logger;
use Psr\Log\LogLevel;

$test = new class extends mini\Test {
    private Logger $logger;
    private array $capturedLogs = [];

    protected function setUp(): void
    {
        $this->logger = new Logger();

        // Capture error_log output by setting a custom error handler
        set_error_handler(function($errno, $errstr) {
            $this->capturedLogs[] = $errstr;
            return true;
        });
    }

    private function captureLog(callable $fn): string
    {
        // Use output buffering and a temp file to capture error_log
        $tmpFile = tempnam(sys_get_temp_dir(), 'log_test_');
        $oldErrorLog = ini_get('error_log');
        ini_set('error_log', $tmpFile);

        $fn();

        ini_set('error_log', $oldErrorLog);
        $content = file_get_contents($tmpFile);
        unlink($tmpFile);

        return $content;
    }

    public function testLoggerExtendsPsr3AbstractLogger(): void
    {
        $this->assertInstanceOf(\Psr\Log\AbstractLogger::class, $this->logger);
    }

    public function testLoggerImplementsLoggerInterface(): void
    {
        $this->assertInstanceOf(\Psr\Log\LoggerInterface::class, $this->logger);
    }

    public function testInfoLevelLogging(): void
    {
        $output = $this->captureLog(fn() => $this->logger->info("Test message"));

        $this->assertContains('[INFO]', $output);
        $this->assertContains('Test message', $output);
    }

    public function testErrorLevelLogging(): void
    {
        $output = $this->captureLog(fn() => $this->logger->error("Error occurred"));

        $this->assertContains('[ERROR]', $output);
        $this->assertContains('Error occurred', $output);
    }

    public function testWarningLevelLogging(): void
    {
        $output = $this->captureLog(fn() => $this->logger->warning("Warning message"));

        $this->assertContains('[WARNING]', $output);
        $this->assertContains('Warning message', $output);
    }

    public function testDebugLevelLogging(): void
    {
        $output = $this->captureLog(fn() => $this->logger->debug("Debug info"));

        $this->assertContains('[DEBUG]', $output);
        $this->assertContains('Debug info', $output);
    }

    public function testCriticalLevelLogging(): void
    {
        $output = $this->captureLog(fn() => $this->logger->critical("Critical failure"));

        $this->assertContains('[CRITICAL]', $output);
        $this->assertContains('Critical failure', $output);
    }

    public function testEmergencyLevelLogging(): void
    {
        $output = $this->captureLog(fn() => $this->logger->emergency("System down"));

        $this->assertContains('[EMERGENCY]', $output);
        $this->assertContains('System down', $output);
    }

    public function testAlertLevelLogging(): void
    {
        $output = $this->captureLog(fn() => $this->logger->alert("Action required"));

        $this->assertContains('[ALERT]', $output);
        $this->assertContains('Action required', $output);
    }

    public function testNoticeLevelLogging(): void
    {
        $output = $this->captureLog(fn() => $this->logger->notice("Notice this"));

        $this->assertContains('[NOTICE]', $output);
        $this->assertContains('Notice this', $output);
    }

    public function testLogEntryContainsTimestamp(): void
    {
        $output = $this->captureLog(fn() => $this->logger->info("Test"));

        // Should match format [YYYY-MM-DD HH:MM:SS]
        $this->assertTrue(
            preg_match('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $output) === 1,
            "Log entry should contain timestamp in format [YYYY-MM-DD HH:MM:SS]"
        );
    }

    public function testSimplePlaceholderInterpolation(): void
    {
        $output = $this->captureLog(fn() =>
            $this->logger->info("User {username} logged in", ['username' => 'john'])
        );

        $this->assertContains('User john logged in', $output);
    }

    public function testMultiplePlaceholderInterpolation(): void
    {
        $output = $this->captureLog(fn() =>
            $this->logger->info("User {user} did {action}", [
                'user' => 'admin',
                'action' => 'delete'
            ])
        );

        $this->assertContains('User admin did delete', $output);
    }

    public function testArrayContextIsJsonEncodedWithBackticks(): void
    {
        $output = $this->captureLog(fn() =>
            $this->logger->info("Data: {data}", [
                'data' => ['id' => 123, 'name' => 'Test']
            ])
        );

        $this->assertContains('`{"id":123,"name":"Test"}`', $output);
    }

    public function testNullContextValueHandling(): void
    {
        $output = $this->captureLog(fn() =>
            $this->logger->info("Value is {value}", ['value' => null])
        );

        $this->assertContains('Value is `null`', $output);
    }

    public function testBooleanTrueContextValue(): void
    {
        $output = $this->captureLog(fn() =>
            $this->logger->info("Active: {active}", ['active' => true])
        );

        $this->assertContains('Active: `true`', $output);
    }

    public function testBooleanFalseContextValue(): void
    {
        $output = $this->captureLog(fn() =>
            $this->logger->info("Active: {active}", ['active' => false])
        );

        $this->assertContains('Active: `false`', $output);
    }

    public function testNonStringableObjectInContext(): void
    {
        $obj = new \stdClass();

        $output = $this->captureLog(fn() =>
            $this->logger->info("Object: {obj}", ['obj' => $obj])
        );

        $this->assertContains('Object: `stdClass`', $output);
    }

    public function testExceptionInContextFormatsStackTrace(): void
    {
        $exception = new \RuntimeException("Something went wrong");

        $output = $this->captureLog(fn() =>
            $this->logger->error("Error occurred", ['exception' => $exception])
        );

        $this->assertContains('Exception: RuntimeException', $output);
        $this->assertContains('Message: Something went wrong', $output);
        $this->assertContains('File:', $output);
        $this->assertContains('Trace:', $output);
    }

    public function testExceptionContextKeyIsNotInterpolated(): void
    {
        $exception = new \RuntimeException("Test error");

        $output = $this->captureLog(fn() =>
            $this->logger->error("Error: {message}", [
                'message' => 'custom message',
                'exception' => $exception
            ])
        );

        // The message placeholder should be replaced
        $this->assertContains('Error: custom message', $output);
        // But exception details should be appended
        $this->assertContains('Exception: RuntimeException', $output);
    }

    public function testStringableObjectInContext(): void
    {
        $stringable = new class {
            public function __toString(): string {
                return 'StringableValue';
            }
        };

        $output = $this->captureLog(fn() =>
            $this->logger->info("Object: {obj}", ['obj' => $stringable])
        );

        $this->assertContains('StringableValue', $output);
    }

    public function testEmptyContextDoesNotBreakLogging(): void
    {
        $output = $this->captureLog(fn() =>
            $this->logger->info("Simple message", [])
        );

        $this->assertContains('Simple message', $output);
    }

    public function testGenericLogMethod(): void
    {
        $output = $this->captureLog(fn() =>
            $this->logger->log(LogLevel::INFO, "Generic log call")
        );

        $this->assertContains('[INFO]', $output);
        $this->assertContains('Generic log call', $output);
    }
};

exit($test->run());
