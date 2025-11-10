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
class Validator
{
    private array $rules = [];
    private array $propertyValidators = [];
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
        if (!empty($this->propertyValidators) && (is_array($value) || is_object($value))) {
            $errors = [];
            foreach ($this->propertyValidators as $property => $validator) {
                $propValue = is_array($value) ? ($value[$property] ?? null)
                                              : ($value->$property ?? null);
                if ($error = $validator->isInvalid($propValue)) {
                    $errors[$property] = $error;
                }
            }
            return $errors ?: null;
        }

        // Run rules until first error (fail fast)
        foreach ($this->rules as $rule) {
            if ($error = $rule($value)) {
                return $error;
            }
        }

        return null;
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
    // Type Validators
    // ========================================================================

    /**
     * Validate string type
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function isString(string|Stringable|null $message = null): static
    {
        $this->rules[] = fn($v) => is_string($v) ? null
            : ($message ?? \mini\t("Must be a string"));
        return $this;
    }

    /**
     * Validate integer type
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function isInt(string|Stringable|null $message = null): static
    {
        $this->rules[] = fn($v) => is_int($v) || (is_string($v) && ctype_digit($v)) ? null
            : ($message ?? \mini\t("Must be an integer"));
        return $this;
    }

    /**
     * Validate numeric type (int or float)
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function isNumber(string|Stringable|null $message = null): static
    {
        $this->rules[] = fn($v) => is_numeric($v) ? null
            : ($message ?? \mini\t("Must be a number"));
        return $this;
    }

    /**
     * Validate boolean type
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function isBool(string|Stringable|null $message = null): static
    {
        $this->rules[] = fn($v) => is_bool($v) || in_array($v, [0, 1, '0', '1', 'true', 'false'], true) ? null
            : ($message ?? \mini\t("Must be a boolean"));
        return $this;
    }

    /**
     * Validate object/hash map type (JSON Schema: type "object")
     *
     * Accepts both PHP objects and associative arrays (hash maps).
     * For list validation, use isArray() instead.
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function isObject(string|Stringable|null $message = null): static
    {
        $this->rules[] = fn($v) => is_object($v) || is_array($v) ? null
            : ($message ?? \mini\t("Must be an object"));
        return $this;
    }

    /**
     * Validate array/list type (JSON Schema: type "array")
     *
     * Only accepts sequential arrays (lists), not associative arrays.
     * Uses array_is_list() to match JavaScript array semantics.
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function isArray(string|Stringable|null $message = null): static
    {
        $this->rules[] = fn($v) => is_array($v) && array_is_list($v) ? null
            : ($message ?? \mini\t("Must be an array"));
        return $this;
    }

    /**
     * Validate null type (JSON Schema: type "null")
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function isNull(string|Stringable|null $message = null): static
    {
        $this->rules[] = fn($v) => $v === null ? null
            : ($message ?? \mini\t("Must be null"));
        return $this;
    }

    /**
     * Validate instance of class or interface
     *
     * @param string $classOrInterface Fully-qualified class or interface name
     * @param string|Stringable $message Error message (required, no default)
     * @return static
     */
    public function isInstanceOf(string $classOrInterface, string|Stringable $message): static
    {
        $this->rules[] = fn($v) => $v instanceof $classOrInterface ? null : $message;
        return $this;
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
    public function email(string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($message) {
            if ($v === null) {
                return null;
            }
            if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
                return $message ?? \mini\t("Please enter a valid email address.");
            }
            return null;
        };
        return $this;
    }

