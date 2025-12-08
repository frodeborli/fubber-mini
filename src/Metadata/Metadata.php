<?php

namespace mini\Metadata;

use JsonSerializable;
use Stringable;

/**
 * JSON Schema annotation builder
 *
 * Build metadata annotations for classes, properties, and data structures that
 * complement validation rules. Inspired by JSON Schema's annotation vocabulary,
 * this class provides documentation, UI hints, and semantic information.
 *
 * ## Basic Usage
 *
 * ```php
 * // Simple property metadata
 * $usernameMeta = (new Metadata())
 *     ->title(t('Username'))
 *     ->description(t('Unique identifier for user login'))
 *     ->examples(['johndoe', 'frode1977'])
 *     ->readOnly(true);
 * ```
 *
 * ## Class/Entity Metadata
 *
 * ```php
 * // Register metadata for a class
 * metadata(User::class)->set((new Metadata())
 *     ->title(t('User'))
 *     ->description(t('Represents a user account in the system'))
 *     ->properties([
 *         'username' => (new Metadata())
 *             ->title(t('Username'))
 *             ->description(t('Unique login identifier'))
 *             ->examples(['johndoe', 'admin123'])
 *             ->readOnly(true),
 *         'email' => (new Metadata())
 *             ->title(t('Email'))
 *             ->description(t('User email address'))
 *             ->format('email'),
 *         'password' => (new Metadata())
 *             ->title(t('Password'))
 *             ->writeOnly(true)
 *     ])
 * );
 *
 * // Access property metadata
 * $title = metadata(User::class)->username->title;
 * ```
 *
 * ## Array/Collection Metadata
 *
 * ```php
 * // Array of items
 * $intArrayMeta = (new Metadata())
 *     ->title(t('Integer Array'))
 *     ->description(t('List of integers'))
 *     ->items((new Metadata())->title(t('Integer')));
 * ```
 *
 * ## Integration with Validator
 *
 * ```php
 * // Combine validation and metadata for full JSON Schema
 * $userSchema = array_merge(
 *     validator(User::class)->jsonSerialize(),
 *     metadata(User::class)->jsonSerialize()
 * );
 * ```
 *
 * ## Design Philosophy
 *
 * - **Non-validating**: Metadata provides documentation, not validation
 * - **Composable**: Can be nested for complex structures
 * - **Translatable**: Accepts Translatable instances for i18n support
 * - **Permissive**: All fields optional, add only what's needed
 * - **JSON Schema compliant**: Maps directly to JSON Schema annotation keywords
 */
class Metadata implements JsonSerializable
{
    /**
     * @var array<string, mixed> Annotation values
     */
    private array $annotations = [];

    /**
     * @var array<string, Metadata> Property metadata
     */
    private array $propertyMetadata = [];

    /**
     * @var array<string, class-string> Property class references (for lazy resolution)
     */
    private array $propertyRefs = [];

    /**
     * @var Metadata|null Metadata for array items
     */
    private ?Metadata $itemsMetadata = null;

    /**
     * Magic property access for property metadata
     *
     * Returns property metadata if explicitly set, otherwise resolves
     * from class reference if available.
     *
     * @param string $property Property name
     * @return Metadata|null Property metadata or null if not set
     */
    public function __get(string $property): ?Metadata
    {
        // Return explicit property metadata if set
        if (isset($this->propertyMetadata[$property])) {
            return $this->propertyMetadata[$property];
        }

        // Resolve from class reference if available
        if (isset($this->propertyRefs[$property])) {
            return \mini\metadata($this->propertyRefs[$property]);
        }

        return null;
    }

    /**
     * Set a class reference for a property (for lazy metadata resolution)
     *
     * @param string $property Property name
     * @param class-string $class Class to resolve metadata from
     * @return static
     */
    public function ref(string $property, string $class): static
    {
        $clone = clone $this;
        $clone->propertyRefs[$property] = $class;
        return $clone;
    }

    /**
     * Set title annotation
     *
     * Short, human-readable label for the data. Typically used in UI forms,
     * documentation, and error messages.
     *
     * Stringable values (like Translatable) are preserved for i18n support
     * and converted to strings during jsonSerialize().
     *
     * @param Stringable|string|null $title Short identifier
     * @return static
     */
    public function title(Stringable|string|null $title): static
    {
        return $this->setAnnotation('title', $title);
    }

    /**
     * Set description annotation
     *
     * Detailed explanation of the data's purpose, constraints, or usage.
     * More verbose than title.
     *
     * Stringable values (like Translatable) are preserved for i18n support
     * and converted to strings during jsonSerialize().
     *
     * @param Stringable|string|null $description Detailed explanation
     * @return static
     */
    public function description(Stringable|string|null $description): static
    {
        return $this->setAnnotation('description', $description);
    }

