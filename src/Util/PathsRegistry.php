<?php

namespace mini\Util;

/**
 * Registry for managing multiple paths with priority-based file resolution
 *
 * The primary path is always checked first, followed by fallback paths in reverse order
 * of addition (most recently added fallback takes precedence over earlier fallbacks).
 *
 * This design works naturally with Composer's dependency graph loading order:
 * - Dependencies load first (e.g., fubber/mini)
 * - Then packages that depend on them (e.g., fubber/some-bundle)
 * - Finally the application itself
 *
 * When each loads and calls addPath(), the most recent (application) will be checked
 * before earlier ones (bundle before framework), allowing natural override cascading.
 *
 * Example:
 * ```php
 * $registry = new PathsRegistry('/app/resources');      // Primary path (app)
 * $registry->addPath('/vendor/mini/resources');         // Framework fallback
 * $registry->addPath('/vendor/some-bundle/resources');  // Bundle fallback
 *
 * // Resolution order: /app/resources → /vendor/some-bundle/resources → /vendor/mini/resources
 * // App overrides bundle, bundle overrides framework
 * ```
 *
 * Results are cached per filename until addPath() is called again.
 */
class PathsRegistry
{
    private string $primaryPath;
    /** @var list<string> Fallback paths in reverse order (most recent first) */
    private array $fallbackPaths = [];
    private array $cacheFirst = [];
    private array $cacheAll = [];
    private string $cachePrefix;

    /**
     * Create a new paths registry with a primary path
     *
     * @param string $primaryPath The primary path that is always checked first
     */
    public function __construct(string $primaryPath)
    {
        $this->primaryPath = rtrim($primaryPath, '/');
        $this->updateCachePrefix();
    }

    /**
     * Update cache prefix after paths change
     */
    private function updateCachePrefix(): void
    {
        $this->cachePrefix = 'path:' . md5($this->primaryPath . ':' . implode(':', $this->fallbackPaths)) . ':';
    }

    /**
     * Add a fallback path
     *
     * Fallback paths are prepended, so the most recently added fallback is checked
     * before earlier fallbacks. Duplicate paths and paths matching the primary path
     * are silently ignored.
     *
     * Cache is cleared when a new path is added.
     *
     * @param string $path The fallback path to add
     */
    public function addPath(string $path): void
    {
        $this->cacheFirst = [];
        $this->cacheAll = [];
        $path = rtrim($path, '/');
        if ($path === $this->primaryPath || in_array($path, $this->fallbackPaths)) {
            return;
        }
        // Prepend to fallback paths so most recent additions are checked first
        array_unshift($this->fallbackPaths, $path);
        $this->updateCachePrefix();
    }

    /**
     * Find the first occurrence of a file across all paths
     *
     * Searches in priority order: primary path first, then fallback paths from most
     * recently added to earliest. Returns the full path to the first match found,
     * or null if the file doesn't exist in any path.
     *
     * Results are cached in-memory (per-request) and in APCu (across requests).
     *
     * @param string $filename Relative filename to search for
     * @return string|null Full path to first match, or null if not found
     */
    public function findFirst(string $filename): ?string
    {
        // L1: In-memory cache (fastest)
        if (isset($this->cacheFirst[$filename]) || \array_key_exists($filename, $this->cacheFirst)) {
            return $this->cacheFirst[$filename];
        }

        // Resolver function - used by both APCu and direct call paths
        $resolver = function() use ($filename) {
            // Check primary path first
            $fullPath = $this->primaryPath . '/' . ltrim($filename, '/');
            if (file_exists($fullPath)) {
                return $fullPath;
            }

            // Then check fallback paths (most recent first)
            foreach ($this->fallbackPaths as $path) {
                $fullPath = $path . '/' . ltrim($filename, '/');
                if (file_exists($fullPath)) {
                    return $fullPath;
                }
            }

            return null;
        };

        $result = apcu_entry($this->cachePrefix . $filename, $resolver, ttl: 1);

        // Note; probably a PHP bug (or poor design choice) - apcu_entry callback
        // not called if APCu disabled
        if ($result === null && !apcu_enabled()) {
            $result = $resolver();
        }

        $this->cacheFirst[$filename] = $result;
        return $result;
    }

    /**
     * Find all occurrences of a file across all paths
     *
     * Searches in priority order: primary path first, then fallback paths from most
     * recently added to earliest. Returns an array of all full paths where the file
     * exists, in priority order.
     *
     * Results are cached in-memory (per-request) and in APCu (across requests).
     *
     * @param string $filename Relative filename to search for
     * @return list<string> All matching file paths in priority order
     */
    public function findAll(string $filename): array
    {
        // L1: In-memory cache (fastest)
        if (isset($this->cacheAll[$filename])) {
            return $this->cacheAll[$filename];
        }

        $resolver = function() use ($filename) {
            $found = [];

            // Check primary path first
            $fullPath = $this->primaryPath . '/' . ltrim($filename, '/');
            if (file_exists($fullPath)) {
                $found[] = $fullPath;
            }

            // Then check fallback paths (most recent first)
            foreach ($this->fallbackPaths as $path) {
                $fullPath = $path . '/' . ltrim($filename, '/');
                if (file_exists($fullPath)) {
                    $found[] = $fullPath;
                }
            }

            return $found;
        };

        // L2: APCu cache (survives across requests, 1s TTL for hot paths)
        $result = apcu_entry($this->cachePrefix . 'all:' . $filename, $resolver, ttl: 1);

        if ($result === null && !apcu_enabled()) {
            $result = $resolver();
        }

        $this->cacheAll[$filename] = $result;
        return $result;
    }

    /**
     * Get all registered paths in resolution order
     *
     * @return list<string> All paths in resolution order (primary first, then fallbacks from most recent to earliest)
     */
    public function getPaths(): array
    {
        return array_merge([$this->primaryPath], $this->fallbackPaths);
    }
}