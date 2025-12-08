<?php

namespace mini\Metadata;

use ReflectionClass;
use ReflectionProperty;
use mini\I18n\Translatable;
use mini\Mini;
use mini\Metadata\Attributes;

/**
 * Builds metadata from PHP class attributes
 *
 * Scans class and property attributes to construct Metadata instances
 * that describe the entity structure. String values (title, description)
 * are automatically wrapped in Translatable for i18n support.
 */
class AttributeMetadataFactory
{
    /**
     * Build metadata from a class using reflection
     *
     * @param class-string $className Class to build metadata for
     * @return Metadata Metadata instance with property metadata
     */
    public function forClass(string $className): Metadata
    {
        $reflection = new ReflectionClass($className);
        $sourceFile = $this->getRelativeSourceFile($reflection->getFileName());
        $metadata = new Metadata();

        // Process class-level attributes
        $metadata = $this->applyClassAttributes($reflection, $metadata, $sourceFile);

        $properties = [];

        // First, process Property attributes on the class itself (property-less metadata)
        foreach ($reflection->getAttributes(Attributes\Property::class) as $attribute) {
            $prop = $attribute->newInstance();
            $propMetadata = $this->buildPropertyMetadata($prop, $sourceFile);

            if ($propMetadata !== null) {
                $properties[$prop->name] = $propMetadata;
            }
        }

        // Then, process actual properties
        $refs = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $propMetadata = $this->buildPropertyMetadataFromProperty($property, $sourceFile);

            if ($propMetadata !== null) {
                $properties[$property->getName()] = $propMetadata;
            }

            // Check for class reference (Ref attribute or type hint)
            $refClass = $this->getPropertyRefClass($property);
            if ($refClass !== null) {
                $refs[$property->getName()] = $refClass;
            }
        }

        // Add property metadata first (this clones)
        if (!empty($properties)) {
            $metadata = $metadata->properties($properties);
        }

        // Then add refs (each call clones, so do this last)
        foreach ($refs as $propName => $refClass) {
            $metadata = $metadata->ref($propName, $refClass);
        }

