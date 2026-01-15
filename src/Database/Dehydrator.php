<?php

namespace mini\Database;

use mini\Converter\ConverterRegistryInterface;
use mini\Mini;

/**
 * Converts between entity objects and SQL-compatible arrays
 *
 * Handles hydration/dehydration via:
 * 1. Hydration interface - if entity implements Hydration, uses fromSqlRow()/toSqlRow()
 * 2. Reflection fallback - maps properties to columns with type conversion
 *
 * Used by PartialQuery for reading and by write operations for validation.
 */
final class Dehydrator
{
    /**
     * Hydrate an array to an entity instance
     *
     * @template T of object
     * @param array<string, mixed> $row Associative array of column => value
     * @param class-string<T> $entityClass The entity class to hydrate into
     * @param array|false $constructorArgs Constructor args, or false to skip constructor
     * @return T
     */
    public static function hydrate(array $row, string $entityClass, array|false $constructorArgs = false): object
    {
        // If entity implements Hydration, use its fromSqlRow() method
        if (is_subclass_of($entityClass, Hydration::class)) {
            return $entityClass::fromSqlRow($row);
        }

        // Reflection-based hydration
        return self::hydrateViaReflection($row, $entityClass, $constructorArgs);
    }

    /**
     * Hydrate using reflection (maps columns to properties with type conversion)
     *
     * @template T of object
     * @param array<string, mixed> $row
     * @param class-string<T> $entityClass
     * @param array|false $constructorArgs
     * @return T
     */
    private static function hydrateViaReflection(array $row, string $entityClass, array|false $constructorArgs): object
    {
        $refClass = new \ReflectionClass($entityClass);
        $converterRegistry = null;

        // Create instance with or without constructor
        if ($constructorArgs === false) {
            $entity = $refClass->newInstanceWithoutConstructor();
        } else {
            $entity = $refClass->newInstanceArgs($constructorArgs);
        }

        // Map columns to properties
        foreach ($row as $propertyName => $value) {
            if (!$refClass->hasProperty($propertyName)) {
                continue; // Unknown column, skip
            }

            $prop = $refClass->getProperty($propertyName);
            $prop->setAccessible(true);

            // Get target type for conversion
            $refType = $prop->getType();
            $targetType = null;
            if ($refType instanceof \ReflectionNamedType && !$refType->isBuiltin()) {
                $targetType = $refType->getName();
            }

            // Convert value if target is a class and value needs conversion
            if ($value !== null && $targetType !== null && !($value instanceof $targetType)) {
                if ($converterRegistry === null) {
                    $converterRegistry = Mini::$mini->get(ConverterRegistryInterface::class);
                }

                $found = false;
                $converted = $converterRegistry->tryConvert($value, $targetType, 'sql-value', $found);
                if ($found) {
                    $value = $converted;
                }
            }

            $prop->setValue($entity, $value);
        }

        return $entity;
    }

    /**
     * Dehydrate an entity to a SQL-compatible array
     *
     * @param object $entity The entity to dehydrate
     * @return array<string, mixed> Associative array of column => value
     */
    public static function dehydrate(object $entity): array
    {
        // If entity implements Hydration, use its toSqlRow() method
        if ($entity instanceof Hydration) {
            return $entity->toSqlRow();
        }

        // Reflection-based dehydration
        return self::dehydrateViaReflection($entity);
    }

    /**
     * Dehydrate using reflection (reads public properties, converts values)
     */
    private static function dehydrateViaReflection(object $entity): array
    {
        $data = [];
        $converterRegistry = null;

        $refClass = new \ReflectionClass($entity);

        foreach ($refClass->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            // Skip static properties
            if ($prop->isStatic()) {
                continue;
            }

            $name = $prop->getName();

            // Skip uninitialized properties
            if (!$prop->isInitialized($entity)) {
                continue;
            }

            $value = $prop->getValue($entity);

            // Convert non-scalar values to SQL-compatible format
            if ($value !== null && !is_scalar($value)) {
                // Lazy-load converter registry
                if ($converterRegistry === null) {
                    $converterRegistry = Mini::$mini->get(ConverterRegistryInterface::class);
                }

                // Try to convert to sql-value
                $found = false;
                $converted = $converterRegistry->tryConvert($value, 'sql-value', null, $found);
                if ($found) {
                    $value = $converted;
                } elseif ($value instanceof SqlValue) {
                    // Direct SqlValue support
                    $value = $value->toSqlValue();
                } elseif ($value instanceof \DateTimeInterface) {
                    // Common case: DateTime to string
                    $value = $value->format('Y-m-d H:i:s');
                } elseif ($value instanceof \BackedEnum) {
                    // Backed enums to their value
                    $value = $value->value;
                } elseif ($value instanceof \UnitEnum) {
                    // Unit enums to their name
                    $value = $value->name;
                } elseif ($value instanceof \Stringable) {
                    // Stringable objects
                    $value = (string) $value;
                } elseif (is_object($value) || is_array($value)) {
                    // Last resort: JSON encode complex structures
                    $value = json_encode($value, JSON_THROW_ON_ERROR);
                }
            }

            $data[$name] = $value;
        }

        return $data;
    }
}
