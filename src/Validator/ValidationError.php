<?php

namespace mini\Validator;

use Stringable;
use ArrayAccess;
use IteratorAggregate;
use JsonSerializable;
use Traversable;
use ArrayIterator;

/**
 * Represents validation errors for both scalar values and complex objects
 *
 * ValidationError provides a unified interface for validation results:
 * - Stringable: Cast to string for simple error display
 * - ArrayAccess: Access property errors via $error['fieldName']
 * - IteratorAggregate: Iterate over property errors with foreach
 * - JsonSerializable: Export for API responses
 *
 * ## Usage
 *
 * ```php
 * $error = $validator->isInvalid($value);
 * if ($error) {
 *     // Scalar value - just echo it
 *     echo $error; // "Must be at least 3 characters."
 *
 *     // Object value - access property errors
 *     echo $error['username']; // "Username is required."
 *     echo $error['email'];    // "Invalid email format."
 *
 *     // Iterate all property errors
 *     foreach ($error as $field => $fieldError) {
 *         echo "$field: $fieldError\n";
 *     }
 *
 *     // Nested objects
 *     echo $error['address']['city']; // drills down
 *
 *     // JSON for API responses
 *     echo json_encode($error);
 * }
 * ```
 */
class ValidationError implements Stringable, ArrayAccess, IteratorAggregate, JsonSerializable
{
    /**
     * @param string|Stringable $message Error message for this level
     * @param array<string, ValidationError> $propertyErrors Nested property errors
     */
    public function __construct(
        private string|Stringable $message,
        private array $propertyErrors = []
    ) {}

    /**
     * Get the error message
     */
    public function getMessage(): string|Stringable
    {
        return $this->message;
    }

    /**
     * Check if this error has property-level errors
     */
    public function hasPropertyErrors(): bool
    {
        return !empty($this->propertyErrors);
    }

    /**
     * Get all property errors
     *
     * @return array<string, ValidationError>
     */
    public function getPropertyErrors(): array
    {
        return $this->propertyErrors;
    }

    // ========================================================================
    // Stringable
    // ========================================================================

    public function __toString(): string
    {
        return (string) $this->message;
    }

    // ========================================================================
    // ArrayAccess
    // ========================================================================

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->propertyErrors[$offset]);
    }

    public function offsetGet(mixed $offset): ?ValidationError
    {
        return $this->propertyErrors[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \BadMethodCallException('ValidationError is immutable');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \BadMethodCallException('ValidationError is immutable');
    }

    // ========================================================================
    // IteratorAggregate
    // ========================================================================

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->propertyErrors);
    }

    // ========================================================================
    // JsonSerializable
    // ========================================================================

    public function jsonSerialize(): mixed
    {
        if (empty($this->propertyErrors)) {
            return (string) $this->message;
        }

        // For objects with property errors, serialize as object
        return array_map(
            fn(ValidationError $error) => $error->jsonSerialize(),
            $this->propertyErrors
        );
    }
}
