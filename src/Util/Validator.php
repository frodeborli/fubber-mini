<?php

namespace mini\Util;

use Closure;
use Stringable;

/**
 * Composable validation builder
 *
 * Build reusable validators that can be composed for complex validation scenarios.
 * Inspired by JSON Schema, validators are permissive by default and rules are added
 * incrementally.
 *
 * ## Basic Usage
 *
 * ```php
 * // Simple field validator with fluent API
 * $emailValidator = (new Validator())->required()->email();
 *
 * if ($error = $emailValidator->isInvalid($_POST['email'])) {
 *     echo "Error: $error";
 * }
 * ```
 *
 * ## Entity Validation
 *
 * ```php
 * class User {
 *     public static function validator(): Validator {
 *         return (new Validator())
 *             ->forProperty('username',
 *                 (new Validator())->isString()->required()->minLength(3))
 *             ->forProperty('email',
 *                 (new Validator())->required()->email())
 *             ->forProperty('age',
 *                 (new Validator())->isInt()->minVal(18));
 *     }
 * }
 *
 * // Direct field validation
 * if ($error = User::validator()->username->isInvalid($_POST['username'])) {
 *     echo "Username error: $error";
 * }
 *
 * // Full entity validation
 * $errors = User::validator()->validate($_POST);
 * if ($errors) {
 *     // ['username' => Translatable, 'email' => Translatable, ...]
 * }
 * ```
 *
 * ## Composition & Reusability
 *
 * ```php
 * // Define reusable validators
 * $emailValidator = (new Validator())->required()->email();
 * $passwordValidator = (new Validator())->required()->minLength(8);
 *
 * // Compose into entity validator
 * $userValidator = (new Validator())
 *     ->forProperty('email', $emailValidator)
 *     ->forProperty('password', $passwordValidator);
 *
 * // Partial validation (returns clone)
 * $profileValidator = User::validator()->withoutFields(['password']);
 * ```
 *
 * ## Design Philosophy
 *
 * - **Permissive by default**: Empty validator accepts any value
 * - **Non-smart**: Doesn't prevent contradictory rules (e.g., `isInt()->isString()`)
 * - **Fail fast**: Returns first error for field validation
 * - **Nullable aware**: Optional fields skip validation when empty
 * - **Lazy translation**: Error messages (Translatable) aren't converted until displayed
 *
 * @package mini\Util
 */
class Validator implements \JsonSerializable
{
    private array $rules = []; // JSON Schema rules: ['type' => 'string', 'minLength' => 3, 'items' => $validator, ...]
    private array $propertyValidators = [];
    private array $patternPropertyValidators = [];
    private ?Validator $additionalPropertiesValidator = null;
    private bool $allowAdditionalProperties = true;
    private bool $isRequired = false;
    private string|Stringable|null $requiredMessage = null;

    /**
     * Magic property access for field validators
     *
     * @param string $property
     * @return Validator|null
     */
    public function __get(string $property): ?Validator
    {
        return $this->propertyValidators[$property] ?? null;
    }

    /**
     * Invokable: $validator($value)
     *
     * @param mixed $value
     * @return null|array|string|Stringable
     */
    public function __invoke(mixed $value): null|array|string|Stringable
    {
        return $this->isInvalid($value);
    }

