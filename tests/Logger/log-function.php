<?php
/**
 * Test the mini\log() function
 */

require __DIR__ . '/../../ensure-autoloader.php';

use Psr\Log\LoggerInterface;

$test = new class extends mini\Test {

    private function captureLog(callable $fn): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'log_test_');
        $oldErrorLog = ini_get('error_log');
        ini_set('error_log', $tmpFile);

        $fn();

        ini_set('error_log', $oldErrorLog);
        $content = file_get_contents($tmpFile);
        unlink($tmpFile);

        return $content;
    }

    public function testLogFunctionReturnsPsr3Logger(): void
    {
        $logger = \mini\log();
        $this->assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function testLogFunctionReturnsSingletonInstance(): void
    {
        $logger1 = \mini\log();
        $logger2 = \mini\log();
        $this->assertSame($logger1, $logger2);
    }

    public function testLogFunctionReturnsBuiltInLoggerByDefault(): void
    {
        $logger = \mini\log();
        $this->assertInstanceOf(\mini\Logger\Logger::class, $logger);
    }

    public function testLogFunctionCanLogMessages(): void
    {
        $output = $this->captureLog(fn() =>
            \mini\log()->info("Hello from log function")
        );

        $this->assertContains('Hello from log function', $output);
        $this->assertContains('[INFO]', $output);
    }

    public function testLogFunctionSupportsAllLevels(): void
    {
        $levels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

        foreach ($levels as $level) {
            $output = $this->captureLog(fn() =>
                \mini\log()->$level("Test $level message")
            );
            $this->assertContains(strtoupper("[$level]"), $output);
        }
    }

    public function testLogFunctionWithContext(): void
    {
        $output = $this->captureLog(fn() =>
            \mini\log()->info("User {name} logged in", ['name' => 'alice'])
        );

        $this->assertContains('User alice logged in', $output);
    }
};

exit($test->run());
