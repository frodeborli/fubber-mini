<?php
/**
 * Scoped routing of mounted PSR-15 handlers
 *
 * A route file returning a RequestHandlerInterface sees a request target
 * relative to its mount point, while getUri() keeps the full client URL and
 * the 'mini.router.routePrefix' attribute holds the consumed prefix.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Mini;
use mini\Router\Router;
use mini\Test;
use mini\Http\Message\ServerRequest;

$test = new class extends Test {

    private function mounted(string $path, string $fixtureDir = '_routes-mount-test'): array
    {
        Mini::$mini->paths->routes = new \mini\Util\PathsRegistry(dirname(__DIR__) . '/' . $fixtureDir);
        $response = (new Router())->handle(new ServerRequest('GET', $path, ''));
        $this->assertSame(200, $response->getStatusCode());
        return json_decode((string) $response->getBody(), true);
    }

    public function testSubfolderMountSeesPathBelowMountPoint(): void
    {
        $seen = $this->mounted('/some-application/me');
        $this->assertSame('/me', $seen['target']);
        $this->assertSame('/some-application/me', $seen['uriPath']);
        $this->assertSame('/some-application', $seen['routePrefix']);
    }

    public function testMountRootSeesSlash(): void
    {
        $seen = $this->mounted('/some-application/');
        $this->assertSame('/', $seen['target']);
        $this->assertSame('/some-application/', $seen['uriPath']);
        $this->assertSame('/some-application', $seen['routePrefix']);
    }

    public function testMountRootWithoutSlashRedirectsToSlash(): void
    {
        Mini::$mini->paths->routes = new \mini\Util\PathsRegistry(dirname(__DIR__) . '/_routes-mount-test');
        $response = (new Router())->handle(new ServerRequest('GET', '/some-application', ''));
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/some-application/', $response->getHeaderLine('Location'));
    }

    public function testQueryStringIsKeptInTargetAndUri(): void
    {
        $seen = $this->mounted('/some-application/me?a=1&b=2');
        $this->assertSame('/me?a=1&b=2', $seen['target']);
        $this->assertSame('/some-application/me', $seen['uriPath']);
        $this->assertSame('a=1&b=2', $seen['uriQuery']);
    }

    public function testRootMountLeavesPathUnchanged(): void
    {
        $seen = $this->mounted('/some/where?x=1', '_routes-mount-root-test');
        $this->assertSame('/some/where?x=1', $seen['target']);
        $this->assertSame('/some/where', $seen['uriPath']);
        $this->assertSame('', $seen['routePrefix']);
    }

    public function testWildcardMountStripsLiteralUrlPrefix(): void
    {
        // Filesystem path orgs/_/app is 10 chars; URL prefix /orgs/acme-corporation/app is 25
        $seen = $this->mounted('/orgs/acme-corporation/app/members/7');
        $this->assertSame('/members/7', $seen['target']);
        $this->assertSame('/orgs/acme-corporation/app/members/7', $seen['uriPath']);
        $this->assertSame('/orgs/acme-corporation/app', $seen['routePrefix']);
    }
};

exit($test->run());
