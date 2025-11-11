<?php

declare(strict_types=1);

namespace mini\Util;

use mini\Contracts\PathInterface;
use Stringable;

/**
 * Cross-platform path manipulation utility
 *
 * Provides lexical path operations that work consistently across Unix-like systems
 * and Windows. All paths are stored internally using forward slashes and converted
 * to platform-native separators only when converted to strings.
 *
 * # Features
 *
 * - **Cross-platform**: Handles POSIX paths, Windows drive letters (C:/), and UNC paths (//server/share)
 * - **Lexical operations**: Path manipulation without filesystem access (join, canonical)
 * - **Filesystem resolution**: Optional realpath() for resolving symlinks and validating existence
 * - **Immutable**: All operations return new Path instances
 * - **Type-safe**: Implements PathInterface for consistent behavior
 *
 * # Usage Examples
 *
 * ```php
 * // Create and manipulate paths
 * $path = new Path('/var/www/html');
 * $file = $path->join('index.php'); // /var/www/html/index.php
 *
 * // Resolve relative paths
 * $config = Path::create('/var/www', '../config', 'app.php'); // /var/config/app.php
 *
 * // Get parent directory
 * $parent = $path->parent(); // /var/www
 *
 * // Check if path exists on filesystem
 * $real = $path->realpath(); // null if doesn't exist
 *
 * // Platform-native string representation
 * echo $path; // "/var/www/html" on Unix, "\\var\\www\\html" on Windows
 * ```
 *
 * # Path Semantics
 *
 * **Absolute paths** start with:
 * - POSIX root: `/` (e.g., `/var/www`)
 * - Windows drive: `C:/` (e.g., `C:/Users`)
 * - UNC share: `//` (e.g., `//server/share`)
 *
 * **Relative paths** are everything else:
 * - Simple: `foo/bar`
 * - Current directory: `.` or `./foo`
 * - Parent directory: `..` or `../foo`
 *
 * **Canonicalization** resolves `.` and `..` segments:
 * - `/foo/./bar` → `/foo/bar`
 * - `/foo/baz/../bar` → `/foo/bar`
 * - `foo/../../bar` → `../bar` (preserves unmatched `..` in relative paths)
 *
 * @see PathInterface For the interface this class implements
 */
final class Path implements PathInterface
{
    /**
     * Internal canonical representation:
     * - Always uses forward slashes (/)
     * - No trailing slash except for:
     *   - POSIX root: "/"
     *   - UNC root:   "//"
     *   - Drive root: "C:/"
     * - May contain "." and ".." (handled by canonical())
     */
    private string $path;

    /**
     * Create a new Path instance
     *
     * Accepts any path string and normalizes it to the internal representation.
     * Backslashes are converted to forward slashes, duplicate slashes are collapsed,
     * and trailing slashes are removed (except for root paths).
     *
     * @param PathInterface|Stringable|string $path The path to wrap
     *
     * @example
     * ```php
     * $path = new Path('/var/www/html');
     * $path = new Path('C:\\Users\\John'); // Windows path
     * $path = new Path('//server/share'); // UNC path
     * $path = new Path('../relative/path');
     * ```
     */
    public function __construct(PathInterface|Stringable|string $path)
    {
        $this->path = self::normalizeRaw((string) $path);
    }

    /**
     * Build a path from a base and zero or more additional parts (lexical only)
     *
     * Convenience factory method that joins multiple path segments and canonicalizes
     * the result. All parts are joined left-to-right using join() semantics, and the
     * final path is canonicalized to resolve `.` and `..` segments.
     *
     * This is a pure lexical operation with no filesystem access.
     *
     * @param PathInterface|Stringable|string $basePath The base path to start from
     * @param PathInterface|Stringable|string ...$parts Zero or more path segments to append
     * @return PathInterface The canonicalized joined path
     *
     * @example
     * ```php
     * // Join and canonicalize multiple segments
     * $path = Path::create('/var/www', 'app', 'public'); // /var/www/app/public
     *
     * // Resolves relative segments
     * $path = Path::create('/var/www', '../config', 'app.php'); // /var/config/app.php
     *
     * // Absolute target overrides base
     * $path = Path::create('/var/www', '/etc/config'); // /etc/config
     *
     * // Empty parts are ignored
     * $path = Path::create('/var', '', 'www', '.', 'html'); // /var/www/html
     * ```
     */
    public static function create(
        PathInterface|Stringable|string $basePath,
        PathInterface|Stringable|string ...$parts,
    ): PathInterface {
        // Wrap everything into our own Path to guarantee internal normalization.
        if ($basePath instanceof self) {
            $current = $basePath;
        } elseif ($basePath instanceof PathInterface) {
            $current = new self((string) $basePath);
        } else {
            $current = new self($basePath);
        }

        foreach ($parts as $part) {
            if (! $part instanceof PathInterface) {
                $part = new self($part);
            } elseif (! $part instanceof self) {
                $part = new self((string) $part);
            }

            $current = $current->join($part);
        }

        return $current->canonical();
    }

