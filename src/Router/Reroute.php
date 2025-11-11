<?php

namespace mini\Router;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Exception for pattern-based routing in __DEFAULT__.php files
 *
 * Allows __DEFAULT__.php to define routing patterns for its directory scope.
 * The Router will match the current path against patterns and resolve the target.
 *
 * Only valid when thrown from __DEFAULT__.php files.
 * Patterns are relative to the directory containing the __DEFAULT__.php.
 *
 * Pattern targets can be:
 * - string: Request path (Router will resolve to file)
 * - Closure: Invoked with path parameters, returns request path string
 * - ResponseInterface: Direct response (future)
 * - RequestHandlerInterface: PSR-15 handler (future)
 *
 * IMPORTANT: Targets are REQUEST PATHS, not filenames!
 * Router resolves them: `_view?id=123` → tries `[_view.php, __DEFAULT__.php]`
 *
 * Examples:
 * ```php
 * // _routes/users/__DEFAULT__.php
 * throw new Reroute([
 *     '/{id}/' => fn($id) => "_view?id=$id",      // → resolves to _view.php
 *     '/{id}/edit' => '_edit',                     // → resolves to _edit.php
 *     '/create' => '_create',                      // → resolves to _create.php
 *     '/' => '_index',                             // → resolves to _index.php
 * ]);
 *
 * // _routes/blog/__DEFAULT__.php
 * throw new Reroute([
 *     '/{slug}/' => fn($slug) => "_post?slug=$slug",  // → resolves to _post.php
 *     '/' => 'index',                                  // → resolves to blog/index.php
 * ]);
 * ```
 *
 * Security:
 * Can route to underscore-prefixed files (internal routing).
 */
class Reroute extends \RuntimeException
{
    /**
     * @param array<string, string|ResponseInterface|RequestHandlerInterface|\Closure> $routes
     */
    public function __construct(
        public readonly array $routes,
        ?\Throwable $previous = null
    ) {
        parent::__construct("Reroute with " . count($routes) . " patterns", 0, $previous);
    }
}
