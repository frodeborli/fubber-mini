<?php
/**
 * Test script for Mini Controller implementation
 */

require_once __DIR__ . '/../../autoload.php';

use mini\Controller\AbstractController;
use Psr\Http\Message\ResponseInterface;

// Test controller
class TestController extends AbstractController
{
    public function __construct()
    {
        parent::__construct();

        // Register routes with type-aware patterns
        $this->router->get('/', $this->index(...));
        $this->router->get('/hello/', $this->hello(...));
        $this->router->get('/users/{id}/', $this->show(...));
        $this->router->get('/posts/{postId}/comments/{commentId}/', $this->nested(...));
        $this->router->post('/users/', $this->create(...));
    }

    public function index(): ResponseInterface
    {
        echo "✓ index() called\n";
        return $this->json(['message' => 'index']);
    }

    public function hello(): ResponseInterface
    {
        echo "✓ hello() called\n";
        return $this->json(['message' => 'hello']);
    }

    public function show(int $id): ResponseInterface
    {
        echo "✓ show() called with id=$id (type: " . gettype($id) . ")\n";

        return $this->json(['message' => 'show', 'id' => $id]);
    }

    public function nested(int $postId, int $commentId): ResponseInterface
    {
        echo "✓ nested() called with postId=$postId, commentId=$commentId\n";

        return $this->json([
            'message' => 'nested',
            'postId' => $postId,
            'commentId' => $commentId
        ]);
    }

    public function create(): ResponseInterface
    {
        echo "✓ create() called\n";
        return $this->json(['message' => 'created'], 201);
    }
}

// Helper to simulate PSR-7 request
function createTestRequest(string $method, string $path, array $query = [], array $body = []): Psr\Http\Message\ServerRequestInterface
{
    $requestTarget = $path . ($query ? '?' . http_build_query($query) : '');

    return new \mini\Http\Message\ServerRequest(
        method: $method,
        requestTarget: $requestTarget,
        body: '', // body
        headers: [], // headers
        queryParams: $query, // query params
        serverParams: [], // server params
        cookieParams: [], // cookies
        uploadedFiles: [], // uploaded files
        parsedBody: $body, // parsed body
        attributes: [], // attributes
        protocolVersion: '1.1'
    );
}

// Bootstrap Mini (transitions to Ready phase, enables Scoped services)
\mini\bootstrap();

// Run tests
echo "Testing Mini Controller Implementation\n";
echo "=====================================\n\n";

$controller = new TestController();

// Test 1: Index route
echo "Test 1: GET /\n";
$request = createTestRequest('GET', '/');
$response = $controller->handle($request);
echo "  Status: " . $response->getStatusCode() . "\n";
echo "  Body: " . $response->getBody() . "\n\n";

// Test 2: Simple route with query params
echo "Test 2: GET /hello/?name=World\n";
$request = createTestRequest('GET', '/hello/', ['name' => 'World']);
$response = $controller->handle($request);
echo "  Status: " . $response->getStatusCode() . "\n";
echo "  Body: " . $response->getBody() . "\n\n";

// Test 3: Route with int parameter
echo "Test 3: GET /users/123/\n";
$request = createTestRequest('GET', '/users/123/');
$response = $controller->handle($request);
echo "  Status: " . $response->getStatusCode() . "\n";
echo "  Body: " . $response->getBody() . "\n\n";

// Test 4: Nested route with multiple int parameters
echo "Test 4: GET /posts/456/comments/789/\n";
$request = createTestRequest('GET', '/posts/456/comments/789/');
$response = $controller->handle($request);
echo "  Status: " . $response->getStatusCode() . "\n";
echo "  Body: " . $response->getBody() . "\n\n";

// Test 5: POST with body
echo "Test 5: POST /users/\n";
$_POST = ['name' => 'John', 'email' => 'john@example.com'];
$request = createTestRequest('POST', '/users/', [], $_POST);
$response = $controller->handle($request);
echo "  Status: " . $response->getStatusCode() . "\n";
echo "  Body: " . $response->getBody() . "\n\n";

// Test 6: 404 Not Found
echo "Test 6: GET /notfound/ (should throw NotFoundException)\n";
try {
    $request = createTestRequest('GET', '/notfound/');
    $response = $controller->handle($request);
    echo "  ❌ Expected exception but got response\n";
} catch (\mini\Http\NotFoundException $e) {
    echo "  ✓ NotFoundException thrown: " . $e->getMessage() . "\n";
}

echo "\n✅ All tests completed!\n";
