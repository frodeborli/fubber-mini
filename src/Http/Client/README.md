# Http Client - PSR-18 HTTP Client

A lightweight PSR-18 compliant HTTP client built on curl.

## Usage

```php
use mini\Http\Client\HttpClient;

$client = new HttpClient();

// Simple GET
$response = $client->get('https://api.example.com/users');
echo $response->getStatusCode();  // 200
echo $response->getBody();        // {"users": [...]}

// POST with form data
$response = $client->post('https://api.example.com/users', [
    'name' => 'John',
    'email' => 'john@example.com',
]);

// POST with JSON
$response = $client->postJson('https://api.example.com/users', [
    'name' => 'John',
    'email' => 'john@example.com',
]);

// Custom headers
$response = $client->get('https://api.example.com/users', [
    'Authorization' => 'Bearer token123',
    'Accept' => 'application/json',
]);

// All HTTP methods
$client->get($url);
$client->post($url, $data);
$client->postJson($url, $data);
$client->put($url, $data);
$client->putJson($url, $data);
$client->patch($url, $data);
$client->patchJson($url, $data);
$client->delete($url);
$client->head($url);
$client->request($method, $url, $body, $headers);
```

## PSR-18 Interface

For library interoperability, use the PSR-18 `sendRequest()` method:

```php
use mini\Http\Message\Request;
use Psr\Http\Client\ClientInterface;

function fetchData(ClientInterface $client): array
{
    $request = new Request('GET', 'https://api.example.com/data');
    $response = $client->sendRequest($request);
    return json_decode((string) $response->getBody(), true);
}

$client = new HttpClient();
$data = fetchData($client);
```

## Configuration

```php
$client = new HttpClient([
    'timeout' => 30,           // Request timeout in seconds
    'connect_timeout' => 10,   // Connection timeout in seconds
    'verify_ssl' => true,      // Verify SSL certificates
    'follow_redirects' => true,// Follow 3xx redirects
    'max_redirects' => 5,      // Maximum redirects to follow
    'user_agent' => 'MyApp/1.0',
    'headers' => [             // Default headers for all requests
        'Accept' => 'application/json',
    ],
]);
```

## Error Handling

The client follows PSR-18 semantics:

- **4xx/5xx responses do NOT throw** - check `$response->getStatusCode()`
- **Network errors throw `NetworkException`** - DNS, connection, timeout
- **Malformed requests throw `RequestException`**

```php
use mini\Http\Client\NetworkException;
use mini\Http\Client\RequestException;

try {
    $response = $client->get('https://api.example.com/users');

    if ($response->getStatusCode() >= 400) {
        // Handle HTTP error
        echo "Error: " . $response->getStatusCode();
    }
} catch (NetworkException $e) {
    // Network error (DNS, timeout, connection refused)
    echo "Network error: " . $e->getMessage();
} catch (RequestException $e) {
    // Malformed request
    echo "Request error: " . $e->getMessage();
}
```

## Requirements

- PHP 8.3+
- ext-curl
