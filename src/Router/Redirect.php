<?php

namespace mini\Router;

/**
 * Exception to redirect routing to a different target
 *
 * Used for internal routing control flow. Allows controllers to redirect
 * to other controller files using relative or absolute paths.
 *
 * Unlike client-facing redirects (301/302), this happens entirely within
 * the routing layer - no HTTP redirect is sent to the browser.
 *
 * Path resolution:
 * - Relative: `../admin/_dashboard` (resolved from current file's directory)
 * - Absolute: `/api/users` (resolved from _routes/)
 * - With query: `_user?id=123`
 *
 * The target is a REQUEST PATH, not a filename. Router will resolve it to a file.
 *
 * Security:
 * Can access underscore-prefixed files (internal routing only).
 * Client requests to underscore paths are blocked at entry.
 *
 * Examples:
 * ```php
 * // _routes/users/profile.php
 * if (!$authenticated) {
 *     throw new Redirect('../auth/login');
 * }
 *
 * // _routes/admin/index.php
 * throw new Redirect('_dashboard?section=overview');
 * ```
 */
class Redirect extends \RuntimeException
{
    public function __construct(
        public readonly string $target,
        ?\Throwable $previous = null
    ) {
        parent::__construct("Redirect to: $target", 0, $previous);
    }
}
