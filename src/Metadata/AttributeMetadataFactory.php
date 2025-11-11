<?php

namespace mini\Metadata;

use ReflectionClass;
use ReflectionProperty;
use mini\Metadata\Attributes;

/**
 * Builds metadata from PHP class attributes
 *
 * Scans class and property attributes to construct Metadata instances
 * that describe the entity structure.
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
        $metadata = new Metadata();

        // Process class-level attributes
        $metadata = $this->applyClassAttributes($reflection, $metadata);

        $properties = [];

        // First, process Property attributes on the class itself (property-less metadata)
        foreach ($reflection->getAttributes(Attributes\Property::class) as $attribute) {
            $prop = $attribute->newInstance();
            $propMetadata = $this->buildPropertyMetadata($prop);

            if ($propMetadata !== null) {
                $properties[$prop->name] = $propMetadata;
            }
        }

        // Then, process actual properties
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $propMetadata = $this->buildPropertyMetadataFromProperty($property);

            if ($propMetadata !== null) {
                $properties[$property->getName()] = $propMetadata;
            }
        }

        // Add property metadata
        if (!empty($properties)) {
            $metadata = $metadata->properties($properties);
        }

        return $metadata;
    }

    /**
     * Apply class-level attributes to metadata
     *
     * @param ReflectionClass $reflection Class reflection
     * @param Metadata $metadata Base metadata
     * @return Metadata Metadata with class attributes applied
     */
    private function applyClassAttributes(ReflectionClass $reflection, Metadata $metadata): Metadata
    {
        foreach ($reflection->getAttributes() as $attribute) {
            // Only process metadata attributes
            if (!str_starts_with($attribute->getName(), 'mini\\Metadata\\Attributes\\')) {
                continue;
            }

            $instance = $attribute->newInstance();
            $metadata = $this->applyAttribute($metadata, $instance);
        }

        return $metadata;
    }

    /**
     * Build metadata from a Property attribute
     *
     * @param Attributes\Property $prop Property attribute instance
     * @return Metadata|null Property metadata
     */
    private function buildPropertyMetadata(Attributes\Property $prop): ?Metadata
    {
        $metadata = new Metadata();

        if ($prop->title !== null) {
            $metadata = $metadata->title($prop->title);
        }

        if ($prop->description !== null) {
            $metadata = $metadata->description($prop->description);
        }

        if ($prop->default !== null) {
            $metadata = $metadata->default($prop->default);
        }

        if (!empty($prop->examples)) {
            $metadata = $metadata->examples(...$prop->examples);
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
     * @return Metadata|null Property metadata, or null if no metadata attributes
     */
    private function buildPropertyMetadataFromProperty(ReflectionProperty $property): ?Metadata
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

            $hasMetadata = true;
            $instance = $attribute->newInstance();
            $metadata = $this->applyAttribute($metadata, $instance);
        }

        return $hasMetadata ? $metadata : null;
    }

    /**
     * Apply a metadata attribute to a metadata instance
     *
     * @param Metadata $metadata Base metadata
     * @param object $attribute Attribute instance
     * @return Metadata Metadata with attribute applied
     */
    private function applyAttribute(Metadata $metadata, object $attribute): Metadata
    {
        return match(get_class($attribute)) {
            Attributes\Title::class => $metadata->title($attribute->title),
            Attributes\Description::class => $metadata->description($attribute->description),
            Attributes\Examples::class => $metadata->examples(...$attribute->examples),
            Attributes\DefaultValue::class => $metadata->default($attribute->default),
            Attributes\IsReadOnly::class => $metadata->readOnly($attribute->value),
            Attributes\IsWriteOnly::class => $metadata->writeOnly($attribute->value),
            Attributes\IsDeprecated::class => $metadata->deprecated($attribute->value),
            Attributes\MetaFormat::class => $metadata->format($attribute->format),
            default => $metadata
        };
    }
}