    /**
     * Set default value annotation
     *
     * Suggests a default value for the data. Note: This is purely documentary
     * and not used during validation. Tools may use it for UI hints.
     *
     * @param mixed $default Default value
     * @return static
     */
    public function default(mixed $default): static
    {
        return $this->setAnnotation('default', $default);
    }

    /**
     * Set examples annotation
     *
     * Provides example values that validate against the schema. Helps readers
     * understand expected data format and values.
     *
     * @param mixed ...$examples One or more example values
     * @return static
     */
    public function examples(mixed ...$examples): static
    {
        return $this->setAnnotation('examples', $examples);
    }

    /**
     * Set readOnly annotation
     *
     * Indicates the value should not be modified. Typically used in API contexts
     * to signal that PUT/POST requests should not include this field.
     *
     * @param bool $readOnly Whether the field is read-only
     * @return static
     */
    public function readOnly(bool $readOnly = true): static
    {
        return $this->setAnnotation('readOnly', $readOnly);
    }

    /**
     * Set writeOnly annotation
     *
     * Indicates the value may be set but will remain hidden from retrieval.
     * Used for sensitive data like passwords that can be written but not read.
     *
     * @param bool $writeOnly Whether the field is write-only
     * @return static
     */
    public function writeOnly(bool $writeOnly = true): static
    {
        return $this->setAnnotation('writeOnly', $writeOnly);
    }

    /**
     * Set deprecated annotation
     *
     * Marks the data as deprecated and potentially removed in future versions.
     * Does not affect validation.
     *
     * @param bool $deprecated Whether the field is deprecated
     * @return static
     */
    public function deprecated(bool $deprecated = true): static
    {
        return $this->setAnnotation('deprecated', $deprecated);
    }

    /**
     * Set format annotation
     *
     * Provides semantic hint about the string format (e.g., 'email', 'uri', 'date-time').
     * This is primarily documentary but may be used by UI generators.
     *
     * @param string|null $format Format identifier
     * @return static
     */
    public function format(?string $format): static
    {
        return $this->setAnnotation('format', $format);
    }

    /**
     * Set metadata for object properties
     *
     * Defines metadata for properties of an object structure.
     *
     * @param array<string, Metadata> $properties Property name => Metadata mapping
     * @return static
     */
    public function properties(array $properties): static
    {
        $clone = clone $this;
        foreach ($properties as $name => $metadata) {
            if (!$metadata instanceof Metadata) {
                throw new \InvalidArgumentException(
                    "Property '$name' must be a Metadata instance, got " . get_debug_type($metadata)
                );
            }
            $clone->propertyMetadata[$name] = $metadata;
        }
        return $clone;
    }

    /**
     * Set metadata for array items
     *
     * Defines metadata for items in an array/list structure.
     *
     * @param Metadata $items Metadata for array items
     * @return static
     */
    public function items(Metadata $items): static
    {
        $clone = clone $this;
        $clone->itemsMetadata = $items;
        return $clone;
    }

    /**
     * Set annotation immutably
     *
     * @param string $key Annotation key
     * @param mixed $value Annotation value
     * @return static
     */
    private function setAnnotation(string $key, mixed $value): static
    {
        $clone = clone $this;
        if ($value === null) {
            unset($clone->annotations[$key]);
        } else {
            $clone->annotations[$key] = $value;
        }
        return $clone;
    }

    /**
     * Export metadata as JSON Schema annotations
     *
     * @return array JSON Schema annotation object
     */
    public function jsonSerialize(): array
    {
        $schema = [];

        // Add all annotations (convert Translatable to strings)
        foreach ($this->annotations as $key => $value) {
            if ($value instanceof Stringable) {
                $schema[$key] = (string)$value;
            } elseif ($key === 'examples' && is_array($value)) {
                // Convert any Translatable in examples
                $schema[$key] = array_map(
                    fn($v) => $v instanceof Stringable ? (string)$v : $v,
                    $value
                );
            } else {
                $schema[$key] = $value;
            }
        }

        // Add property metadata (explicit and refs)
        $allProps = array_unique(array_merge(
            array_keys($this->propertyMetadata),
            array_keys($this->propertyRefs)
        ));

        if (!empty($allProps)) {
            $schema['properties'] = [];
            foreach ($allProps as $prop) {
                // Use __get to resolve refs lazily
                $propMeta = $this->__get($prop);
                if ($propMeta !== null) {
                    $schema['properties'][$prop] = $propMeta;
                }
            }
        }

        // Add items metadata
        if ($this->itemsMetadata !== null) {
            $schema['items'] = $this->itemsMetadata;
        }

        return $schema;
    }

    /**
     * Deep clone property metadata (refs are just class names, no need to clone)
     */
    public function __clone(): void
    {
        foreach ($this->propertyMetadata as $key => $metadata) {
            $this->propertyMetadata[$key] = clone $metadata;
        }
        if ($this->itemsMetadata !== null) {
            $this->itemsMetadata = clone $this->itemsMetadata;
        }
    }
}
