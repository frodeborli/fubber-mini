<?php

namespace mini\Table;

/**
 * Provides arbitrary property storage for tables
 *
 * Enables attaching metadata to tables without modifying the interface.
 * Properties are immutable - withProperty() returns a new instance.
 *
 * ```php
 * $aliased = $users->withProperty('alias', 'u');
 * $aliased->getProperty('alias');  // 'u'
 * $users->getProperty('alias');    // null (original unchanged)
 * ```
 */
trait TablePropertiesTrait
{
    /** @var array<string, mixed> Arbitrary properties */
    private array $props = [];

    /**
     * Get a property value
     *
     * @return mixed Property value, or null if not set
     */
    public function getProperty(string $name): mixed
    {
        if (isset($this->props[$name]) || array_key_exists($name, $this->props)) {
            return $this->props[$name];
        }
        return null;
    }

    /**
     * Check if a property exists (including null values)
     */
    public function hasProperty(string $name): bool
    {
        return array_key_exists($name, $this->props);
    }

    /**
     * Return table with property set
     *
     * Properties can be set to null explicitly. Use hasProperty() to
     * distinguish between "not set" and "set to null".
     */
    public function withProperty(string $name, mixed $value): static
    {
        // Check if already set to same value
        if ($this->hasProperty($name) && $this->props[$name] === $value) {
            return $this;
        }

        $c = clone $this;
        $c->props[$name] = $value;
        return $c;
    }
}
