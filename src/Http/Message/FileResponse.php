<?php
namespace mini\Http\Message;

/**
 * Response for serving files with automatic MIME type detection
 *
 * Serves files from the filesystem with proper Content-Type, Content-Length,
 * and optional Content-Disposition headers for downloads.
 *
 *     // Serve an image inline (displayed in browser)
 *     return new FileResponse('/path/to/image.png');
 *
 *     // Serve a file as download
 *     return new FileResponse('/path/to/file.pdf', download: true);
 *
 *     // Serve with custom filename for download
 *     return new FileResponse('/path/to/file.pdf', download: 'report.pdf');
 *
 *     // Override MIME type
 *     return new FileResponse('/path/to/file', mimeType: 'application/octet-stream');
 */
class FileResponse extends Response {

    /**
     * Common MIME types by extension
     */
    private const MIME_TYPES = [
        // Images
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'bmp' => 'image/bmp',

        // Documents
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',

        // Text
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'md' => 'text/markdown',

        // Archives
        'zip' => 'application/zip',
        'gz' => 'application/gzip',
        'tar' => 'application/x-tar',
        'rar' => 'application/vnd.rar',
        '7z' => 'application/x-7z-compressed',

        // Audio
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',

        // Video
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'avi' => 'video/x-msvideo',

        // Fonts
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',
    ];

    /**
     * @param string $filePath       Absolute path to the file
     * @param bool|string $download  false = inline, true = download with original name, string = download with custom name
     * @param string|null $mimeType  Override MIME type detection
     * @param int $statusCode        HTTP status code (default 200)
     */
    public function __construct(
        string $filePath,
        bool|string $download = false,
        ?string $mimeType = null,
        int $statusCode = 200
    ) {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: $filePath");
        }

        if (!is_readable($filePath)) {
            throw new \InvalidArgumentException("File not readable: $filePath");
        }

        $fp = fopen($filePath, 'rb');
        if ($fp === false) {
            throw new \RuntimeException("Failed to open file: $filePath");
        }
        $body = Stream::cast($fp);

        if ($mimeType === null) {
            $mimeType = $this->detectMimeType($filePath);
        }

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) filesize($filePath),
        ];

        if ($download !== false) {
            $filename = is_string($download) ? $download : basename($filePath);
            $filename = preg_replace('/[^\w\.\-]/', '_', $filename);
            $headers['Content-Disposition'] = 'attachment; filename="' . $filename . '"';
        }

        parent::__construct($body, $headers, $statusCode);
    }

    /**
     * Detect MIME type from file extension or content
     */
    private function detectMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // Check our mapping first (faster and more reliable for common types)
        if (isset(self::MIME_TYPES[$extension])) {
            return self::MIME_TYPES[$extension];
        }

        // Try finfo if available
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $filePath);
            finfo_close($finfo);
            if ($mimeType && $mimeType !== 'application/octet-stream') {
                return $mimeType;
            }
        }

        // Default fallback
        return 'application/octet-stream';
    }
}
