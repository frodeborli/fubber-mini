<?php

namespace mini\Util;

/**
 * Registry for managing multiple paths with priority-based file resolution
 */
class PathsRegistry
{
    /** @var list<string> */
    private array $paths = [];

    public function __construct(string $primaryPath)
    {
        $this->paths[] = rtrim($primaryPath, '/');
    }

    public function addPath(string $path): void
    {
        $path = rtrim($path, '/');
        if (!in_array($path, $this->paths)) {
            $this->paths[] = $path;
        }
    }

    public function findFirst(string $filename): ?string
    {
        foreach ($this->paths as $path) {
            $fullPath = $path . '/' . ltrim($filename, '/');
            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }
        return null;
    }

    /**
     * @return list<string> All matching file paths in priority order
     */
    public function findAll(string $filename): array
    {
        $found = [];
        foreach ($this->paths as $path) {
            $fullPath = $path . '/' . ltrim($filename, '/');
            if (file_exists($fullPath)) {
                $found[] = $fullPath;
            }
        }
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