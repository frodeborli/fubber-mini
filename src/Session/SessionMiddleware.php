<?php

namespace mini\Session;

use mini\Mini;
use mini\Util\CacheControlHeader;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware that adds session cookies to responses
 *
 * This middleware runs AFTER the request is handled, checking if the session
 * needs a cookie set (new session, regenerated ID, or destroy). If so, it adds
 * the Set-Cookie header to the PSR-7 response.
 *
 * This approach:
 * - Integrates with PSR-7 response handling
 * - Works in Swoole/ReactPHP/phasync (no setcookie() calls)
 * - Is testable (no direct header manipulation)
 */
class SessionMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Process request through the rest of the chain
        $response = $handler->handle($request);

        // Check if session needs a cookie set
        try {
            $session = Mini::$mini->get(SessionInterface::class);
            $cookie = $session->getCookieToSet();

            if ($cookie !== null) {
                $response = $response->withAddedHeader('Set-Cookie', $this->formatCookie($cookie));
                $response = $this->applyCacheLimiter($response);
            }
        } catch (\Throwable) {
            // Session not available - nothing to do
        }

        return $response;
    }

    /**
     * Format cookie data as Set-Cookie header value
     *
     * @param array{name: string, value: string, options: array} $cookie
     * @return string
     */
    private function formatCookie(array $cookie): string
    {
        $parts = [
            rawurlencode($cookie['name']) . '=' . rawurlencode($cookie['value']),
        ];

        $options = $cookie['options'];

        if (isset($options['expires'])) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s T', $options['expires']);
        }

        if (isset($options['path'])) {
            $parts[] = 'Path=' . $options['path'];
        }

        if (isset($options['domain'])) {
            $parts[] = 'Domain=' . $options['domain'];
        }

        // Secure flag - check if request came over HTTPS
        if (!empty($options['secure']) || $this->isSecureRequest()) {
            $parts[] = 'Secure';
        }

        if (!empty($options['httponly'])) {
            $parts[] = 'HttpOnly';
        }

        if (isset($options['samesite'])) {
            $parts[] = 'SameSite=' . $options['samesite'];
        }

        return implode('; ', $parts);
    }

    /**
     * Check if the current request is over HTTPS
     */
    private function isSecureRequest(): bool
    {
        try {
            $request = Mini::$mini->get(ServerRequestInterface::class);
            return $request->getUri()->getScheme() === 'https';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Apply cache control headers based on session.cache_limiter
     *
     * Prevents caching of responses that set session cookies, which could
     * leak personalized data to other users via CDN/proxy caches.
     *
     * Always restricts caching - if app set Cache-Control, we make it more
     * restrictive (never less). Setting a session cookie and caching the
     * response is always a bug.
     */
    private function applyCacheLimiter(ResponseInterface $response): ResponseInterface
    {
        $limiter = ini_get('session.cache_limiter') ?: 'nocache';

        // 'none' means don't touch cache headers at all
        if ($limiter === 'none') {
            return $response;
        }

        // Parse existing Cache-Control (if any)
        $cacheControl = new CacheControlHeader(
            $response->hasHeader('Cache-Control') ? $response->getHeaderLine('Cache-Control') : null
        );

        $maxAge = session_cache_expire() * 60;

        switch ($limiter) {
            case 'public':
                // Public caching allowed, but cap TTL
                $cacheControl = $cacheControl->withMaxTtl($maxAge);
                if (!$cacheControl->has('public') && !$cacheControl->has('private') &&
                    !$cacheControl->has('no-cache') && !$cacheControl->has('no-store')) {
                    $cacheControl = $cacheControl->with('public');
                }
                break;

            case 'private':
                // Restrict to private (browser only), cap TTL
                $cacheControl = $cacheControl
                    ->withRestrictedVisibility('private')
                    ->withMaxTtl($maxAge);
                $response = $response->withHeader('Expires', 'Thu, 19 Nov 1981 08:52:00 GMT');
                break;

            case 'private_no_expire':
                // Restrict to private, don't set max-age
                $cacheControl = $cacheControl->withRestrictedVisibility('private');
                break;

            case 'nocache':
            default:
                // Most restrictive - no caching at all
                $cacheControl = $cacheControl
                    ->withNoStore()
                    ->with('no-cache')
                    ->with('must-revalidate');
                $response = $response
                    ->withHeader('Pragma', 'no-cache')
                    ->withHeader('Expires', 'Thu, 19 Nov 1981 08:52:00 GMT');
                break;
        }

        return $response->withHeader('Cache-Control', (string) $cacheControl);
    }
}