    /**
     * Check if value is invalid
     *
     * Returns null if valid, or error message(s) if invalid.
     *
     * For single field validation: Returns null or first error (string|Stringable)
     * For object/array validation: Returns null or array of field errors
     *
     * @param mixed $value Value to validate
     * @return null|array|string|Stringable Null if valid, error(s) if invalid
     */
    public function isInvalid(mixed $value): null|array|string|Stringable
    {
        // Check required first
        $isEmpty = $value === null || $value === '' || $value === [];

        if ($this->isRequired && $isEmpty) {
            return $this->requiredMessage ?? \mini\t("This field is required.");
        }

        // If not required and empty, skip all other rules
        if (!$this->isRequired && $isEmpty) {
            return null;
        }

        // For object/array validation, check properties
        if ((!empty($this->propertyValidators) || !empty($this->patternPropertyValidators) || $this->additionalPropertiesValidator !== null || !$this->allowAdditionalProperties) && (is_array($value) || is_object($value))) {
            $errors = [];
            $validatedProps = [];

            // Validate defined properties
            foreach ($this->propertyValidators as $property => $validator) {
                $validatedProps[$property] = true;
                $propValue = is_array($value) ? ($value[$property] ?? null)
                                              : ($value->$property ?? null);
                if ($error = $validator->isInvalid($propValue)) {
                    $errors[$property] = $error;
                }
            }

            // Get all actual properties in the value
            $actualProps = is_array($value) ? array_keys($value) : array_keys(get_object_vars($value));

            // Validate pattern properties and track which properties they match
            foreach ($actualProps as $property) {
                if (isset($validatedProps[$property])) {
                    continue; // Already validated by properties()
                }

                foreach ($this->patternPropertyValidators as $pattern => $validator) {
                    if (preg_match($pattern, $property)) {
                        $validatedProps[$property] = true;
                        $propValue = is_array($value) ? $value[$property] : $value->$property;
                        if ($error = $validator->isInvalid($propValue)) {
                            $errors[$property] = $error;
                        }
                        break; // Only validate against first matching pattern
                    }
                }
            }

            // Validate additional properties
            foreach ($actualProps as $property) {
                if (isset($validatedProps[$property])) {
                    continue; // Already validated
                }

                if (!$this->allowAdditionalProperties) {
                    $errors[$property] = \mini\t("Additional property '{property}' is not allowed.", ['property' => $property]);
                } elseif ($this->additionalPropertiesValidator !== null) {
                    $propValue = is_array($value) ? $value[$property] : $value->$property;
                    if ($error = $this->additionalPropertiesValidator->isInvalid($propValue)) {
                        $errors[$property] = $error;
                    }
                }
            }

            return $errors ?: null;
        }

        // Run rules until first error (fail fast)
        foreach ($this->rules as $keyword => $ruleValue) {
            $error = $this->validateRule($keyword, $ruleValue, $value);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * Validate a single rule
     *
     * @param string $keyword Rule keyword
     * @param mixed $ruleValue Rule constraint value
     * @param mixed $value Value to validate
     * @return null|string|Stringable Null if valid, error message if invalid
     */
    private function validateRule(string $keyword, mixed $ruleValue, mixed $value): null|string|Stringable
    {
        // Skip null values for most rules (required is handled separately)
        if ($value === null && $keyword !== 'type') {
            return null;
        }

        return match($keyword) {
            // Type validation
            'type' => $this->validateType($ruleValue, $value),

            // String constraints (only apply to strings)
            'minLength' => !is_string($value) ? null : (strlen($value) < $ruleValue ? \mini\t("Must be at least {min} characters long.", ['min' => $ruleValue]) : null),
            'maxLength' => !is_string($value) ? null : (strlen($value) > $ruleValue ? \mini\t("Must be {max} characters or less.", ['max' => $ruleValue]) : null),
            'pattern' => !is_string($value) ? null : (!preg_match($ruleValue, $value) ? \mini\t("Invalid format.") : null),

            // Numeric constraints (only apply to int/float, not numeric strings)
            'minimum' => !(is_int($value) || is_float($value)) ? null : ($value < $ruleValue ? \mini\t("Must be at least {min}.", ['min' => $ruleValue]) : null),
            'maximum' => !(is_int($value) || is_float($value)) ? null : ($value > $ruleValue ? \mini\t("Must be {max} or less.", ['max' => $ruleValue]) : null),
            'exclusiveMinimum' => !(is_int($value) || is_float($value)) ? null : ($value <= $ruleValue ? \mini\t("Must be greater than {min}.", ['min' => $ruleValue]) : null),
            'exclusiveMaximum' => !(is_int($value) || is_float($value)) ? null : ($value >= $ruleValue ? \mini\t("Must be less than {max}.", ['max' => $ruleValue]) : null),
            'multipleOf' => !(is_int($value) || is_float($value)) ? null : (fmod($value, $ruleValue) != 0 ? \mini\t("Must be a multiple of {divisor}.", ['divisor' => $ruleValue]) : null),

            // Array constraints (only apply to arrays)
            'minItems' => !is_array($value) ? null : (count($value) < $ruleValue ? \mini\t("Must have at least {min} items.", ['min' => $ruleValue]) : null),
            'maxItems' => !is_array($value) ? null : (count($value) > $ruleValue ? \mini\t("Must have at most {max} items.", ['max' => $ruleValue]) : null),
            'uniqueItems' => !is_array($value) ? null : (count($value) !== count(array_unique($value, SORT_REGULAR)) ? \mini\t("Items must be unique.") : null),
            'items' => !is_array($value) ? null : $this->validateItems($ruleValue, $value),

            // Object constraints (only apply to objects/associative arrays)
            'minProperties' => (!is_array($value) || array_is_list($value)) ? null : (count($value) < $ruleValue ? \mini\t("Must have at least {min} properties.", ['min' => $ruleValue]) : null),
            'maxProperties' => (!is_array($value) || array_is_list($value)) ? null : (count($value) > $ruleValue ? \mini\t("Must have at most {max} properties.", ['max' => $ruleValue]) : null),

            // Enum/const (apply to any type)
            'const' => $value !== $ruleValue ? \mini\t("Must be exactly {value}.", ['value' => $ruleValue]) : null,
            'enum' => !in_array($value, $ruleValue, true) ? \mini\t("Please select a valid option.") : null,

            // Format validators (only apply to strings)
            'format' => !is_string($value) ? null : $this->validateFormat($ruleValue, $value),

            // Combinators (apply to any type)
            'anyOf' => $this->validateAnyOf($ruleValue, $value),
            'allOf' => $this->validateAllOf($ruleValue, $value),
            'oneOf' => $this->validateOneOf($ruleValue, $value),
            'not' => $this->validateNot($ruleValue, $value),

            // Complex validators
            'additionalItems' => !is_array($value) ? null : $this->validateAdditionalItems($ruleValue, $value),
            'minContains' => !is_array($value) ? null : $this->validateMinContains($ruleValue, $value),
            'maxContains' => !is_array($value) ? null : $this->validateMaxContains($ruleValue, $value),
            'dependentRequired' => !is_array($value) ? null : $this->validateDependentRequired($ruleValue, $value),

            // Custom validators (closures - apply to any type)
            default => str_starts_with($keyword, 'custom:') ? ($ruleValue($value) ? null : \mini\t("Validation failed.")) : null
        };
    }

    /**
     * Validate entire structure (alias for isInvalid)
     *
     * @param mixed $value
     * @return null|array|string|Stringable
     */
    public function validate(mixed $value): null|array|string|Stringable
    {
        return $this->isInvalid($value);
    }

    // ========================================================================
    // Required (special handling)
    // ========================================================================

    /**
     * Mark field as required
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function required(string|Stringable|null $message = null): static
    {
        $this->isRequired = true;
        if ($message !== null) {
            $this->requiredMessage = $message;
        }
        return $this;
    }

    // ========================================================================
    // Immutability Helper
    // ========================================================================

    /**
     * Set a rule immutably (clones before mutation)
     *
     * @param string $key Rule keyword
     * @param mixed $value Rule value
     * @return static New instance with rule set
     */
    private function setRule(string $key, mixed $value): static
    {
        $clone = clone $this;
        $clone->rules[$key] = $value;
        return $clone;
    }

    // ========================================================================
    // Type Validators
    // ========================================================================

    /**
     * Set JSON Schema type(s)
     *
     * @param string|array $types Single type or array of types: 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null'
     * @return static
     */
    public function type(string|array $types): static
    {
        // Normalize to single string or array
        $typeArray = is_array($types) ? $types : [$types];
        $normalized = count($typeArray) === 1 ? $typeArray[0] : $typeArray;
        return $this->setRule('type', $normalized);
    }


    // ========================================================================
    // Format Validators (JSON Schema: format keyword)
    // ========================================================================

    /**
     * Validate email format (JSON Schema: format "email")
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function email(): static
    {
        $this->rules['format'] = 'email';
        return $this;
    }

    /**
     * Validate URL format (JSON Schema: format "uri")
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function url(): static
    {
        $this->rules['format'] = 'uri';
        return $this;
    }

    /**
     * Validate date-time format (JSON Schema: format "date-time")
     *
     * Validates ISO 8601 date-time format.
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function dateTime(): static
    {
        $this->rules['format'] = 'date-time';
        return $this;
    }

    /**
     * Validate date format (JSON Schema: format "date")
     *
     * Validates ISO 8601 date format (YYYY-MM-DD).
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function date(): static
    {
        $this->rules['format'] = 'date';
        return $this;
    }

    /**
     * Validate time format (JSON Schema: format "time")
     *
     * Validates ISO 8601 time format (HH:MM:SS or HH:MM:SS.sss).
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function time(): static
    {
        $this->rules['format'] = 'time';
        return $this;
    }

    /**
     * Validate IPv4 address (JSON Schema: format "ipv4")
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function ipv4(): static
    {
        $this->rules['format'] = 'ipv4';
        return $this;
    }

    /**
     * Validate IPv6 address (JSON Schema: format "ipv6")
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function ipv6(): static
    {
        $this->rules['format'] = 'ipv6';
        return $this;
    }

    /**
     * Validate UUID format (JSON Schema: format "uuid")
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function uuid(): static
    {
        $this->rules['format'] = 'uuid';
        return $this;
    }

    /**
     * Validate slug format (URL-safe string)
     *
     * Not a JSON Schema standard, but commonly used.
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function slug(): static
    {
        $this->rules['format'] = 'slug';
        return $this;
    }

    // ========================================================================
    // Constraint Validators
    // ========================================================================

    /**
     * Validate minimum string length
     *
     * @param int $min Minimum length
     * @return static
     */
    public function minLength(int $min): static
    {
        return $this->setRule('minLength', $min);
    }

    /**
     * Validate maximum string length
     *
     * @param int $max Maximum length
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function maxLength(int $max): static
    {
        return $this->setRule('maxLength', $max);
    }

    /**
     * Validate exact value match (JSON Schema: const)
     *
     * @param mixed $value Exact value required
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function const(mixed $value): static
    {
        return $this->setRule('const', $value);
    }

    /**
     * Validate value is in allowed list (JSON Schema: enum)
     *
     * @param array $allowed Allowed values
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function enum(array $allowed): static
    {
        return $this->setRule('enum', $allowed);
    }

    /**
     * Validate minimum value (inclusive) - JSON Schema: minimum
     *
     * @param int|float $min Minimum value
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function minimum(int|float $min): static
    {
        return $this->setRule('minimum', $min);
    }

    /**
     * Validate maximum value (inclusive) - JSON Schema: maximum
     *
     * @param int|float $max Maximum value
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function maximum(int|float $max): static
    {
        return $this->setRule('maximum', $max);
    }

    /**
     * Validate minimum value (exclusive) - JSON Schema: exclusiveMinimum
     *
     * @param int|float $min Minimum value (exclusive)
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function exclusiveMinimum(int|float $min): static
    {
        return $this->setRule('exclusiveMinimum', $min);
    }

    /**
     * Validate maximum value (exclusive) - JSON Schema: exclusiveMaximum
     *
     * @param int|float $max Maximum value (exclusive)
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function exclusiveMaximum(int|float $max): static
    {
        return $this->setRule('exclusiveMaximum', $max);
    }

    /**
     * Validate number is a multiple of value (JSON Schema: multipleOf)
     *
     * @param int|float $divisor Number must be divisible by this value
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function multipleOf(int|float $divisor): static
    {
        return $this->setRule('multipleOf', $divisor);
    }

    /**
     * Validate minimum array length (JSON Schema: minItems)
     *
     * @param int $min Minimum number of items
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function minItems(int $min): static
    {
        return $this->setRule('minItems', $min);
    }

    /**
     * Validate maximum array length (JSON Schema: maxItems)
     *
     * @param int $max Maximum number of items
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function maxItems(int $max): static
    {
        return $this->setRule('maxItems', $max);
    }

    /**
     * Validate minimum number of object properties (JSON Schema: minProperties)
     *
     * @param int $min Minimum number of properties
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function minProperties(int $min): static
    {
        return $this->setRule('minProperties', $min);
    }

    /**
     * Validate maximum number of object properties (JSON Schema: maxProperties)
     *
     * @param int $max Maximum number of properties
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function maxProperties(int $max): static
    {
        return $this->setRule('maxProperties', $max);
    }

    /**
     * Validate dependent required properties (JSON Schema: dependentRequired)
     *
     * When a property exists, require other properties to also exist.
     *
     * @param string $property The property that triggers the requirement
     * @param array $requiredProperties Properties required when $property exists
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function dependentRequired(string $property, array $requiredProperties): static
    {
        $this->rules['dependentRequired'] = [$property => $requiredProperties];
        return $this;
    }

    /**
     * Validate array has unique items (JSON Schema: uniqueItems)
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function uniqueItems(): static
    {
        $this->rules['uniqueItems'] = true;
        return $this;
    }

    /**
     * Validate all array items against a schema (JSON Schema: items)
     *
     * All items in the array must pass the provided validator.
     *
     * @param Validator|array<Validator> $validator Validator for all items, or array of validators for tuple validation
     * @return static
     */
    public function items(Validator|array $validator): static
    {
        return $this->setRule('items', $validator);
    }

    /**
     * Validate additional items beyond tuple schema (JSON Schema: additionalItems)
     *
     * When items() is an array (tuple validation), this validates items beyond
     * the defined positions.
     *
     * @param Validator|bool $validator Validator for additional items, or false to disallow
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function additionalItems(Validator|bool $validator): static
    {
        return $this->setRule('additionalItems', $validator);
    }

    /**
     * Validate minimum number of items matching a schema (JSON Schema: minContains)
     *
     * Requires at least $min items in the array to pass the validator.
     *
     * @param int $min Minimum number of matching items
     * @param Validator $validator Validator that items must match
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function minContains(int $min, Validator $validator): static
    {
        $this->rules['minContains'] = ['min' => $min, 'validator' => $validator];
        return $this;
    }

    /**
     * Validate maximum number of items matching a schema (JSON Schema: maxContains)
     *
     * Requires at most $max items in the array to pass the validator.
     *
     * @param int $max Maximum number of matching items
     * @param Validator $validator Validator that items must match
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function maxContains(int $max, Validator $validator): static
    {
        $this->rules['maxContains'] = ['max' => $max, 'validator' => $validator];
        return $this;
    }

    /**
     * Validate value matches regex pattern
     *
     * @param string $pattern Regular expression pattern
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function pattern(string $pattern): static
    {
        return $this->setRule('pattern', $pattern);
    }

    // ========================================================================
    // Combinators (JSON Schema: anyOf, allOf, oneOf, not)
    // ========================================================================

    /**
     * Validate against any of the provided validators (JSON Schema: anyOf)
     *
     * The value is valid if it passes at least one of the validators.
     *
     * @param array<Validator> $validators Array of Validator instances
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function anyOf(array $validators): static
    {
        return $this->setRule('anyOf', $validators);
    }

    /**
     * Validate against all of the provided validators (JSON Schema: allOf)
     *
     * The value is valid only if it passes all validators.
     *
     * @param array<Validator> $validators Array of Validator instances
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function allOf(array $validators): static
    {
        return $this->setRule('allOf', $validators);
    }

    /**
     * Validate against exactly one of the provided validators (JSON Schema: oneOf)
     *
     * The value is valid only if it passes exactly one validator (not zero, not multiple).
     *
     * @param array<Validator> $validators Array of Validator instances
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function oneOf(array $validators): static
    {
        return $this->setRule('oneOf', $validators);
    }

    /**
     * Validate that value does NOT match validator (JSON Schema: not)
     *
     * The value is valid only if it fails the provided validator.
     *
     * @param Validator $validator Validator instance
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function not(Validator $validator): static
    {
        return $this->setRule('not', $validator);
    }

    // ========================================================================
    // Custom Validator (NOT exportable to JSON Schema)
    // ========================================================================

    /**
     * Custom validation callback (for server-side validation only)
     *
     * Use this for PHP-specific validations that cannot be exported to JSON Schema,
     * such as instance checks, filter_var validations, or any custom PHP logic.
     *
     * The callback should return truthy if valid, falsy if invalid.
     *
     * Example:
     * ```php
     * $validator->custom(
     *     fn($v) => $v instanceof SomeClass,
     *     mini\t("Must be instance of SomeClass")
     * );
     * ```
     *
     * @param Closure $callback Validation function: fn($value) => bool
     * @param string|Stringable $message Error message to return on failure (required)
     * @return static
     */
    public function custom(Closure $callback): static
    {
        return $this->setRule('custom:' . spl_object_id($callback), $callback);
    }

    // ========================================================================
    // Composition
    // ========================================================================

    /**
     * Add validator for object property or array key
     *
     * @param string $property Property name or array key
     * @param Validator $validator Validator for this property
     * @return static
     */
    public function forProperty(string $property, Validator $validator): static
    {
        $this->propertyValidators[$property] = $validator;
        return $this;
    }

    /**
     * Define validators for multiple properties (JSON Schema: properties)
     *
     * Bulk version of forProperty(). Validates specific named properties.
     *
     * @param array<string, Validator> $properties Property name => Validator map
     * @return static
     */
    public function properties(array $properties): static
    {
        foreach ($properties as $property => $validator) {
            if (!($validator instanceof Validator)) {
                throw new \InvalidArgumentException("properties() requires Validator instances");
            }
            $this->propertyValidators[$property] = $validator;
        }
        return $this;
    }

    /**
     * Validate properties matching regex patterns (JSON Schema: patternProperties)
     *
     * Properties whose names match the pattern will be validated against the schema.
     *
     * @param string $pattern Regex pattern for property names
     * @param Validator $validator Validator for matching properties
     * @return static
     */
    public function patternProperties(string $pattern, Validator $validator): static
    {
        $this->patternPropertyValidators[$pattern] = $validator;
        return $this;
    }

    /**
     * Validate additional properties (JSON Schema: additionalProperties)
     *
     * Controls validation of properties not defined in properties() or patternProperties().
     *
     * @param Validator|bool $validator Validator for additional properties, or false to disallow
     * @return static
     */
    public function additionalProperties(Validator|bool $validator): static
    {
        if ($validator === false) {
            $this->allowAdditionalProperties = false;
            $this->additionalPropertiesValidator = null;
        } elseif ($validator === true) {
            $this->allowAdditionalProperties = true;
            $this->additionalPropertiesValidator = null;
        } else {
            $this->allowAdditionalProperties = true;
            $this->additionalPropertiesValidator = $validator;
        }
        return $this;
    }

    /**
     * Remove fields from validation
     *
     * Returns a clone without specified property validators.
     *
     * @param array $properties Property names to remove
     * @return static
     */
    public function withoutFields(array $properties): static
    {
        $clone = clone $this;
        foreach ($properties as $property) {
            unset($clone->propertyValidators[$property]);
        }
        return $clone;
    }

    /**
     * Keep only specified fields for validation
     *
     * Returns a clone with only the specified property validators.
     *
     * @param array $fields Property names to keep
     * @return static
     */
    public function withFields(array $fields): static
    {
        $clone = clone $this;
        foreach ($clone->propertyValidators as $property => $validator) {
            if (!in_array($property, $fields, true)) {
                unset($clone->propertyValidators[$property]);
            }
        }
        return $clone;
    }

    /**
     * Deep clone property validators
     */
    public function __clone()
    {
        foreach ($this->propertyValidators as $prop => $validator) {
            $this->propertyValidators[$prop] = clone $validator;
        }
        foreach ($this->patternPropertyValidators as $pattern => $validator) {
            $this->patternPropertyValidators[$pattern] = clone $validator;
        }
        if ($this->additionalPropertiesValidator !== null) {
            $this->additionalPropertiesValidator = clone $this->additionalPropertiesValidator;
        }
    }

    // ========================================================================
    // Validation Helper Methods
    // ========================================================================

    private function validateType(string|array $types, mixed $value): ?string
    {
        $typeArray = is_array($types) ? $types : [$types];

        foreach ($typeArray as $type) {
            $valid = match($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && !array_is_list($value),
                'null' => $value === null,
                default => false
            };

            if ($valid) {
                return null;
            }
        }

        $typeList = count($typeArray) === 1
            ? "a {$typeArray[0]}"
            : implode(' or ', array_map(fn($t) => "a $t", $typeArray));
        return \mini\t("Must be {types}", ['types' => $typeList]);
    }

    private function validateFormat(string $format, mixed $value): ?string
    {
        if ($value === null) return null;

        $valid = match($format) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'uri' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'date-time' => (bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value),
            'date' => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate(...array_map('intval', explode('-', $value))),
            'time' => (bool)preg_match('/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)(\.\d+)?$/', $value),
            'ipv4' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'ipv6' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            'uuid' => (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value),
            'slug' => (bool)preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value),
            default => true
        };

        return $valid ? null : \mini\t("Invalid {format} format.", ['format' => $format]);
    }

