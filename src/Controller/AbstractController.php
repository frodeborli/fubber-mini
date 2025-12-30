<?php

namespace mini\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Base controller class with routing and response helpers
 *
 * Controllers extend this class to handle HTTP requests using an internal router.
 * Route registration uses type-aware patterns - the router analyzes method signatures
 * to generate appropriate regex patterns for URL parameters.
 *
 * Example:
 * ```php
 * class UserController extends AbstractController
 * {
 *     public function __construct()
 *     {
 *         parent::__construct();
 *
 *         $this->router->get('/', $this->index(...));
 *         $this->router->get('/{id}/', $this->show(...));
 *         $this->router->post('/', $this->create(...));
 *     }
 *
 *     public function index(): ResponseInterface
 *     {
 *         $users = User::query()->limit(100);
 *         return $this->respond(iterator_to_array($users));
 *     }
 *
 *     public function show(int $id): ResponseInterface
 *     {
 *         $user = User::find($id);
 *         if (!$user) throw new \mini\Exceptions\NotFoundException();
 *         return $this->respond($user);
 *     }
 * }
 * ```
 *
 * Mount in Mini router:
 * ```php
 * // _routes/users/__DEFAULT__.php
 * return new UserController();
 * ```
 *
 * @package mini\Controller
 */
abstract class AbstractController implements RequestHandlerInterface
{
    /**
     * Internal router for this controller
     *
     * Use to register routes:
     * - $this->router->get($path, $handler)
     * - $this->router->post($path, $handler)
     * - $this->router->patch($path, $handler)
     * - $this->router->put($path, $handler)
     * - $this->router->delete($path, $handler)
     * - $this->router->any($path, $handler)
     */
    public readonly Router $router;

    public function __construct()
    {
        $this->router = new Router();
        $this->router->importRoutesFromAttributes($this);
    }

    /**
     * PSR-15 entry point
     *
     * Flow:
     * 1. Router::match() finds matching route and returns handler + params (or redirect)
     * 2. Enrich request with type-cast parameters as attributes
     * 3. Create ConverterHandler wrapping the matched controller method
     * 4. ConverterHandler invokes method and converts return value to ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Match route
        $match = $this->router->match($request);

        // Handle redirects (trailing slash normalization)
        if ($match instanceof ResponseInterface) {
            return $match;
        }

        // Enrich request with URL parameters as attributes
        foreach ($match['params'] as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        // Create ConverterHandler and invoke controller method
        $converterHandler = new ConverterHandler($match['handler']);
        return $converterHandler->handle($request);
    }

    /**
     * Content negotiation response
     *
     * Checks Accept header to determine response format:
     * - application/json → JSON response
     * - text/html → Renders view if exists, otherwise JSON
     * - wildcard or empty → Prefers HTML if view exists, otherwise JSON
     *
     * This enables API-first development with progressive HTML enhancement.
     *
     * @param mixed $data Data to respond with
     * @param int $status HTTP status code
     * @param array $headers Additional headers ['Header-Name' => 'value']
     * @return ResponseInterface
     */
    protected function respond(mixed $data, int $status = 200, array $headers = []): ResponseInterface
    {
        // Check if client accepts HTML
        if ($this->acceptsHtml()) {
            // Try to find view for this route
            $view = $this->findViewForCurrentRoute();

            if ($view) {
                $html = \mini\render($view, ['data' => $data]);
                return $this->html($html, $status, $headers);
            }
        }

        // Fallback to JSON (also handles explicit application/json Accept)
        return $this->json($data, $status, $headers);
    }

