<?php
/**
 * When logging a request's error fails too, the request's own error is still told
 *
 * The 500 converter logs the exception; a logger that throws (its config could not load, its
 * backend refused) used to replace the request's error with its own, so the cause was lost.
 * Now both go to error_log().
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Dispatcher\RequestDispatcher;
use mini\Http\Message\ServerRequest;
use mini\Mini;
use mini\Test;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

$test = new class extends Test {

    private string $errorLog;

    protected function setUp(): void
    {
        $this->errorLog = \tempnam(\sys_get_temp_dir(), 'mini-errorlog-');
        \ini_set('error_log', $this->errorLog);
        Mini::$mini->set(LoggerInterface::class, new class extends AbstractLogger {
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                throw new \LogicException('the logger is broken');
            }
        });
        \mini\bootstrap();
        Mini::$mini->paths->routes = new \mini\Util\PathsRegistry(\dirname(__DIR__) . '/_routes-contract-test');
    }

    public function testTheRequestsErrorIsLoggedWhenTheLoggerFails(): void
    {
        $response = Mini::$mini->get(RequestDispatcher::class)->handle(new ServerRequest('GET', '/throws', ''));
        $this->assertSame(500, $response->getStatusCode());
        $log = (string) \file_get_contents($this->errorLog);
        $this->assertTrue(\str_contains($log, 'where does this go?'), "log: $log");
        $this->assertTrue(\str_contains($log, 'the logger is broken'), "log: $log");
        $this->assertTrue(\str_contains($log, 'GET /throws'), "log: $log");
    }
};

exit($test->run());