    private function validateItems(Validator|array $validator, mixed $value): ?string
    {
        if ($value === null) return null;
        if (!is_array($value)) return \mini\t("Must be an array.");

        if (is_array($validator)) {
            // Tuple validation
            foreach ($validator as $index => $itemValidator) {
                if (!isset($value[$index])) continue;
                $error = $itemValidator->isInvalid($value[$index]);
                if ($error !== null) {
                    return \mini\t("Item at index {index} is invalid: {error}", [
                        'index' => $index,
                        'error' => $error
                    ]);
                }
            }
        } else {
            // All items validation
            foreach ($value as $index => $item) {
                $error = $validator->isInvalid($item);
                if ($error !== null) {
                    return \mini\t("Item at index {index} is invalid: {error}", [
                        'index' => $index,
                        'error' => $error
                    ]);
                }
            }
        }

        return null;
    }

    private function validateAnyOf(array $validators, mixed $value): ?string
    {
        foreach ($validators as $validator) {
            if ($validator->isInvalid($value) === null) {
                return null;
            }
        }
        return \mini\t("Must match at least one of the allowed types.");
    }

    private function validateAllOf(array $validators, mixed $value): ?string
    {
        foreach ($validators as $validator) {
            $error = $validator->isInvalid($value);
            if ($error !== null) {
                return $error;
            }
        }
        return null;
    }

