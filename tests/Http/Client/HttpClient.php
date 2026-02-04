<?php
/**
 * Test HTTP Client
 */

require __DIR__ . '/../../../ensure-autoloader.php';
require __DIR__ . '/../../assert.php';

use mini\Http\Client\HttpClient;
use mini\Http\Client\NetworkException;
use mini\Http\Message\Request;
use Psr\Http\Client\ClientInterface;

echo "Testing HttpClient...\n";

// Test: Implements PSR-18
$client = new HttpClient();
assert_true($client instanceof ClientInterface, 'Should implement ClientInterface');
echo "  ✓ Implements PSR-18 ClientInterface\n";

// Test: GET request to httpbin
$response = $client->get('https://httpbin.org/get');
assert_eq(200, $response->getStatusCode(), 'Should return 200');
assert_true($response->hasHeader('Content-Type'), 'Should have Content-Type header');
$body = (string) $response->getBody();
assert_true(str_contains($body, 'httpbin.org'), 'Response should contain httpbin.org');
echo "  ✓ GET request works\n";

// Test: POST request with form data
$response = $client->post('https://httpbin.org/post', ['foo' => 'bar', 'baz' => 'qux']);
assert_eq(200, $response->getStatusCode());
$data = json_decode((string) $response->getBody(), true);
assert_eq('bar', $data['form']['foo'] ?? null, 'POST data should be received');
echo "  ✓ POST with form data works\n";

// Test: POST request with JSON
$response = $client->postJson('https://httpbin.org/post', ['name' => 'Mini', 'version' => 1]);
assert_eq(200, $response->getStatusCode());
$data = json_decode((string) $response->getBody(), true);
$json = json_decode($data['data'] ?? '{}', true);
assert_eq('Mini', $json['name'] ?? null, 'JSON data should be received');
echo "  ✓ POST with JSON works\n";

// Test: Custom headers
$response = $client->get('https://httpbin.org/headers', ['X-Custom-Header' => 'TestValue']);
$data = json_decode((string) $response->getBody(), true);
assert_eq('TestValue', $data['headers']['X-Custom-Header'] ?? null, 'Custom header should be sent');
echo "  ✓ Custom headers work\n";

// Test: PSR-18 sendRequest method
$request = new Request('GET', 'https://httpbin.org/user-agent');
$response = $client->sendRequest($request);
assert_eq(200, $response->getStatusCode());
$data = json_decode((string) $response->getBody(), true);
assert_true(str_contains($data['user-agent'] ?? '', 'Mini'), 'User-Agent should contain Mini');
echo "  ✓ PSR-18 sendRequest() works\n";

// Test: Response headers
$response = $client->get('https://httpbin.org/response-headers?X-Test=Hello');
assert_eq('Hello', $response->getHeaderLine('X-Test'), 'Should parse response headers');
echo "  ✓ Response headers parsing works\n";

// Test: Follow redirects
$response = $client->get('https://httpbin.org/redirect/1');
assert_eq(200, $response->getStatusCode(), 'Should follow redirects');
echo "  ✓ Redirect following works\n";

// Test: HEAD request
$response = $client->head('https://httpbin.org/get');
assert_eq(200, $response->getStatusCode());
assert_eq('', (string) $response->getBody(), 'HEAD should have no body');
echo "  ✓ HEAD request works\n";

// Test: DELETE request
$response = $client->delete('https://httpbin.org/delete');
assert_eq(200, $response->getStatusCode());
echo "  ✓ DELETE request works\n";

// Test: PUT request
$response = $client->putJson('https://httpbin.org/put', ['updated' => true]);
assert_eq(200, $response->getStatusCode());
echo "  ✓ PUT request works\n";

// Test: PATCH request
$response = $client->patchJson('https://httpbin.org/patch', ['patched' => true]);
assert_eq(200, $response->getStatusCode());
echo "  ✓ PATCH request works\n";

// Test: Network error throws NetworkException
$thrown = false;
try {
    $client->get('https://this-domain-does-not-exist-12345.invalid/');
} catch (NetworkException $e) {
    $thrown = true;
    assert_true($e->getRequest() !== null, 'Exception should have request');
}
assert_true($thrown, 'Should throw NetworkException for DNS errors');
echo "  ✓ NetworkException thrown on network errors\n";

// Test: 4xx/5xx responses don't throw (PSR-18 requirement)
$response = $client->get('https://httpbin.org/status/404');
assert_eq(404, $response->getStatusCode(), '404 should not throw');
echo "  ✓ 4xx responses don\'t throw (PSR-18 compliant)\n";

$response = $client->get('https://httpbin.org/status/500');
assert_eq(500, $response->getStatusCode(), '500 should not throw');
echo "  ✓ 5xx responses don\'t throw (PSR-18 compliant)\n";

// Test: Custom options
$customClient = new HttpClient([
    'timeout' => 5,
    'user_agent' => 'CustomAgent/1.0',
]);
$response = $customClient->get('https://httpbin.org/user-agent');
$data = json_decode((string) $response->getBody(), true);
assert_eq('CustomAgent/1.0', $data['user-agent'] ?? null, 'Custom user-agent should work');
echo "  ✓ Custom options work\n";

echo "\nAll HttpClient tests passed!\n";
