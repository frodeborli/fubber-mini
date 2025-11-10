<?php

namespace mini\Validator;

use ReflectionClass;
use ReflectionProperty;
use mini\Validator\Attributes;

/**
 * Builds validators from PHP class attributes
 *
 * Scans class properties for validation attributes and constructs
 * a Validator instance that validates the entire object structure.
 */
class AttributeValidatorFactory
{
    /**
     * Build a validator from a class using reflection
     *
     * @param class-string $className Class to build validator for
     * @return Validator Object validator with property validators
     */
    public function forClass(string $className): Validator
    {
        $reflection = new ReflectionClass($className);
        $validator = (new Validator())->type('object');

        $properties = [];
        $required = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $propertyValidator = $this->buildPropertyValidator($property);

            if ($propertyValidator !== null) {
                $properties[$property->getName()] = $propertyValidator;

                // Check if property is required
                if ($this->isRequired($property)) {
                    $required[] = $property->getName();
                }
            }
        }

        // Add property validators
        if (!empty($properties)) {
            $validator = $validator->properties($properties);
        }

        // Mark required properties
        foreach ($required as $propName) {
            // Required is handled by the property validator itself via the Required attribute
        }

        return $validator;
    }

    /**
     * Build a validator for a single property from its attributes
     *
     * @param ReflectionProperty $property Property to build validator for
     * @return Validator|null Property validator, or null if no validation attributes
     */
    private function buildPropertyValidator(ReflectionProperty $property): ?Validator
    {
        $attributes = $property->getAttributes();

        if (empty($attributes)) {
            return null;
        }

        $validator = new Validator();
        $hasValidation = false;

        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();

            // Skip non-validator attributes (e.g., Tables attributes)
            if (!str_starts_with($attribute->getName(), 'mini\\Validator\\Attributes\\')) {
                continue;
            }

            $hasValidation = true;
            $validator = $this->applyAttribute($validator, $instance);
        }

        return $hasValidation ? $validator : null;
    }

    /**
     * Apply a validation attribute to a validator
     *
     * @param Validator $validator Base validator
     * @param object $attribute Attribute instance
     * @return Validator Validator with attribute applied
     */
    private function applyAttribute(Validator $validator, object $attribute): Validator
    {
        return match(get_class($attribute)) {
            Attributes\Type::class => $validator->type($attribute->type, $attribute->message),
            Attributes\MinLength::class => $validator->minLength($attribute->min, $attribute->message),
            Attributes\MaxLength::class => $validator->maxLength($attribute->max, $attribute->message),
            Attributes\Minimum::class => $validator->minimum($attribute->min, $attribute->message),
            Attributes\Maximum::class => $validator->maximum($attribute->max, $attribute->message),
            Attributes\ExclusiveMinimum::class => $validator->exclusiveMinimum($attribute->min, $attribute->message),
            Attributes\ExclusiveMaximum::class => $validator->exclusiveMaximum($attribute->max, $attribute->message),
            Attributes\MultipleOf::class => $validator->multipleOf($attribute->divisor, $attribute->message),
            Attributes\Pattern::class => $validator->pattern($attribute->pattern, $attribute->message),
            Attributes\Format::class => $validator->format($attribute->format, $attribute->message),
            Attributes\MinItems::class => $validator->minItems($attribute->min, $attribute->message),
            Attributes\MaxItems::class => $validator->maxItems($attribute->max, $attribute->message),
            Attributes\UniqueItems::class => $validator->uniqueItems(),
            Attributes\MinProperties::class => $validator->minProperties($attribute->min, $attribute->message),
            Attributes\MaxProperties::class => $validator->maxProperties($attribute->max, $attribute->message),
            Attributes\Required::class => $validator->required($attribute->message),
            Attributes\Const::class => $validator->const($attribute->value, $attribute->message),
            Attributes\Enum::class => $validator->enum($attribute->values, $attribute->message),
            default => $validator
        };
    }

    /**
     * Check if a property has the Required attribute
     */
    private function isRequired(ReflectionProperty $property): bool
    {
        foreach ($property->getAttributes() as $attribute) {
            if ($attribute->getName() === Attributes\Required::class) {
                return true;
            }
        }
        return false;
    }
}
