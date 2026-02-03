<?php

namespace mini\Util;

/**
 * Immutable Cache-Control header manipulation
 *
 * Inspired by PSR-7 UriInterface - all with* methods return new instances.
 *
 * Usage:
 * ```php
 * $cache = new CacheControlHeader('public, max-age=3600');
 *
 * // Query directives
 * $cache->has('public');           // true
 * $cache->get('max-age');          // '3600'
 * $cache->get('private');          // null (not set)
 * $cache->get('no-cache');         // true (set but no value)
 *
 * // Modify (returns new instance)
 * $cache = $cache->with('private')->without('public');
 * $cache = $cache->with('max-age', 60);
 *
 * // Utility methods
 * $cache = $cache->withRestrictedVisibility('private');  // public -> private
 * $cache = $cache->withMaxTtl(60);  // cap max-age at 60
 *
 * // Render
 * echo $cache;  // "private, max-age=60"
 * ```
 */
class CacheControlHeader implements \Stringable
{
    /** @var array<string, true|string> Directive name => true (flag) or string (value) */
    private array $directives = [];

    /**
     * Visibility levels from most permissive to most restrictive
     */
    private const VISIBILITY_LEVELS = [
        'public' => 1,
        'private' => 2,
        'no-cache' => 3,
        'no-store' => 4,
    ];

    /**
     * Create from existing header value
     */
    public function __construct(?string $header = null)
    {
        if ($header !== null && $header !== '') {
            $this->directives = $this->parse($header);
        }
    }

    /**
     * Parse Cache-Control header string into directives
     */
    private function parse(string $header): array
    {
        $directives = [];

        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (str_contains($part, '=')) {
                [$name, $value] = explode('=', $part, 2);
                $directives[strtolower(trim($name))] = trim($value, ' "\'');
            } else {
                $directives[strtolower($part)] = true;
            }
        }

        return $directives;
    }

    /**
     * Check if a directive is set
     */
    public function has(string $directive): bool
    {
        return isset($this->directives[strtolower($directive)]);
    }

    /**
     * Get a directive's value
     *
     * @return string|true|null String value, true if flag (no value), null if not set
     */
    public function get(string $directive): string|true|null
    {
        return $this->directives[strtolower($directive)] ?? null;
    }

    /**
     * Get all directives
     *
     * @return array<string, true|string>
     */
    public function all(): array
    {
        return $this->directives;
    }

    /**
     * Check if header is empty (no directives)
     */
    public function isEmpty(): bool
    {
        return empty($this->directives);
    }

    /**
     * Add or update a directive
     *
     * @param string $directive Directive name
     * @param string|true|null $value Value, true for flag, null to remove
     */
    public function with(string $directive, string|true|null $value = true): static
    {
        if ($value === null) {
            return $this->without($directive);
        }

        $new = clone $this;
        $new->directives[strtolower($directive)] = $value;
        return $new;
    }

    /**
     * Remove a directive
     */
    public function without(string $directive): static
    {
        $key = strtolower($directive);
        if (!isset($this->directives[$key])) {
            return $this;
        }

        $new = clone $this;
        unset($new->directives[$key]);
        return $new;
    }

    /**
     * Restrict visibility to at most the given level
     *
     * Visibility hierarchy (most to least permissive):
     * public > private > no-cache > no-store
     *
     * Examples:
     * - restrictVisibility('private') on 'public' → 'private'
     * - restrictVisibility('private') on 'no-cache' → 'no-cache' (already more restrictive)
     * - restrictVisibility('no-store') on anything → 'no-store'
     *
     * @param string $maxVisibility One of: public, private, no-cache, no-store
     */
    public function withRestrictedVisibility(string $maxVisibility): static
    {
        $maxLevel = self::VISIBILITY_LEVELS[strtolower($maxVisibility)] ?? null;
        if ($maxLevel === null) {
            throw new \InvalidArgumentException(
                "Invalid visibility: $maxVisibility. Must be one of: " . implode(', ', array_keys(self::VISIBILITY_LEVELS))
            );
        }

        // Find current visibility level
        $currentLevel = 0;
        foreach (self::VISIBILITY_LEVELS as $vis => $level) {
            if ($this->has($vis) && $level > $currentLevel) {
                $currentLevel = $level;
            }
        }

        // If no visibility set or already more restrictive, apply the requested one
        if ($currentLevel === 0 || $currentLevel < $maxLevel) {
            // Remove any existing visibility directives
            $new = $this;
            foreach (array_keys(self::VISIBILITY_LEVELS) as $vis) {
                $new = $new->without($vis);
            }
            return $new->with($maxVisibility);
        }

        // Current is already more restrictive
        return $this;
    }

    /**
     * Ensure visibility is at least as restrictive as 'private'
     *
     * Shorthand for withRestrictedVisibility('private')
     */
    public function withPrivate(): static
    {
        return $this->withRestrictedVisibility('private');
    }

    /**
     * Ensure visibility is 'no-store' (most restrictive)
     *
     * Also adds no-cache for compatibility.
     */
    public function withNoStore(): static
    {
        return $this
            ->withRestrictedVisibility('no-store')
            ->with('no-cache')
            ->with('must-revalidate');
    }

    /**
     * Cap max-age to at most the given value
     *
     * If current max-age is lower, keeps the lower value.
     * If no max-age set, sets it to the given value.
     */
    public function withMaxTtl(int $seconds): static
    {
        $current = $this->get('max-age');

        if ($current === null || $current === true) {
            return $this->with('max-age', (string) $seconds);
        }

        $currentSeconds = (int) $current;
        if ($currentSeconds > $seconds) {
            return $this->with('max-age', (string) $seconds);
        }

        return $this;
    }

    /**
     * Cap s-maxage (shared cache TTL) to at most the given value
     */
    public function withMaxSharedTtl(int $seconds): static
    {
        $current = $this->get('s-maxage');

        if ($current === null || $current === true) {
            return $this->with('s-maxage', (string) $seconds);
        }

        $currentSeconds = (int) $current;
        if ($currentSeconds > $seconds) {
            return $this->with('s-maxage', (string) $seconds);
        }

        return $this;
    }

    /**
     * Set must-revalidate directive
     */
    public function withMustRevalidate(): static
    {
        return $this->with('must-revalidate');
    }

    /**
     * Render to Cache-Control header string
     */
    public function __toString(): string
    {
        $parts = [];

        foreach ($this->directives as $name => $value) {
            if ($value === true) {
                $parts[] = $name;
            } else {
                $parts[] = "$name=$value";
            }
        }

        return implode(', ', $parts);
    }
}