    /**
     * Explicit JSON response
     *
     * Always returns JSON regardless of Accept header.
     *
     * @param mixed $data Data to encode as JSON
     * @param int $status HTTP status code
     * @param array $headers Additional headers
     * @return ResponseInterface
     */
    protected function json(mixed $data, int $status = 200, array $headers = []): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $response = new \mini\Http\Message\Response($json, ['Content-Type' => 'application/json'], $status);

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Explicit HTML response
     *
     * @param string $body HTML content
     * @param int $status HTTP status code
     * @param array $headers Additional headers
     * @return ResponseInterface
     */
    protected function html(string $body, int $status = 200, array $headers = []): ResponseInterface
    {
        $response = new \mini\Http\Message\Response($body, ['Content-Type' => 'text/html; charset=utf-8'], $status);

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Plain text response
     *
     * @param string $body Response body
     * @param int $status HTTP status code
     * @param array $headers Additional headers
     * @return ResponseInterface
     */
    protected function text(string $body, int $status = 200, array $headers = []): ResponseInterface
    {
        $response = new \mini\Http\Message\Response($body, ['Content-Type' => 'text/plain; charset=utf-8'], $status);

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Empty response (for 204 No Content, etc.)
     *
     * @param int $status HTTP status code
     * @param array $headers Additional headers
     * @return ResponseInterface
     */
    protected function empty(int $status = 204, array $headers = []): ResponseInterface
    {
        $response = new \mini\Http\Message\Response('', [], $status);

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Redirect response
     *
     * @param string $url Redirect target URL
     * @param int $status HTTP status code (301 permanent, 302 temporary, 303 see other)
     * @return ResponseInterface
     */
    protected function redirect(string $url, int $status = 302): ResponseInterface
    {
        return new \mini\Http\Message\Response('', ['Location' => $url], $status);
    }

    /**
     * Check if client accepts HTML
     *
     * @return bool
     */
    private function acceptsHtml(): bool
    {
        $accept = \mini\request()->getHeaderLine('Accept');

        // No Accept header or */* - assume HTML preference
        if (empty($accept) || $accept === '*/*') {
            return true;
        }

        // Parse Accept header for quality values
        $types = $this->parseAcceptHeader($accept);

        // Check if text/html is accepted and preferred over application/json
        $htmlQuality = $types['text/html'] ?? 0;
        $jsonQuality = $types['application/json'] ?? 0;

        return $htmlQuality > 0 && $htmlQuality >= $jsonQuality;
    }

    /**
     * Parse Accept header into quality-weighted array
     *
     * @param string $accept Accept header value
     * @return array Type => quality mapping
     */
    private function parseAcceptHeader(string $accept): array
    {
        $types = [];

        foreach (explode(',', $accept) as $part) {
            $part = trim($part);

            // Parse "type/subtype;q=0.8"
            if (preg_match('/^([^;]+)(?:;q=([0-9.]+))?$/', $part, $matches)) {
                $type = trim($matches[1]);
                $quality = isset($matches[2]) ? (float)$matches[2] : 1.0;

                $types[$type] = $quality;

                // Handle wildcards
                if ($type === '*/*') {
                    $types['text/html'] = max($types['text/html'] ?? 0, $quality);
                    $types['application/json'] = max($types['application/json'] ?? 0, $quality);
                }
            }
        }

        return $types;
    }

    /**
     * Find view template for current route
     *
     * Maps controller route to view path:
     * - UserController::index() → users/index.php
     * - UserController::show() → users/show.php
     * - PostController::showPost() → posts/show.php
     *
     * @return string|null View path or null if not found
     */
    private function findViewForCurrentRoute(): ?string
    {
        // Get controller class name
        $className = get_class($this);
        $shortName = (new \ReflectionClass($this))->getShortName();

        // Strip "Controller" suffix: UserController → User
        $resource = preg_replace('/Controller$/', '', $shortName);
        $resource = strtolower($resource);

        // Get current method being called from backtrace
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $method = null;
        foreach ($trace as $frame) {
            if (isset($frame['class']) && $frame['class'] === $className && $frame['function'] !== 'handle') {
                $method = $frame['function'];
                break;
            }
        }

        if (!$method) {
            return null;
        }

        // Map method names to view names
        // index → index, show → show, showPost → show, createComment → create, etc.
        $viewName = $this->methodToViewName($method);

        // Try to find view: users/index.php, users/show.php, etc.
        $viewPath = $resource . '/' . $viewName . '.php';

        $foundPath = \mini\Mini::$mini->paths->views->findFirst($viewPath);

        return $foundPath ? $viewPath : null;
    }

    /**
     * Convert controller method name to view name
     *
     * @param string $method Controller method name
     * @return string View name
     */
    private function methodToViewName(string $method): string
    {
        // Common mappings
        $map = [
            'index' => 'index',
            'show' => 'show',
            'create' => 'create',
            'update' => 'update',
            'edit' => 'edit',
            'delete' => 'delete',
        ];

        if (isset($map[$method])) {
            return $map[$method];
        }

        // Try to extract action from method name
        // showPost → show, createComment → create, listPosts → list
        foreach (['show', 'create', 'update', 'edit', 'delete', 'list'] as $action) {
            if (str_starts_with($method, $action)) {
                return $action;
            }
        }

        // Fallback: use method name as-is
        return $method;
    }
}
