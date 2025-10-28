<?php

namespace mini\Util;

/**
 * Registry for managing multiple paths with priority-based file resolution
 */
class PathsRegistry
{
    /** @var list<string> */
    private array $paths = [];
    private array $cacheFirst = [];
    private array $cacheAll = [];

    public function __construct(string $primaryPath)
    {
        $this->paths[] = rtrim($primaryPath, '/');
    }

    public function addPath(string $path): void
    {
        $this->cacheFirst = [];
        $this->cacheAll = [];
        $path = rtrim($path, '/');
        if (!in_array($path, $this->paths)) {
            $this->paths[] = $path;
        }
    }

    public function findFirst(string $filename): ?string
    {
        if (isset($this->cacheFirst[$filename]) || \array_key_exists($filename, $this->cacheFirst)) {
            return $this->cacheFirst[$filename];
        }
        foreach ($this->paths as $path) {
            $fullPath = $path . '/' . ltrim($filename, '/');
            if (file_exists($fullPath)) {
                $this->cacheFirst[$filename] = $fullPath;
                return $fullPath;
            }
        }
        $this->cacheFirst[$filename] = null;
        return null;
    }

    /**
     * @return list<string> All matching file paths in priority order
     */
    public function findAll(string $filename): array
    {
        if (isset($this->cacheAll[$filename])) {
            return $this->cacheAll[$filename];
        }
        $found = [];
        foreach ($this->paths as $path) {
            $fullPath = $path . '/' . ltrim($filename, '/');
            if (file_exists($fullPath)) {
                $found[] = $fullPath;
            }
        }
        $this->cacheAll[$filename] = $found;
        return $found;
    }

    /**
     * @return list<string>
     */
    public function getPaths(): array
    {
        return $this->paths;
    }
}