    private function validateOneOf(array $validators, mixed $value): ?string
    {
        $passedCount = 0;
        foreach ($validators as $validator) {
            if ($validator->isInvalid($value) === null) {
                $passedCount++;
            }
        }
        return $passedCount === 1 ? null : \mini\t("Must match exactly one of the allowed types.");
    }

    private function validateNot(Validator $validator, mixed $value): ?string
    {
        return $validator->isInvalid($value) === null ? \mini\t("Must not match the disallowed type.") : null;
    }

    private function validateAdditionalItems(Validator|bool $validator, mixed $value): ?string
    {
        if ($value === null) return null;
        if (!is_array($value)) return \mini\t("Must be an array.");

        if ($validator === false) {
            return null; // Would need tuple schema count to enforce
        } elseif ($validator instanceof Validator) {
            foreach ($value as $index => $item) {
                $error = $validator->isInvalid($item);
                if ($error !== null) {
                    return \mini\t("Additional item at index {index} is invalid: {error}", [
                        'index' => $index,
                        'error' => $error
                    ]);
                }
            }
        }

        return null;
    }

    private function validateMinContains(array $rule, mixed $value): ?string
    {
        if ($value === null) return null;
        if (!is_array($value)) return \mini\t("Must be an array.");

        $min = $rule['min'];
        $validator = $rule['validator'];
        $matchCount = 0;

        foreach ($value as $item) {
            if ($validator->isInvalid($item) === null) {
                $matchCount++;
            }
        }

        return $matchCount < $min ? \mini\t("Must contain at least {min} matching items.", ['min' => $min]) : null;
    }

