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

use mini\Controller\AbstractController;
use mini\Http\Message\Response;
use mini\Http\Message\ServerRequest;
use Psr\Http\Message\ResponseInterface;

echo "Testing Controller\\Router Trailing Slash Redirects\n";
echo "===================================================\n\n";

// Create a test controller
class TestSlashController extends AbstractController {
    public function __construct() {
        parent::__construct();
        $this->router->get('/users/', $this->withSlash(...));
        $this->router->get('/posts', $this->withoutSlash(...));
    }

    public function withSlash(): ResponseInterface {
        return new Response('Handler with slash', [], 200);
    }

    public function withoutSlash(): ResponseInterface {
        return new Response('Handler without slash', [], 200);
    }
}

$controller = new TestSlashController();

// Test helper
function testControllerRoute(TestSlashController $controller, string $path, int $expectedStatus, ?string $expectedLocation = null): void {
    global $testNum;
    $testNum = ($testNum ?? 0) + 1;

    $request = new ServerRequest('GET', $path, '', [], null, [], [], [], null, [], '1.1');

    try {
        $response = $controller->handle($request);
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
testControllerRoute($controller, '/users', 301, '/users/');

// Test 2: Route with slash accessed with slash should work normally
testControllerRoute($controller, '/users/', 200);

// Test 3: Route without slash accessed with slash should redirect
testControllerRoute($controller, '/posts/', 301, '/posts');

// Test 4: Route without slash accessed without slash should work normally
testControllerRoute($controller, '/posts', 200);

echo "\n✅ All Controller\\Router trailing slash tests completed!\n";
echo "\nTrailing slash behavior:\n";
echo "  - Routes defined with '/' only match paths with '/'\n";
echo "  - Routes defined without '/' only match paths without '/'\n";
echo "  - Wrong format triggers 301 redirect to correct format\n";
echo "  - Consistent with filesystem-based router behavior\n";
