<?php
/**
 * Test mini\Logger\ScopedLogger
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Logger\ScopedLogger;
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

    public function testImplementsLoggerInterface(): void
    {
        $scoped = new ScopedLogger('test', \mini\log());
        $this->assertInstanceOf(LoggerInterface::class, $scoped);
    }

    public function testPrefixesMessagesWithScope(): void
    {
        $scoped = new ScopedLogger('http', \mini\log());

        $output = $this->captureLog(fn() =>
            $scoped->info("Request received")
        );

        $this->assertContains('[http] Request received', $output);
    }

    public function testPreservesLogLevel(): void
    {
        $scoped = new ScopedLogger('db', \mini\log());

        $output = $this->captureLog(fn() =>
            $scoped->error("Connection failed")
        );

        $this->assertContains('[ERROR]', $output);
        $this->assertContains('[db] Connection failed', $output);
    }

    public function testInterpolatesContextVariables(): void
    {
        $scoped = new ScopedLogger('http', \mini\log());

        $output = $this->captureLog(fn() =>
            $scoped->info("Incoming {method} {path}", [
                'method' => 'GET',
                'path' => '/users'
            ])
        );

        $this->assertContains('[http] Incoming GET /users', $output);
    }

    public function testAddsScopeToContext(): void
    {
        $capturedContext = null;

        // Create a mock logger to capture context
        $mock = new class($capturedContext) implements LoggerInterface {
            use \Psr\Log\LoggerTrait;
            private mixed $ref;
            public function __construct(&$ref) { $this->ref = &$ref; }
            public function log($level, string|\Stringable $message, array $context = []): void {
                $this->ref = $context;
            }
        };

        $scoped = new ScopedLogger('auth', $mock);
        $scoped->info("User logged in");

        $this->assertArrayHasKey('scope', $capturedContext);
        $this->assertSame('auth', $capturedContext['scope']);
    }

    public function testDoesNotOverwriteExistingScope(): void
    {
        $capturedContext = null;

        $mock = new class($capturedContext) implements LoggerInterface {
            use \Psr\Log\LoggerTrait;
            private mixed $ref;
            public function __construct(&$ref) { $this->ref = &$ref; }
            public function log($level, string|\Stringable $message, array $context = []): void {
                $this->ref = $context;
            }
        };

        $scoped = new ScopedLogger('auth', $mock);
        $scoped->info("Message", ['scope' => 'custom']);

        $this->assertSame('custom', $capturedContext['scope']);
    }

    public function testNestedScopedLoggers(): void
    {
        $outer = new ScopedLogger('app', \mini\log());
        $inner = new ScopedLogger('db', $outer);

        $output = $this->captureLog(fn() =>
            $inner->info("Query executed")
        );

        $this->assertContains('[app] [db] Query executed', $output);
    }

    public function testAllLogLevelsWork(): void
    {
        $scoped = new ScopedLogger('test', \mini\log());
        $levels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

        foreach ($levels as $level) {
            $output = $this->captureLog(fn() =>
                $scoped->$level("Test message")
            );
            $this->assertContains('[test] Test message', $output);
            $this->assertContains('[' . strtoupper($level) . ']', $output);
        }
    }
};

exit($test->run());
