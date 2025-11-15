<?php

/**
 * Test trailing slash redirects in Controller\Router
 */

$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../autoload.php')
    ?: realpath(__DIR__ . '/../../../../autoload.php');

if (!$autoloader) {
    fwrite(STDERR, "Error: Could not find composer autoloader\n");
    exit(1);
}

require $autoloader;

use mini\Controller\Router;
use mini\Http\Message\Response;
use Nyholm\Psr7\ServerRequest;

echo "Testing Controller\\Router Trailing Slash Redirects\n";
echo "===================================================\n\n";

// Create a mock controller
$controller = new class {
    public function withSlash(): Response {
        return new Response('Handler with slash', [], 200);
    }

    public function withoutSlash(): Response {
        return new Response('Handler without slash', [], 200);
    }
};

// Create router and register routes
$router = new Router($controller);
$router->get('/users/', [$controller, 'withSlash']);
$router->get('/posts', [$controller, 'withoutSlash']);

// Test helper
function testControllerRoute(Router $router, string $path, int $expectedStatus, ?string $expectedLocation = null): void {
    global $testNum;
    $testNum = ($testNum ?? 0) + 1;

    $request = new ServerRequest('GET', $path);

    try {
        $response = $router->dispatch($request);
        $status = $response->getStatusCode();
        $location = $response->getHeaderLine('Location');

        if ($status === $expectedStatus) {
            if ($expectedStatus === 301) {
                if ($location === $expectedLocation) {
                    echo "✓ Test $testNum: $path → 301 redirect to $expectedLocation\n";
                } else {
                    echo "✗ Test $testNum FAILED: Expected redirect to '$expectedLocation', got '$location'\n";
                }
            } else {
                echo "✓ Test $testNum: $path → $status (no redirect)\n";
            }
        } else {
            echo "✗ Test $testNum FAILED: Expected status $expectedStatus, got $status\n";
            echo "  Path: $path\n";
        }
    } catch (\Exception $e) {
        echo "✗ Test $testNum FAILED: Exception thrown\n";
        echo "  Path: $path\n";
        echo "  Error: {$e->getMessage()}\n";
    }
}

// Test 1: Route with slash accessed without slash should redirect
testControllerRoute($router, '/users', 301, '/users/');

// Test 2: Route with slash accessed with slash should work normally
testControllerRoute($router, '/users/', 200);

// Test 3: Route without slash accessed with slash should redirect
testControllerRoute($router, '/posts/', 301, '/posts');

// Test 4: Route without slash accessed without slash should work normally
testControllerRoute($router, '/posts', 200);

echo "\n✅ All Controller\\Router trailing slash tests completed!\n";
echo "\nTrailing slash behavior:\n";
echo "  - Routes defined with '/' only match paths with '/'\n";
echo "  - Routes defined without '/' only match paths without '/'\n";
echo "  - Wrong format triggers 301 redirect to correct format\n";
echo "  - Consistent with filesystem-based router behavior\n";
