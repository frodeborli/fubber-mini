<?php

/**
 * Test filesystem-based wildcard routing using "_" directories and files
 */

$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../autoload.php')
    ?: realpath(__DIR__ . '/../../../../autoload.php');

if (!$autoloader) {
    fwrite(STDERR, "Error: Could not find composer autoloader\n");
    exit(1);
}

require $autoloader;

use mini\Mini;
use mini\Router\Router;
use Nyholm\Psr7\ServerRequest;

echo "Testing Filesystem-Based Wildcard Routing\n";
echo "==========================================\n\n";

// Setup: Point routes to our test directory
$testRoutesPath = __DIR__ . '/_routes-wildcard-test';
Mini::$mini->paths->routes = new \mini\Util\PathsRegistry($testRoutesPath);

// Helper function to test routing
function testRoute(string $path, array $expectedGet, string $expectedHandler): void {
    global $testNum;
    $testNum = ($testNum ?? 0) + 1;

    // Clear $_GET before each test
    $_GET = [];

    // Create a test request
    $request = new ServerRequest('GET', $path);

    // Create router and handle request
    $router = new Router();

    try {
        ob_start();
        $response = $router->handle($request);
        $output = ob_get_clean();

        // Parse JSON response
        $data = json_decode($output, true);

        // Verify handler
        if ($data['handler'] !== $expectedHandler) {
            echo "✗ Test $testNum FAILED: Expected handler '$expectedHandler', got '{$data['handler']}'\n";
            echo "  Path: $path\n";
            return;
        }

        // Verify $_GET parameters
        $actualGet = array_filter($_GET, fn($k) => is_int($k), ARRAY_FILTER_USE_KEY);
        if ($actualGet !== $expectedGet) {
            echo "✗ Test $testNum FAILED: \$_GET mismatch\n";
            echo "  Path: $path\n";
            echo "  Expected: " . json_encode($expectedGet) . "\n";
            echo "  Actual:   " . json_encode($actualGet) . "\n";
            return;
        }

        echo "✓ Test $testNum: $path → {$data['handler']}\n";
        if (!empty($expectedGet)) {
            echo "  Captured: " . json_encode($expectedGet) . "\n";
        }

    } catch (\mini\Http\ResponseAlreadySentException $e) {
        // Classical PHP output - capture what was echoed
        $output = ob_get_clean();
        $data = json_decode($output, true);

        // Verify handler
        if ($data['handler'] !== $expectedHandler) {
            echo "✗ Test $testNum FAILED: Expected handler '$expectedHandler', got '{$data['handler']}'\n";
            echo "  Path: $path\n";
            return;
        }

        // Verify $_GET parameters
        $actualGet = array_filter($_GET, fn($k) => is_int($k), ARRAY_FILTER_USE_KEY);
        if ($actualGet !== $expectedGet) {
            echo "✗ Test $testNum FAILED: \$_GET mismatch\n";
            echo "  Path: $path\n";
            echo "  Expected: " . json_encode($expectedGet) . "\n";
            echo "  Actual:   " . json_encode($actualGet) . "\n";
            return;
        }

        echo "✓ Test $testNum: $path → {$data['handler']}\n";
        if (!empty($expectedGet)) {
            echo "  Captured: " . json_encode($expectedGet) . "\n";
        }

    } catch (\Exception $e) {
        ob_end_clean();
        echo "✗ Test $testNum FAILED: Exception thrown\n";
        echo "  Path: $path\n";
        echo "  Error: {$e->getMessage()}\n";
    }
}

// Helper function to test redirects
function testRedirect(string $path, string $expectedRedirectTo): void {
    global $testNum;
    $testNum = ($testNum ?? 0) + 1;

    // Clear $_GET before each test
    $_GET = [];

    // Create a test request
    $request = new ServerRequest('GET', $path);

    // Create router and handle request
    $router = new Router();

    try {
        ob_start();
        $response = $router->handle($request);
        ob_end_clean();

        // Check if it's a redirect response
        if ($response->getStatusCode() === 301) {
            $location = $response->getHeaderLine('Location');
            if ($location === $expectedRedirectTo) {
                echo "✓ Test $testNum: $path → 301 redirect to $expectedRedirectTo\n";
            } else {
                echo "✗ Test $testNum FAILED: Expected redirect to '$expectedRedirectTo', got '$location'\n";
                echo "  Path: $path\n";
            }
        } else {
            echo "✗ Test $testNum FAILED: Expected 301 redirect, got status {$response->getStatusCode()}\n";
            echo "  Path: $path\n";
        }

    } catch (\Exception $e) {
        ob_end_clean();
        echo "✗ Test $testNum FAILED: Expected redirect, got exception\n";
        echo "  Path: $path\n";
        echo "  Error: {$e->getMessage()}\n";
    }
}

// Test 1: Exact match takes precedence over wildcard
testRoute('/users/john', [], 'users/john.php');

// Test 2: Wildcard file matches when no exact match
testRoute('/users/123', [0 => '123'], 'users/_.php');

// Test 3: Wildcard directory with trailing slash
testRoute('/users/456/', [0 => '456'], 'users/_/index.php');

// Test 4: Nested path with wildcard
testRoute('/users/789/posts', [0 => '789'], 'users/_/posts.php');

// Test 5: Multiple wildcards
testRoute('/users/100/friendship/200', [0 => '100', 1 => '200'], 'users/_/friendship/_.php');

// Test 6: Wildcard with non-numeric values
testRoute('/users/alice', [0 => 'alice'], 'users/_.php');

// Test 7: Multiple wildcards with mixed values
testRoute('/users/bob/friendship/charlie', [0 => 'bob', 1 => 'charlie'], 'users/_/friendship/_.php');

echo "\n--- Trailing Slash Redirect Tests ---\n\n";

// Test 8: Request without slash should redirect if only index.php exists
// products/ has ONLY _/index.php, no _.php file
testRedirect('/products/widget', '/products/widget/');

// Test 9: Request with slash should redirect if only .php exists
// users/john.php exists but no users/john/ directory
testRedirect('/users/john/', '/users/john');

echo "\n✅ All wildcard routing tests completed!\n";
echo "\nWildcard routing features:\n";
echo "  - Use '_' as directory or file name to match any single segment\n";
echo "  - Exact matches take precedence over wildcards\n";
echo "  - Captured values stored in \$_GET[0], \$_GET[1], etc. (left to right)\n";
echo "  - Works for both files (_.php) and directories (_/index.php)\n";
echo "  - Falls back to __DEFAULT__.php if no wildcard match found\n";
echo "  - Automatic trailing slash redirects for SEO/consistency\n";