    private function validateMaxContains(array $rule, mixed $value): ?string
    {
        if ($value === null) return null;
        if (!is_array($value)) return \mini\t("Must be an array.");

        $max = $rule['max'];
        $validator = $rule['validator'];
        $matchCount = 0;

        foreach ($value as $item) {
            if ($validator->isInvalid($item) === null) {
                $matchCount++;
            }
        }

        return $matchCount > $max ? \mini\t("Must contain at most {max} matching items.", ['max' => $max]) : null;
    }

    private function validateDependentRequired(array $dependencies, mixed $value): ?string
    {
        if ($value === null) return null;
        if (!is_array($value)) return \mini\t("Must be an object.");

        foreach ($dependencies as $property => $requiredProperties) {
            if (isset($value[$property])) {
                foreach ($requiredProperties as $requiredProp) {
                    if (!isset($value[$requiredProp])) {
                        return \mini\t("Property '{prop}' requires '{required}' to be present.", [
                            'prop' => $property,
                            'required' => $requiredProp
                        ]);
                    }
                }
            }
        }

        return null;
    }


    /**
     * Export validator as JSON Schema (JsonSerializable interface)
     *
     * This enables automatic recursive serialization when using json_encode().
     * Child Validator instances are automatically serialized recursively.
     *
     * @return array JSON Schema representation
     */
    public function jsonSerialize(): array
    {
        $schema = [];

        // Add all rules (excluding custom: rules which aren't JSON Schema)
        foreach ($this->rules as $keyword => $value) {
            if (!str_starts_with($keyword, 'custom:')) {
                $schema[$keyword] = $value;
            }
        }

        // Properties
        if (!empty($this->propertyValidators)) {
            $schema['properties'] = [];
            $required = [];
            foreach ($this->propertyValidators as $prop => $validator) {
                $schema['properties'][$prop] = $validator;
                if ($validator->isRequired) {
                    $required[] = $prop;
                }
            }
            if (!empty($required)) {
                $schema['required'] = $required;
            }
        }

        // Pattern properties
        if (!empty($this->patternPropertyValidators)) {
            $schema['patternProperties'] = [];
            foreach ($this->patternPropertyValidators as $pattern => $validator) {
                $schema['patternProperties'][$pattern] = $validator;
            }
        }

        // Additional properties
        if (!$this->allowAdditionalProperties) {
            $schema['additionalProperties'] = false;
        } elseif ($this->additionalPropertiesValidator !== null) {
            $schema['additionalProperties'] = $this->additionalPropertiesValidator;
        }

        return $schema;
    }
}
