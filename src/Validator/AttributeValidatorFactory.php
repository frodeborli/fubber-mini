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

        // First, process Field attributes on the class itself
        foreach ($reflection->getAttributes(Attributes\Field::class) as $attribute) {
            $field = $attribute->newInstance();
            $fieldValidator = $this->buildFieldValidator($field);

            if ($fieldValidator !== null) {
                $properties[$field->name] = $fieldValidator;

                if ($field->required) {
                    $required[] = $field->name;
                }
            }
        }

        // Then, process actual properties
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
        if (!empty($required)) {
            $validator = $validator->required(...$required);
        }

        return $validator;
    }

    /**
     * Build a validator from a Field attribute
     *
     * @param Attributes\Field $field Field attribute instance
     * @return Validator|null Field validator
     */
    private function buildFieldValidator(Attributes\Field $field): ?Validator
    {
        $validator = new Validator();

        // Apply all validation rules from the Field attribute
        if ($field->type !== null) {
            $validator = $validator->type($field->type, $field->message);
        }

        if ($field->minLength !== null) {
            $validator = $validator->minLength($field->minLength, $field->message);
        }

        if ($field->maxLength !== null) {
            $validator = $validator->maxLength($field->maxLength, $field->message);
        }

        if ($field->minimum !== null) {
            $validator = $validator->minimum($field->minimum, $field->message);
        }

        if ($field->maximum !== null) {
            $validator = $validator->maximum($field->maximum, $field->message);
        }

        if ($field->exclusiveMinimum !== null) {
            $validator = $validator->exclusiveMinimum($field->exclusiveMinimum, $field->message);
        }

        if ($field->exclusiveMaximum !== null) {
            $validator = $validator->exclusiveMaximum($field->exclusiveMaximum, $field->message);
        }

        if ($field->multipleOf !== null) {
            $validator = $validator->multipleOf($field->multipleOf, $field->message);
        }

        if ($field->pattern !== null) {
            $validator = $validator->pattern($field->pattern, $field->message);
        }

        if ($field->format !== null) {
            $validator = $validator->format($field->format, $field->message);
        }

        if ($field->minItems !== null) {
            $validator = $validator->minItems($field->minItems, $field->message);
        }

        if ($field->maxItems !== null) {
            $validator = $validator->maxItems($field->maxItems, $field->message);
        }

        if ($field->uniqueItems === true) {
            $validator = $validator->uniqueItems();
        }

        if ($field->minProperties !== null) {
            $validator = $validator->minProperties($field->minProperties, $field->message);
        }

        if ($field->maxProperties !== null) {
            $validator = $validator->maxProperties($field->maxProperties, $field->message);
        }

        if ($field->required === true) {
            $validator = $validator->required($field->message);
        }

        if ($field->const !== null) {
            $validator = $validator->const($field->const, $field->message);
        }

        if ($field->enum !== null) {
            $validator = $validator->enum($field->enum, $field->message);
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
