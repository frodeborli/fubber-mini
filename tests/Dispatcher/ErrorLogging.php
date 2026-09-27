<?php
/**
 * An exception that becomes a 500 page is logged, in every mode
 *
 * Debug mode only decides whether the visitor sees the details too; the log always gets the
 * exception, with its file, line and trace, through the application's PSR-3 logger (mini's
 * default writes to error_log()).
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Dispatcher\RequestDispatcher;
use mini\Http\Message\ServerRequest;
use mini\Mini;
use mini\Test;

$test = new class extends Test {

    private string $errorLog;

    protected function setUp(): void
    {
        $this->errorLog = \tempnam(\sys_get_temp_dir(), 'mini-errorlog-');
        \ini_set('error_log', $this->errorLog);
        \mini\bootstrap();
        Mini::$mini->paths->routes = new \mini\Util\PathsRegistry(\dirname(__DIR__) . '/_routes-contract-test');
    }

    public function testAnUncaughtExceptionIsLoggedWithItsTraceWhenItBecomesA500(): void
    {
        $response = Mini::$mini->get(RequestDispatcher::class)->handle(new ServerRequest('GET', '/throws', ''));
        $this->assertSame(500, $response->getStatusCode());
        $log = (string) \file_get_contents($this->errorLog);
        $this->assertTrue(\str_contains($log, 'where does this go?'), "log: $log");
        $this->assertTrue(\str_contains($log, 'RuntimeException'), "log: $log");
        $this->assertTrue(\str_contains($log, 'throws.php'), "log: $log");
        $this->assertTrue(\str_contains($log, 'GET /throws'), "log: $log");
    }

    public function testANotFoundIsNotAnError(): void
    {
        \file_put_contents($this->errorLog, '');
        $response = Mini::$mini->get(RequestDispatcher::class)->handle(new ServerRequest('GET', '/no-such-route', ''));
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('', (string) \file_get_contents($this->errorLog));
    }
};

exit($test->run());
