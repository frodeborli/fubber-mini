<?php

namespace mini\Static;

use mini\Http\Message\Response;
use mini\Http\Message\Stream;
use mini\Mini;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Static File Serving Middleware
 *
 * Serves static files from _static/ directories using PathRegistry.
 * Files in _static/path/to/file.js are accessible at /path/to/file.js.
 *
 * Resolution order:
 * 1. Application: _static/ (or MINI_STATIC_ROOT)
 * 2. Framework: vendor/fubber/mini/_static/
 *
 * If file not found in static registry, passes request to next handler (router).
 */
class StaticFiles implements MiddlewareInterface
{
    private array $mimeTypes;

    public function __construct()
    {
        $this->mimeTypes = Mini::$mini->loadConfig('mimeTypes.php');
    }

    /**
     * Process request - serve static file or pass to next handler
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler Next handler (router)
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Extract path from request URI (strips query string)
        $path = parse_url($request->getRequestTarget(), PHP_URL_PATH);

        // Strip base URL path prefix if configured (e.g., /assistant/ -> /)
        $baseUrlPath = parse_url(Mini::$mini->baseUrl, PHP_URL_PATH);
        if ($baseUrlPath && str_starts_with($path, $baseUrlPath)) {
            $path = substr($path, strlen($baseUrlPath));
        }

        $path = ltrim($path, '/');

        // Try to find static file
        $filePath = $this->findAsset($path);

        // If not found, pass to next handler (router)
        if ($filePath === null) {
            return $handler->handle($request);
        }

        // Serve the static file
        return $this->serveFile($filePath, $request);
    }

    /**
     * Find static asset in PathRegistry
     *
     * @param string $assetPath Relative path (e.g., "logo.png" or "css/style.css")
     * @return string|null Full file system path, or null if not found
     */
    public function findAsset(string $assetPath): ?string
    {
        $filePath = Mini::$mini->paths->static->findFirst($assetPath);

        if ($filePath === null || !is_file($filePath)) {
            return null;
        }

        return $filePath;
    }

    /**
     * Get MIME type for file
     *
     * @param string $file File path or filename with extension
     * @return string MIME type (defaults to 'application/octet-stream')
     */
    public function getMimeType(string $file): string
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return $this->mimeTypes[$extension] ?? 'application/octet-stream';
    }

    /**
     * Serve static file with proper headers
     *
     * @param string $filePath Full file system path
     * @param ServerRequestInterface $request Original request
     * @return ResponseInterface
     */
    private function serveFile(string $filePath, ServerRequestInterface $request): ResponseInterface
    {
        $mimeType = $this->getMimeType($filePath);
        $fileSize = filesize($filePath);
        $lastModified = filemtime($filePath);
        $etag = '"' . md5($filePath . $lastModified . $fileSize) . '"';

        // Check conditional request headers (304 Not Modified)
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        $ifModifiedSince = $request->getHeaderLine('If-Modified-Since');

        if ($ifNoneMatch === $etag) {
            // ETag matches - return 304
            return new Response('', [
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ], 304);
        }

        if ($ifModifiedSince && strtotime($ifModifiedSince) >= $lastModified) {
            // File not modified since client's cached version - return 304
            return new Response('', [
                'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ], 304);
        }

        // Open file stream
        $stream = Stream::cast(fopen($filePath, 'rb'));

        // Return response with file content and proper headers
        return new Response($stream, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string)$fileSize,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
        ], 200);
    }
}
