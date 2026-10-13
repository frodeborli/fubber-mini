<?php

namespace mini\Template;

use mini\Mini;

/**
 * Resolves a template source file to its compiled, opcache-friendly PHP
 * file, (re)compiling it with TemplateCompiler when needed.
 *
 * Compiled files live under Mini's cache directory, mirroring the
 * template's path relative to the project root - e.g. a template at
 * `_views/blog/post.php` compiles to `<cache>/templates/_views/blog/post.php`.
 * The path is never hashed or otherwise obscured: a backtrace through the
 * compiled file makes the source template obvious at a glance.
 *
 * The cache directory defaults to `<project root>/_cache`, overridable via
 * the `MINI_CACHE_ROOT` environment variable - the same convention
 * `MINI_VIEWS_ROOT`/`MINI_STATIC_ROOT`/`MINI_CONFIG_ROOT` use.
 *
 * Compiled files are ordinary PHP files, so opcache caches them like any
 * other included file. Recompilation happens when the source is newer
 * than the compiled file (or the compiled file doesn't exist yet), and the
 * new file is written atomically - compiled to a temp file in the same
 * directory, then rename()d into place - so many php-fpm workers racing to
 * compile the same template at once is harmless: compilation is a pure
 * function of the source, so every racing writer produces byte-identical
 * output, and rename() never leaves a reader looking at a half-written
 * file. opcache_invalidate() is called on every recompile, so this also
 * works correctly with opcache.validate_timestamps=0.
 */
final class CompiledTemplateCache
{
    /**
     * Get the compiled PHP file for a template source file, (re)compiling
     * it first if it's missing or older than the source.
     *
     * @param string $sourcePath Absolute path to the template source file.
     * @return string Absolute path to the compiled PHP file.
     * @throws TemplateCompileException If the template uses a forbidden construct.
     */
    public static function getCompiledPath(string $sourcePath): string
    {
        // Normalize away any ".." or "." segments - findFirst() can return
        // one (e.g. a package registers its views path as __DIR__ . '/../_views',
        // per the Template README), and an un-normalized path would mirror
        // into a cache path containing "..", breaking both the mkdir below
        // and the backtrace's readability.
        $sourcePath = realpath($sourcePath) ?: $sourcePath;

        $sourceMTime = filemtime($sourcePath);
        if ($sourceMTime === false) {
            throw new \RuntimeException("Cannot stat template source: $sourcePath");
        }

        $compiledPath = self::resolveCompiledPath($sourcePath);
        $compiledMTime = @filemtime($compiledPath);
        if ($compiledMTime !== false && $compiledMTime >= $sourceMTime) {
            return $compiledPath;
        }

        self::compileAndWrite($sourcePath, $compiledPath);
        return $compiledPath;
    }

    /**
     * Mirror a template's path under the cache's templates/ subdirectory.
     */
    private static function resolveCompiledPath(string $sourcePath): string
    {
        $cacheRoot = $_ENV['MINI_CACHE_ROOT'] ?? (Mini::$mini->root . '/_cache');
        return $cacheRoot . '/templates/' . self::relativePath($sourcePath);
    }

    /**
     * $sourcePath relative to the project root, for both the mirrored
     * cache path and compile-error messages. Falls back to the absolute
     * path (minus its leading slash) for a source outside the project
     * root, such as the framework's own bundled _views/.
     */
    private static function relativePath(string $sourcePath): string
    {
        $root = Mini::$mini->root;
        if ($root !== '' && str_starts_with($sourcePath, $root . '/')) {
            return substr($sourcePath, strlen($root) + 1);
        }
        return ltrim($sourcePath, '/');
    }

    private static function compileAndWrite(string $sourcePath, string $compiledPath): void
    {
        $source = file_get_contents($sourcePath);
        if ($source === false) {
            throw new \RuntimeException("Cannot read template source: $sourcePath");
        }

        $compiled = TemplateCompiler::compile($source, self::relativePath($sourcePath));

        $dir = dirname($compiledPath);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create template cache directory: $dir");
        }

        // Atomic write: compile to a temp file beside the target, then
        // rename() over it. rename() on the same filesystem replaces the
        // target atomically, so a reader never sees a partially written
        // file, regardless of how many workers race to compile the same
        // template at once.
        $tmpPath = $compiledPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($tmpPath, $compiled) === false) {
            throw new \RuntimeException("Cannot write compiled template: $tmpPath");
        }
        if (!rename($tmpPath, $compiledPath)) {
            @unlink($tmpPath);
            throw new \RuntimeException("Cannot install compiled template: $compiledPath");
        }

        // opcache.validate_timestamps=0 makes opcache ignore the file's
        // mtime entirely, so without this it would keep serving whatever
        // bytecode (if any) it had cached for this path under the old
        // content.
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($compiledPath, true);
        }
    }
}
