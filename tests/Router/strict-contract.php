<?php
/**
 * Test the strict route-file contract
 *
 * Route files in _routes/ must return a PSR-15 RequestHandlerInterface or a
 * PSR-7 ResponseInterface. Closures are wrapped as inline handlers. Direct
 * output (echo/header), missing return values, and bare scalars throw.
 *
 * Also covers filesystem wildcard routing (`_` segments) using the
 * _routes-wildcard-test fixtures, replacing tests/_old/router-wildcard.php.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Mini;
use mini\Router\Router;
use mini\Test;
use mini\Http\Message\ServerRequest;
use Psr\Http\Message\ResponseInterface;

$test = new class extends Test {

    private function route(string $path, string $fixtureDir = '_routes-contract-test'): ResponseInterface
    {
        Mini::$mini->paths->routes = new \mini\Util\PathsRegistry(
            dirname(__DIR__) . '/' . $fixtureDir
        );
        $request = new ServerRequest('GET', $path, '');
        return (new Router())->handle($request);
    }

    public function testResponseReturnIsPassedThrough(): void
    {
        $response = $this->route('/response');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pong', (string) $response->getBody());
    }

    public function testRequestHandlerReturnIsInvoked(): void
    {
        $response = $this->route('/handler');
        $this->assertSame('handled', (string) $response->getBody());
    }

    public function testClosureReturnActsAsInlineHandler(): void
    {
        $response = $this->route('/closure');
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame(['status' => 'ok'], $data);
    }

    public function testDirectOutputThrows(): void
    {
        $this->assertThrows(fn() => $this->route('/echoes'), \RuntimeException::class);
    }

    public function testDirectOutputIsNotLeaked(): void
    {
        ob_start();
        try {
            $this->route('/echoes');
        } catch (\RuntimeException $e) {
        }
        $this->assertSame('', ob_get_clean(), 'Echoed output must be discarded, not forwarded');
    }

    public function testMissingReturnThrows(): void
    {
        $this->assertThrows(fn() => $this->route('/nothing'), \RuntimeException::class);
    }

    public function testScalarReturnThrows(): void
    {
        $this->assertThrows(fn() => $this->route('/scalar'), \RuntimeException::class);
    }

    public function testWildcardFileMatch(): void
    {
        $response = $this->route('/users/123', '_routes-wildcard-test');
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('users/_.php', $data['handler']);
    }

    public function testExactMatchBeatsWildcard(): void
    {
        $response = $this->route('/users/john', '_routes-wildcard-test');
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('users/john.php', $data['handler']);
    }

    public function testDoubleWildcardMatch(): void
    {
        $response = $this->route('/users/100/friendship/200', '_routes-wildcard-test');
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('users/_/friendship/_.php', $data['handler']);
    }
};

exit($test->run());
