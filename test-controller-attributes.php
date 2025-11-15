<?php
/**
 * Test script for attribute-based routing
 */

require_once __DIR__ . '/../../autoload.php';

use mini\Controller\AbstractController;
use mini\Controller\Attributes\GET;
use mini\Controller\Attributes\POST;
use mini\Controller\Attributes\Route;
use Psr\Http\Message\ResponseInterface;
use mini\Http\Message\ServerRequest;

// Test controller using attributes
class AttributeController extends AbstractController
{
    public function __construct()
    {
        parent::__construct();

        // Import routes from attributes instead of manual registration
        $this->router->importRoutesFromAttributes($this);
    }

    #[GET('/')]
    public function index(): ResponseInterface
    {
        echo "✓ index() called via attribute\n";
        return $this->json(['message' => 'index']);
    }

    #[GET('/users/{id}/')]
    public function show(int $id): ResponseInterface
    {
        echo "✓ show() called with id=$id (type: " . gettype($id) . ") via attribute\n";
        return $this->json(['message' => 'show', 'id' => $id]);
    }

    #[POST('/users/')]
    public function create(): ResponseInterface
    {
        echo "✓ create() called via attribute\n";
        return $this->json(['message' => 'created'], 201);
    }

    #[Route('/custom/', method: 'PATCH')]
    public function customMethod(): ResponseInterface
    {
        echo "✓ customMethod() called via Route attribute\n";
        return $this->json(['message' => 'patched']);
    }

    // This method has no attribute, should not be registered
    public function notARoute(): void
    {
        echo "❌ This should never be called!\n";
    }
}

// Helper to create test request
function createTestRequest(string $method, string $path): ServerRequest
{
    return new ServerRequest(
        method: $method,
        requestTarget: $path,
        body: '',
        headers: [],
        queryParams: null,
        serverParams: [],
        cookieParams: [],
        uploadedFiles: [],
        parsedBody: null,
        attributes: [],
        protocolVersion: '1.1'
    );
}

// Bootstrap Mini
\mini\bootstrap();

echo "Testing Attribute-Based Routing\n";
echo "================================\n\n";

$controller = new AttributeController();

// Test 1: GET /
echo "Test 1: GET /\n";
$request = createTestRequest('GET', '/');
$response = $controller->handle($request);
echo "  Status: " . $response->getStatusCode() . "\n";
echo "  Body: " . $response->getBody() . "\n\n";

// Test 2: GET /users/123/
echo "Test 2: GET /users/123/\n";
$request = createTestRequest('GET', '/users/123/');
$response = $controller->handle($request);
echo "  Status: " . $response->getStatusCode() . "\n";
echo "  Body: " . $response->getBody() . "\n\n";

// Test 3: POST /users/
echo "Test 3: POST /users/\n";
$request = createTestRequest('POST', '/users/');
$response = $controller->handle($request);
echo "  Status: " . $response->getStatusCode() . "\n";
echo "  Body: " . $response->getBody() . "\n\n";

// Test 4: PATCH /custom/
echo "Test 4: PATCH /custom/\n";
$request = createTestRequest('PATCH', '/custom/');
$response = $controller->handle($request);
echo "  Status: " . $response->getStatusCode() . "\n";
echo "  Body: " . $response->getBody() . "\n\n";

// Test 5: Method without attribute should 404
echo "Test 5: GET /notARoute/ (should throw NotFoundException)\n";
try {
    $request = createTestRequest('GET', '/notARoute/');
    $response = $controller->handle($request);
    echo "  ❌ Expected exception but got response\n";
} catch (\mini\Http\NotFoundException $e) {
    echo "  ✓ NotFoundException thrown: " . $e->getMessage() . "\n";
}

echo "\n✅ All attribute-based routing tests completed!\n";