        return $metadata;
    }

    /**
     * Get the class reference for a property
     *
     * Returns the class from Ref attribute if present, otherwise
     * extracts from the property's type hint if it's a class.
     *
     * @return class-string|null
     */
    private function getPropertyRefClass(ReflectionProperty $property): ?string
    {
        // Check for explicit Ref attribute first
        $refAttrs = $property->getAttributes(Attributes\Ref::class);
        if (!empty($refAttrs)) {
            return $refAttrs[0]->newInstance()->class;
        }

        // Check type hint for class reference
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $typeName = $type->getName();

        // Only return if it's a class (not interface for now, could be expanded)
        if (class_exists($typeName) || interface_exists($typeName)) {
            return $typeName;
        }

        return null;
    }

    /**
     * Get source file path relative to project root
     */
    private function getRelativeSourceFile(string $absolutePath): string
    {
        $projectRoot = Mini::$mini->root;
        return str_replace($projectRoot . '/', '', $absolutePath);
    }

    /**
     * Wrap a string value in Translatable with the source file context
     *
     * If the value is already a Stringable (e.g., Translatable), returns it as-is.
     */
    private function translatable(\Stringable|string $text, string $sourceFile): \Stringable
    {
        if ($text instanceof \Stringable) {
            return $text;
        }
        return new Translatable($text, [], $sourceFile);
    }

    /**
     * Wrap a value in Translatable only if it's a string
     *
     * Non-string values (int, float, bool, null, array) pass through unchanged.
     */
    private function translatableIfString(mixed $value, string $sourceFile): mixed
    {
        if (is_string($value)) {
            return new Translatable($value, [], $sourceFile);
        }
        return $value;
    }

    /**
     * Wrap string values in an array with Translatable
     *
     * Non-string values pass through unchanged.
     */
    private function translatableArray(array $values, string $sourceFile): array
    {
        return array_map(
            fn($v) => $this->translatableIfString($v, $sourceFile),
            $values
        );
    }

    /**
     * Apply class-level attributes to metadata
     *
     * @param ReflectionClass $reflection Class reflection
     * @param Metadata $metadata Base metadata
     * @param string $sourceFile Source file for translations
     * @return Metadata Metadata with class attributes applied
     */
    private function applyClassAttributes(ReflectionClass $reflection, Metadata $metadata, string $sourceFile): Metadata
    {
        foreach ($reflection->getAttributes() as $attribute) {
            // Only process metadata attributes
            if (!str_starts_with($attribute->getName(), 'mini\\Metadata\\Attributes\\')) {
                continue;
            }

            $instance = $attribute->newInstance();
            $metadata = $this->applyAttribute($metadata, $instance, $sourceFile);
        }

        return $metadata;
    }

    /**
     * Build metadata from a Property attribute
     *
     * @param Attributes\Property $prop Property attribute instance
     * @param string $sourceFile Source file for translations
     * @return Metadata|null Property metadata
     */
    private function buildPropertyMetadata(Attributes\Property $prop, string $sourceFile): ?Metadata
    {
        $metadata = new Metadata();

        if ($prop->title !== null) {
            $metadata = $metadata->title($this->translatable($prop->title, $sourceFile));
        }

        if ($prop->description !== null) {
            $metadata = $metadata->description($this->translatable($prop->description, $sourceFile));
        }

        if ($prop->default !== null) {
            $metadata = $metadata->default($this->translatableIfString($prop->default, $sourceFile));
        }

        if (!empty($prop->examples)) {
            $metadata = $metadata->examples(...$this->translatableArray($prop->examples, $sourceFile));
        }

        if ($prop->readOnly !== null) {
            $metadata = $metadata->readOnly($prop->readOnly);
        }

        if ($prop->writeOnly !== null) {
            $metadata = $metadata->writeOnly($prop->writeOnly);
        }

        if ($prop->deprecated !== null) {
            $metadata = $metadata->deprecated($prop->deprecated);
        }

        if ($prop->format !== null) {
            $metadata = $metadata->format($prop->format);
        }

        return $metadata;
    }

    /**
     * Build metadata for a single property from its attributes
     *
     * @param ReflectionProperty $property Property to build metadata for
     * @param string $sourceFile Source file for translations
     * @return Metadata|null Property metadata, or null if no metadata attributes
     */
    private function buildPropertyMetadataFromProperty(ReflectionProperty $property, string $sourceFile): ?Metadata
    {
        $attributes = $property->getAttributes();

        if (empty($attributes)) {
            return null;
        }

        $metadata = new Metadata();
        $hasMetadata = false;

        foreach ($attributes as $attribute) {
            // Skip non-metadata attributes (e.g., Validator, Tables attributes)
            if (!str_starts_with($attribute->getName(), 'mini\\Metadata\\Attributes\\')) {
                continue;
            }

            // Skip Ref attribute - it's handled separately for class references
            if ($attribute->getName() === Attributes\Ref::class) {
                continue;
            }

            $hasMetadata = true;
            $instance = $attribute->newInstance();
            $metadata = $this->applyAttribute($metadata, $instance, $sourceFile);
        }

        return $hasMetadata ? $metadata : null;
    }

    /**
     * Apply a metadata attribute to a metadata instance
     *
     * @param Metadata $metadata Base metadata
     * @param object $attribute Attribute instance
     * @param string $sourceFile Source file for translations
     * @return Metadata Metadata with attribute applied
     */
    private function applyAttribute(Metadata $metadata, object $attribute, string $sourceFile): Metadata
    {
        return match(get_class($attribute)) {
            Attributes\Title::class => $metadata->title($this->translatable($attribute->title, $sourceFile)),
            Attributes\Description::class => $metadata->description($this->translatable($attribute->description, $sourceFile)),
            Attributes\Examples::class => $metadata->examples(...$this->translatableArray($attribute->examples, $sourceFile)),
            Attributes\DefaultValue::class => $metadata->default($this->translatableIfString($attribute->default, $sourceFile)),
            Attributes\IsReadOnly::class => $metadata->readOnly($attribute->value),
            Attributes\IsWriteOnly::class => $metadata->writeOnly($attribute->value),
            Attributes\IsDeprecated::class => $metadata->deprecated($attribute->value),
            Attributes\MetaFormat::class => $metadata->format($attribute->format),
            default => $metadata
        };
    }
}