    /**
     * Validate URL format (JSON Schema: format "uri")
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function url(string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($message) {
            if ($v === null) {
                return null;
            }
            if (!filter_var($v, FILTER_VALIDATE_URL)) {
                return $message ?? \mini\t("Please enter a valid URL.");
            }
            return null;
        };
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
    public function dateTime(string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($message) {
            if ($v === null) {
                return null;
            }
            if (!is_string($v)) {
                return $message ?? \mini\t("Must be a valid date-time string.");
            }
            // ISO 8601 validation
            if (!preg_match('/^\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}:\d{2}/', $v)) {
                return $message ?? \mini\t("Must be a valid ISO 8601 date-time.");
            }
            // Try to parse to ensure validity
            try {
                new \DateTime($v);
            } catch (\Exception $e) {
                return $message ?? \mini\t("Must be a valid date-time.");
            }
            return null;
        };
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
    public function date(string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($message) {
            if ($v === null) {
                return null;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                return $message ?? \mini\t("Must be a valid date (YYYY-MM-DD).");
            }
            // Validate it's a real date
            $parts = explode('-', $v);
            if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
                return $message ?? \mini\t("Must be a valid date.");
            }
            return null;
        };
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
    public function time(string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($message) {
            if ($v === null) {
                return null;
            }
            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)(\.\d+)?$/', $v)) {
                return $message ?? \mini\t("Must be a valid time (HH:MM:SS).");
            }
            return null;
        };
        return $this;
    }

    /**
     * Validate IPv4 address (JSON Schema: format "ipv4")
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function ipv4(string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($message) {
            if ($v === null) {
                return null;
            }
            if (!filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $message ?? \mini\t("Must be a valid IPv4 address.");
            }
            return null;
        };
        return $this;
    }

    /**
     * Validate IPv6 address (JSON Schema: format "ipv6")
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function ipv6(string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($message) {
            if ($v === null) {
                return null;
            }
            if (!filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return $message ?? \mini\t("Must be a valid IPv6 address.");
            }
            return null;
        };
        return $this;
    }

    /**
     * Validate UUID format (JSON Schema: format "uuid")
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function uuid(string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($message) {
            if ($v === null) {
                return null;
            }
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v)) {
                return $message ?? \mini\t("Must be a valid UUID.");
            }
            return null;
        };
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
    public function slug(string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($message) {
            if ($v === null) {
                return null;
            }
            if (!preg_match('/^[a-z0-9\-_]+$/i', $v)) {
                return $message ?? \mini\t("Only letters, numbers, hyphens and underscores are allowed.");
            }
            return null;
        };
        return $this;
    }

    // ========================================================================
    // Constraint Validators
    // ========================================================================

    /**
     * Validate minimum string length
     *
     * @param int $min Minimum length
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function minLength(int $min, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($min, $message) {
            if ($v === null) {
                return null;
            }
            if (strlen($v) < $min) {
                return $message ?? \mini\t("Must be at least {min} characters long.", ['min' => $min]);
            }
            return null;
        };
        return $this;
    }

    /**
     * Validate maximum string length
     *
     * @param int $max Maximum length
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function maxLength(int $max, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($max, $message) {
            if ($v === null) {
                return null;
            }
            if (strlen($v) > $max) {
                return $message ?? \mini\t("Must be {max} characters or less.", ['max' => $max]);
            }
            return null;
        };
        return $this;
    }

    /**
     * Validate exact value match (JSON Schema: const)
     *
     * @param mixed $value Exact value required
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function const(mixed $value, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($value, $message) {
            if ($v === null) {
                return null;
            }
            if ($v !== $value) {
                return $message ?? \mini\t("Must be exactly {value}.", ['value' => $value]);
            }
            return null;
        };
        return $this;
    }

    /**
     * Validate value is in allowed list (JSON Schema: enum)
     *
     * @param array $allowed Allowed values
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function enum(array $allowed, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($allowed, $message) {
            if ($v === null) {
                return null;
            }
            if (!in_array($v, $allowed, true)) {
                return $message ?? \mini\t("Please select a valid option.");
            }
            return null;
        };
        return $this;
    }

    /**
     * Validate minimum value (inclusive) - JSON Schema: minimum
     *
     * @param int|float $min Minimum value
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function minimum(int|float $min, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($min, $message) {
            if ($v === null) {
                return null;
            }
            if ($v < $min) {
                return $message ?? \mini\t("Must be at least {min}.", ['min' => $min]);
            }
            return null;
        };
        return $this;
    }

    /**
     * Validate maximum value (inclusive) - JSON Schema: maximum
     *
     * @param int|float $max Maximum value
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function maximum(int|float $max, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($max, $message) {
            if ($v === null) {
                return null;
            }
            if ($v > $max) {
                return $message ?? \mini\t("Must be {max} or less.", ['max' => $max]);
            }
            return null;
        };
        return $this;
    }

    /**
     * Validate minimum value (exclusive) - JSON Schema: exclusiveMinimum
     *
     * @param int|float $min Minimum value (exclusive)
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function exclusiveMinimum(int|float $min, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($min, $message) {
            if ($v === null) {
                return null;
            }
            if ($v <= $min) {
                return $message ?? \mini\t("Must be greater than {min}.", ['min' => $min]);
            }
            return null;
        };
        return $this;
    }

    /**
     * Validate maximum value (exclusive) - JSON Schema: exclusiveMaximum
     *
     * @param int|float $max Maximum value (exclusive)
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function exclusiveMaximum(int|float $max, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($max, $message) {
            if ($v === null) {
                return null;
            }
            if ($v >= $max) {
                return $message ?? \mini\t("Must be less than {max}.", ['max' => $max]);
            }
            return null;
        };
        return $this;
    }

    /**
     * Validate number is a multiple of value (JSON Schema: multipleOf)
     *
     * @param int|float $divisor Number must be divisible by this value
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function multipleOf(int|float $divisor, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($divisor, $message) {
            if ($v === null) {
                return null;
            }
            if (!is_numeric($v)) {
                return $message ?? \mini\t("Must be a number.");
            }
            $remainder = fmod((float)$v, (float)$divisor);
            if (abs($remainder) > PHP_FLOAT_EPSILON) {
                return $message ?? \mini\t("Must be a multiple of {divisor}.", ['divisor' => $divisor]);
            }
            return null;
        };
        return $this;
    }

    /**
     * Validate minimum array length (JSON Schema: minItems)
     *
     * @param int $min Minimum number of items
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function minItems(int $min, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($min, $message) {
            if ($v === null) {
                return null;
            }
            if (!is_array($v)) {
                return $message ?? \mini\t("Must be an array.");
            }
            if (count($v) < $min) {
                return $message ?? \mini\t("Must have at least {min} items.", ['min' => $min]);
            }
            return null;
        };
        return $this;
    }

    /**
     * Validate maximum array length (JSON Schema: maxItems)
     *
     * @param int $max Maximum number of items
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function maxItems(int $max, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($max, $message) {
            if ($v === null) {
                return null;
            }
            if (!is_array($v)) {
                return $message ?? \mini\t("Must be an array.");
            }
            if (count($v) > $max) {
                return $message ?? \mini\t("Must have at most {max} items.", ['max' => $max]);
            }
            return null;
        };
        return $this;
    }

    /**
     * Validate minimum number of object properties (JSON Schema: minProperties)
     *
     * @param int $min Minimum number of properties
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function minProperties(int $min, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($min, $message) {
            if ($v === null) {
                return null;
            }
            if (!is_array($v) && !is_object($v)) {
                return $message ?? \mini\t("Must be an object or array.");
            }
            $count = is_array($v) ? count($v) : count(get_object_vars($v));
            if ($count < $min) {
                return $message ?? \mini\t("Must have at least {min} properties.", ['min' => $min]);
            }
            return null;
        };
        return $this;
    }

    /**
     * Validate maximum number of object properties (JSON Schema: maxProperties)
     *
     * @param int $max Maximum number of properties
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function maxProperties(int $max, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($max, $message) {
            if ($v === null) {
                return null;
            }
            if (!is_array($v) && !is_object($v)) {
                return $message ?? \mini\t("Must be an object or array.");
            }
            $count = is_array($v) ? count($v) : count(get_object_vars($v));
            if ($count > $max) {
                return $message ?? \mini\t("Must have at most {max} properties.", ['max' => $max]);
            }
            return null;
        };
        return $this;
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
    public function dependentRequired(string $property, array $requiredProperties, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($property, $requiredProperties, $message) {
            if ($v === null) {
                return null;
            }
            if (!is_array($v) && !is_object($v)) {
                return $message ?? \mini\t("Must be an object or array.");
            }

            // Check if the triggering property exists
            $hasProperty = is_array($v)
                ? array_key_exists($property, $v)
                : property_exists($v, $property);

            if (!$hasProperty) {
                return null; // Property doesn't exist, no dependencies to check
            }

            // Property exists, check for required dependencies
            $missing = [];
            foreach ($requiredProperties as $required) {
                $hasRequired = is_array($v)
                    ? array_key_exists($required, $v)
                    : property_exists($v, $required);

                if (!$hasRequired) {
                    $missing[] = $required;
                }
            }

            if (!empty($missing)) {
                return $message ?? \mini\t(
                    "When '{property}' is present, {required} must also be present.",
                    [
                        'property' => $property,
                        'required' => implode(', ', array_map(fn($p) => "'$p'", $missing))
                    ]
                );
            }

            return null;
        };
        return $this;
    }

    /**
     * Validate array has unique items (JSON Schema: uniqueItems)
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function uniqueItems(string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($message) {
            if ($v === null) {
                return null;
            }
            if (!is_array($v)) {
                return $message ?? \mini\t("Must be an array.");
            }

            // Use JSON encoding for deep comparison
            $serialized = array_map('json_encode', $v);
            if (count($serialized) !== count(array_unique($serialized))) {
                return $message ?? \mini\t("Array must contain only unique items.");
            }

            return null;
        };
        return $this;
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
    public function minContains(int $min, Validator $validator, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($min, $validator, $message) {
            if ($v === null) {
                return null;
            }
            if (!is_array($v)) {
                return $message ?? \mini\t("Must be an array.");
            }

            $matchCount = 0;
            foreach ($v as $item) {
                if ($validator->isInvalid($item) === null) {
                    $matchCount++;
                }
            }

            if ($matchCount < $min) {
                return $message ?? \mini\t("Must contain at least {min} matching items.", ['min' => $min]);
            }

            return null;
        };
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
    public function maxContains(int $max, Validator $validator, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($max, $validator, $message) {
            if ($v === null) {
                return null;
            }
            if (!is_array($v)) {
                return $message ?? \mini\t("Must be an array.");
            }

            $matchCount = 0;
            foreach ($v as $item) {
                if ($validator->isInvalid($item) === null) {
                    $matchCount++;
                }
            }

            if ($matchCount > $max) {
                return $message ?? \mini\t("Must contain at most {max} matching items.", ['max' => $max]);
            }

            return null;
        };
        return $this;
    }

    /**
     * Validate value matches regex pattern
     *
     * @param string $pattern Regular expression pattern
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function pattern(string $pattern, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($pattern, $message) {
            if ($v === null) {
                return null;
            }
            if (!preg_match($pattern, $v)) {
                return $message ?? \mini\t("Invalid format.");
            }
            return null;
        };
        return $this;
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
    public function anyOf(array $validators, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($validators, $message) {
            if ($v === null) {
                return null;
            }

            foreach ($validators as $validator) {
                if (!($validator instanceof Validator)) {
                    throw new \InvalidArgumentException("anyOf requires an array of Validator instances");
                }
                $error = $validator->isInvalid($v);
                if ($error === null) {
                    return null; // Valid - at least one passed
                }
            }

            return $message ?? \mini\t("Must match at least one of the allowed types.");
        };
        return $this;
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
    public function allOf(array $validators, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($validators, $message) {
            if ($v === null) {
                return null;
            }

            foreach ($validators as $validator) {
                if (!($validator instanceof Validator)) {
                    throw new \InvalidArgumentException("allOf requires an array of Validator instances");
                }
                $error = $validator->isInvalid($v);
                if ($error !== null) {
                    return $message ?? $error; // Failed - return first error
                }
            }

            return null; // Valid - all passed
        };
        return $this;
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
    public function oneOf(array $validators, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($validators, $message) {
            if ($v === null) {
                return null;
            }

            $passedCount = 0;
            foreach ($validators as $validator) {
                if (!($validator instanceof Validator)) {
                    throw new \InvalidArgumentException("oneOf requires an array of Validator instances");
                }
                $error = $validator->isInvalid($v);
                if ($error === null) {
                    $passedCount++;
                }
            }

            if ($passedCount === 1) {
                return null; // Valid - exactly one passed
            }

            return $message ?? \mini\t("Must match exactly one of the allowed types.");
        };
        return $this;
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
    public function not(Validator $validator, string|Stringable|null $message = null): static
    {
        $this->rules[] = function($v) use ($validator, $message) {
            if ($v === null) {
                return null;
            }

            $error = $validator->isInvalid($v);
            if ($error === null) {
                return $message ?? \mini\t("Must not match the disallowed type.");
            }

            return null; // Valid - validation failed as expected
        };
        return $this;
    }

    // ========================================================================
    // Advanced Validators
    // ========================================================================

    /**
     * Custom validation callback
     *
     * The callback should return truthy if valid, falsy if invalid.
     *
     * @param Closure $callback Validation function: fn($value) => bool
     * @param string|Stringable $message Error message to return on failure (required)
     * @return static
     */
    public function callback(Closure $callback, string|Stringable $message): static
    {
        $this->rules[] = function($v) use ($callback, $message) {
            return $callback($v) ? null : $message;
        };
        return $this;
    }

    /**
     * Validate using PHP's filter_var()
     *
     * @param int $filter Filter constant (e.g., FILTER_VALIDATE_EMAIL, FILTER_VALIDATE_INT)
     * @param array|int $options Filter options
     * @param string|Stringable $message Error message (required, no default)
     * @return static
     */
    public function filter(int $filter = FILTER_DEFAULT, array|int $options = 0, string|Stringable $message = ''): static
    {
        $this->rules[] = function($v) use ($filter, $options, $message) {
            if ($v === null) {
                return null;
            }
            return filter_var($v, $filter, $options) !== false ? null : $message;
        };
        return $this;
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
    }
}