    /**
     * Build a path and resolve it against the filesystem (realpath())
     *
     * Convenience factory method that joins multiple path segments and then resolves
     * the result against the actual filesystem using PHP's realpath() function.
     *
     * This resolves symlinks, relative references, and validates that the path exists.
     * Returns null if the path doesn't exist or is inaccessible.
     *
     * @param PathInterface|Stringable|string $basePath The base path to start from
     * @param PathInterface|Stringable|string ...$parts Zero or more path segments to append
     * @return PathInterface|null The resolved absolute path, or null if path doesn't exist
     *
     * @example
     * ```php
     * // Resolve path on filesystem
     * $path = Path::resolve('/var/www', 'app', 'config.php');
     * // Returns actual canonical path if file exists, null otherwise
     *
     * // Resolves symlinks
     * $path = Path::resolve('/var/www/current'); // /var/www/releases/v1.2.3
     *
     * // Validates existence
     * if ($path = Path::resolve('/tmp', 'myfile.txt')) {
     *     // File exists and we have the real path
     * } else {
     *     // File doesn't exist
     * }
     * ```
     */
    public static function resolve(
        PathInterface|Stringable|string $basePath,
        PathInterface|Stringable|string ...$parts,
    ): ?PathInterface {
        $path = self::create($basePath, ...$parts);

        return $path->realpath();
    }

