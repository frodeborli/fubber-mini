<?php
namespace mini\Contracts;

use Stringable;

interface PathInterface extends Stringable
{
    /**
     * Join a target path to this path (lexically) and return a new Path.
     *
     * If $target is absolute, it usually overrides $this and is returned
     * (canonicalized). If $target is relative, it is appended to $this
     * and the result is lexically canonicalized (no filesystem access).
     */
    public function join(PathInterface|Stringable|string $target): PathInterface;

    /**
     * Lexical parent of this path.
     *
     * For non-root paths, this is the directory above.
     * For the root path, implementations typically return the root itself.
     */
    public function parent(): PathInterface;

    /**
     * Pure lexical canonicalization (no filesystem access).
     *
     * - Normalizes separators (e.g. backslash vs slash depending on platform)
     * - Removes "." segments
     * - Collapses "x/.." when safe
     * - Preserves leading ".." in relative paths
     */
    public function canonical(): PathInterface;

    /**
     * Filesystem-based resolution of the path.
     *
     * Typically wraps PHP's realpath():
     * - Returns an absolute, canonical Path if the path exists
     * - Returns null if it does not exist or cannot be resolved
     */
    public function realpath(): ?PathInterface;

    /**
     * Whether this path is absolute on the current platform semantics.
     */
    public function isAbsolute(): bool;

    /**
     * Convenience negation of isAbsolute().
     */
    public function isRelative(): bool;
}
