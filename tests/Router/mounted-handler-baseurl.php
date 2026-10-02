<?php
/**
 * Mounted PSR-15 handlers under a base URL path (MINI_BASE_URL)
 *
 * Separate file because Mini reads MINI_BASE_URL once, at autoload time.
 */

$_ENV['MINI_BASE_URL'] = 'http://localhost/base';

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Mini;
use mini\Router\Router;
use mini\Test;
use mini\Http\Message\ServerRequest;

$test = new class extends Test {

    private function mounted(string $path, string $fixtureDir): array
    {
        Mini::$mini->paths->routes = new \mini\Util\PathsRegistry(dirname(__DIR__) . '/' . $fixtureDir);
        $response = (new Router())->handle(new ServerRequest('GET', $path, ''));
        $this->assertSame(200, $response->getStatusCode());
        return json_decode((string) $response->getBody(), true);
    }

    public function testRoutePrefixIncludesBaseUrlPath(): void
    {
        $seen = $this->mounted('/base/some-application/me', '_routes-mount-test');
        $this->assertSame('/me', $seen['target']);
        $this->assertSame('/base/some-application/me', $seen['uriPath']);
        $this->assertSame('/base/some-application', $seen['routePrefix']);
    }

    public function testRootMountStripsBaseUrlPath(): void
    {
        $seen = $this->mounted('/base/some/where', '_routes-mount-root-test');
        $this->assertSame('/some/where', $seen['target']);
        $this->assertSame('/base/some/where', $seen['uriPath']);
        $this->assertSame('/base', $seen['routePrefix']);
    }
};

exit($test->run());