    /**
     * Convert path to string using platform-native separators
     *
     * Returns the path as a string using the appropriate directory separator
     * for the current platform:
     * - Windows: backslashes `\`
     * - Unix-like: forward slashes `/`
     *
     * @return string The path with platform-native separators
     *
     * @example
     * ```php
     * $path = new Path('/var/www/html');
     * echo $path; // "/var/www/html" on Unix
     *             // "\\var\\www\\html" on Windows
     *
     * // Use in file operations
     * $content = file_get_contents((string) $path);
     * ```
     */
    public function __toString(): string
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            return \str_replace('/', '\\', $this->path);
        }

        return $this->path;
    }

    /**
     * Join a target path to this path (lexical operation)
     *
     * Appends the target path to this path and returns a new Path instance.
     * This is a pure lexical operation with no filesystem access.
     *
     * **Behavior:**
     * - If target is absolute, it replaces this path (except for special Windows cases)
     * - If target is relative, it's appended to this path
     * - Result is automatically canonicalized
     *
     * **Windows-specific behavior:**
     * - Absolute target with drive (C:/) fully replaces base
     * - Rooted target (/foo) on Windows base (E:/...) becomes E:/foo (root of same drive)
     * - UNC target (//server/share) fully replaces base
     *
     * @param PathInterface|Stringable|string $target The path to join
     * @return PathInterface New Path instance with target joined
     *
     * @example
     * ```php
     * // Join relative path
     * $base = new Path('/var/www');
     * $path = $base->join('html/index.php'); // /var/www/html/index.php
     *
     * // Join with parent references
     * $path = $base->join('../config/app.php'); // /var/config/app.php
     *
     * // Absolute target replaces base
     * $path = $base->join('/etc/nginx'); // /etc/nginx
     *
     * // Windows drive behavior
     * $base = new Path('E:/projects/myapp');
     * $path = $base->join('/config'); // E:/config (root of E: drive)
     * $path = $base->join('C:/Windows'); // C:/Windows (different drive)
     * ```
     */
    public function join(PathInterface|Stringable|string $target): PathInterface
    {
        // Normalize target into our internal form ("/" separators, etc.)
        $targetRaw = self::normalizeRaw((string) $target);

        // Decide based on *normalized* target; we ignore target->isAbsolute()
        // from foreign implementations so we have consistent semantics.
        if (self::isAbsoluteRaw($targetRaw)) {
            // UNC or drive-absolute target: fully absolute, overrides base.
            if (\str_starts_with($targetRaw, '//') ||
                \preg_match('~^[A-Za-z]:/~', $targetRaw) === 1
            ) {
                return (new self($targetRaw))->canonical();
            }

            // Rooted without prefix ("/foo") on Windows base like "E:/...":
            // treat as "root of current drive".
            if (\preg_match('~^[A-Za-z]:/~', $this->path) === 1) {
                $drive = \substr($this->path, 0, 2); // "E:"
                $rest  = \ltrim($targetRaw, '/');    // strip leading "/"

                $newRaw = $rest === ''
                    ? $drive . '/'
                    : $drive . '/' . $rest;

                return (new self($newRaw))->canonical();
            }

            // Otherwise (POSIX-style base), just treat target as absolute root.
            return (new self($targetRaw))->canonical();
        }

        // Relative target: append to this path and canonicalize.
        $joined    = self::joinRaw($this->path, $targetRaw);
        $canonical = self::canonicalizeRaw($joined);

        return new self($canonical);
    }

    /**
     * Get the parent directory of this path (lexical operation)
     *
     * Returns the parent directory by removing the last path segment.
     * This is a pure lexical operation with no filesystem access.
     *
     * **Special cases:**
     * - Root paths (`/`, `C:/`, `//`) return themselves (no parent)
     * - Empty or `.` relative paths return `.` (current directory)
     * - Single segment relative paths return `.`
     *
     * @return PathInterface New Path instance representing the parent directory
     *
     * @example
     * ```php
     * // Standard cases
     * $path = new Path('/var/www/html');
     * $parent = $path->parent(); // /var/www
     *
     * $path = new Path('/var/www');
     * $parent = $path->parent(); // /var
     *
     * $path = new Path('/var');
     * $parent = $path->parent(); // /
     *
     * // Root has no parent
     * $path = new Path('/');
     * $parent = $path->parent(); // /
     *
     * // Relative paths
     * $path = new Path('foo/bar/baz');
     * $parent = $path->parent(); // foo/bar
     *
     * $path = new Path('foo');
     * $parent = $path->parent(); // .
     *
     * // Windows paths
     * $path = new Path('C:/Users/John/Documents');
     * $parent = $path->parent(); // C:/Users/John
     *
     * $path = new Path('C:/');
     * $parent = $path->parent(); // C:/
     * ```
     */
    public function parent(): PathInterface
    {
        $raw = self::canonicalizeRaw($this->path);

        [$prefix, $rest] = self::splitPrefix($raw);
        $segments = $rest === '' ? [] : \explode('/', $rest);

        // Absolute root / UNC root / drive root: parent is itself.
        if ($prefix !== '' && $segments === []) {
            return new self(self::buildPath($prefix, []));
        }

        // Relative empty or "." -> parent is "."
        if ($prefix === '' && ($rest === '' || $rest === '.')) {
            return new self('.');
        }

        if ($segments === []) {
            return new self('.');
        }

        // Drop last segment
        \array_pop($segments);

        // If nothing remains and relative, parent is "."
        if ($prefix === '' && $segments === []) {
            return new self('.');
        }

        return new self(self::buildPath($prefix, $segments));
    }

    /**
     * Get canonical (normalized) form of this path (lexical operation)
     *
     * Returns a new Path with all `.` and `..` segments resolved.
     * This is a pure lexical operation with no filesystem access.
     *
     * **Resolution rules:**
     * - `.` segments are removed
     * - `..` segments collapse the previous segment when safe
     * - In absolute paths, `..` cannot go above root
     * - In relative paths, unmatched `..` are preserved at the beginning
     *
     * @return PathInterface New canonicalized Path instance
     *
     * @example
     * ```php
     * // Remove current directory markers
     * $path = new Path('/var/./www/./html');
     * $canonical = $path->canonical(); // /var/www/html
     *
     * // Resolve parent directory references
     * $path = new Path('/var/www/../config/app.php');
     * $canonical = $path->canonical(); // /var/config/app.php
     *
     * // Multiple parent references
     * $path = new Path('/var/www/html/../../config');
     * $canonical = $path->canonical(); // /var/config
     *
     * // Cannot go above root
     * $path = new Path('/var/../../etc');
     * $canonical = $path->canonical(); // /etc
     *
     * // Relative paths preserve unmatched ..
     * $path = new Path('foo/../../bar');
     * $canonical = $path->canonical(); // ../bar
     *
     * // Complex relative path
     * $path = new Path('./foo/bar/../baz/./qux');
     * $canonical = $path->canonical(); // foo/baz/qux
     * ```
     */
    public function canonical(): PathInterface
    {
        return new self(self::canonicalizeRaw($this->path));
    }

    /**
     * Resolve path against filesystem (resolves symlinks, validates existence)
     *
     * Uses PHP's `realpath()` to resolve this path against the actual filesystem.
     * This resolves all symbolic links, relative references, and validates that
     * the path exists and is accessible.
     *
     * Returns null if the path doesn't exist or cannot be accessed.
     *
     * @return PathInterface|null The resolved absolute path, or null if path doesn't exist
     *
     * @example
     * ```php
     * // Resolve existing path
     * $path = new Path('/var/www/html');
     * $real = $path->realpath(); // /var/www/html (if exists)
     *
     * // Resolve symlink
     * $path = new Path('/var/www/current');
     * $real = $path->realpath(); // /var/www/releases/v1.2.3 (follows symlink)
     *
     * // Check if path exists
     * $path = new Path('/tmp/maybe-exists');
     * if ($real = $path->realpath()) {
     *     echo "Path exists: $real";
     * } else {
     *     echo "Path doesn't exist";
     * }
     *
     * // Resolve relative path
     * $path = new Path('../config/app.php');
     * $real = $path->realpath(); // /actual/absolute/path/config/app.php
     * ```
     */
    public function realpath(): ?PathInterface
    {
        // Use internal representation; PHP on Windows accepts "/" just fine.
        $resolved = \realpath($this->path);

        if ($resolved === false) {
            return null;
        }

        return new self($resolved);
    }

    /**
     * Check if this path is absolute
     *
     * A path is absolute if it starts with:
     * - POSIX root: `/`
     * - Windows drive: `C:/`, `D:/`, etc.
     * - UNC share: `//server/share`
     *
     * @return bool True if path is absolute, false if relative
     *
     * @example
     * ```php
     * // Absolute paths
     * (new Path('/var/www'))->isAbsolute(); // true
     * (new Path('C:/Users'))->isAbsolute(); // true
     * (new Path('//server/share'))->isAbsolute(); // true
     *
     * // Relative paths
     * (new Path('foo/bar'))->isAbsolute(); // false
     * (new Path('./config'))->isAbsolute(); // false
     * (new Path('../parent'))->isAbsolute(); // false
     * (new Path('.'))->isAbsolute(); // false
     * ```
     */
    public function isAbsolute(): bool
    {
        return self::isAbsoluteRaw($this->path);
    }

    /**
     * Check if this path is relative
     *
     * Convenience method that returns the negation of `isAbsolute()`.
     * A path is relative if it's not absolute.
     *
     * @return bool True if path is relative, false if absolute
     *
     * @example
     * ```php
     * // Relative paths
     * (new Path('foo/bar'))->isRelative(); // true
     * (new Path('./config'))->isRelative(); // true
     * (new Path('../parent'))->isRelative(); // true
     *
     * // Absolute paths
     * (new Path('/var/www'))->isRelative(); // false
     * (new Path('C:/Users'))->isRelative(); // false
     * ```
     */
    public function isRelative(): bool
    {
        return ! $this->isAbsolute();
    }

    /**
     * Normalize a raw path string to our internal representation:
     * - Convert backslashes to "/"
     * - Collapse duplicate slashes (except leading UNC "//")
     * - Strip trailing slash except for "/", "//" and drive roots "C:/"
     */
    private static function normalizeRaw(string $path): string
    {
        $path = \str_replace('\\', '/', $path);

        if ($path === '') {
            return '.';
        }

        // Preserve leading UNC "//" if present, collapse the rest.
        if (\str_starts_with($path, '//')) {
            $rest = \substr($path, 2);
            $rest = \preg_replace('#/{2,}#', '/', $rest) ?? $rest;
            $path = '//' . $rest;
        } else {
            $path = \preg_replace('#/{2,}#', '/', $path) ?? $path;
        }

        // Don't strip trailing slash for "/", "//" or drive root "C:/"
        if ($path !== '/' && $path !== '//' &&
            \preg_match('~^[A-Za-z]:/$~', $path) !== 1
        ) {
            $path = \rtrim($path, '/');
        }

        if ($path === '') {
            return '.';
        }

        return $path;
    }

    /**
     * Lexically canonicalize a normalized path:
     * - Removes "." segments
     * - Collapses "x/.." when safe
     * - Preserves leading ".." in relative paths
     */
    private static function canonicalizeRaw(string $path): string
    {
        $path = self::normalizeRaw($path);

        [$prefix, $rest] = self::splitPrefix($path);
        $segments = $rest === '' ? [] : \explode('/', $rest);

        $stack = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($stack !== []) {
                    $last = $stack[\count($stack) - 1];
                    if ($last !== '..') {
                        \array_pop($stack);
                        continue;
                    }
                }

                // Absolute path: do not go above root.
                if ($prefix !== '') {
                    continue;
                }

                // Relative path: preserve unmatched ".."
                $stack[] = '..';
                continue;
            }

            $stack[] = $segment;
        }

        return self::buildPath($prefix, $stack);
    }

    /**
     * Join two normalized path strings (base + relative target) lexically.
     */
    private static function joinRaw(string $base, string $target): string
    {
        $base   = self::normalizeRaw($base);
        $target = self::normalizeRaw($target);

        if ($target === '' || $target === '.') {
            return $base;
        }

        // If base is empty or ".", just return target.
        if ($base === '' || $base === '.') {
            return $target;
        }

        // Root-like bases already end with slash semantics.
        if ($base === '/' || $base === '//' ||
            \preg_match('~^[A-Za-z]:/$~', $base) === 1
        ) {
            return $base . $target;
        }

        return $base . '/' . $target;
    }

    /**
     * Decide if a normalized raw path is absolute.
     */
    private static function isAbsoluteRaw(string $p): bool
    {
        if ($p === '') {
            return false;
        }

        // UNC-style: //server/share
        if (\str_starts_with($p, '//')) {
            return true;
        }

        // POSIX root
        if ($p[0] === '/') {
            return true;
        }

        // Windows drive root / absolute: "C:/..."
        if (\preg_match('~^[A-Za-z]:/~', $p) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Split a normalized path into [prefix, rest].
     *
     * Prefix can be:
     * - ""   (relative)
     * - "/"  (POSIX root)
     * - "//" (UNC root)
     * - "C:" (Windows drive prefix)
     *
     * Rest is the remaining path without leading slashes.
     *
     * @return array{0:string,1:string}
     */
    private static function splitPrefix(string $path): array
    {
        // UNC-style: //server/share/...
        if (\str_starts_with($path, '//')) {
            $prefix = '//';
            $rest   = \substr($path, 2);
            $rest   = \ltrim($rest, '/');

            return [$prefix, $rest];
        }

        // Windows drive: "C:/..." (absolute) or we may see "C:/" (root)
        if (\preg_match('~^[A-Za-z]:/~', $path) === 1) {
            $prefix = \substr($path, 0, 2); // "C:"
            $rest   = \substr($path, 2);    // "/foo/bar"
            $rest   = \ltrim($rest, '/');

            return [$prefix, $rest];
        }

        // POSIX root: "/foo/bar"
        if ($path !== '' && $path[0] === '/') {
            $prefix = '/';
            $rest   = \substr($path, 1);
            $rest   = \ltrim($rest, '/');

            return [$prefix, $rest];
        }

        // Relative path
        $rest = \ltrim($path, '/');

        return ['', $rest];
    }

    /**
     * Build a normalized path string from prefix + segments.
     *
     * @param string   $prefix   See splitPrefix()
     * @param string[] $segments
     */
    private static function buildPath(string $prefix, array $segments): string
    {
        $rest = \implode('/', $segments);

        if ($prefix === '//') {
            if ($rest === '') {
                return '//';
            }

            return '//' . $rest;
        }

        if ($prefix !== '' && \preg_match('~^[A-Za-z]:$~', $prefix) === 1) {
            // Drive prefix: "C:" + "/foo/bar" or just "C:/"
            if ($rest === '') {
                return $prefix . '/';
            }

            return $prefix . '/' . $rest;
        }

        if ($prefix === '/') {
            if ($rest === '') {
                return '/';
            }

            return '/' . $rest;
        }

        // Relative
        if ($rest === '') {
            return '.';
        }

        return $rest;
    }
}
