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
     * Validate object or array type
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
     * Validate array type
     *
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function isArray(string|Stringable|null $message = null): static
    {
        $this->rules[] = fn($v) => is_array($v) ? null
            : ($message ?? \mini\t("Must be an array"));
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
    // Format Validators
    // ========================================================================

    /**
     * Validate email format
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
     * Validate URL format
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
     * Validate slug format (URL-safe string)
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
     * Validate minimum value (inclusive)
     *
     * @param mixed $min Minimum value
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function minVal(mixed $min, string|Stringable|null $message = null): static
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
     * Validate minimum value (exclusive)
     *
     * @param mixed $min Minimum value (exclusive)
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function minValEx(mixed $min, string|Stringable|null $message = null): static
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
     * Validate maximum value (inclusive)
     *
     * @param mixed $max Maximum value
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function maxVal(mixed $max, string|Stringable|null $message = null): static
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
     * Validate maximum value (exclusive)
     *
     * @param mixed $max Maximum value (exclusive)
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function maxValEx(mixed $max, string|Stringable|null $message = null): static
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
     * Validate value is in allowed list
     *
     * @param array $allowed Allowed values
     * @param string|Stringable|null $message Custom error message
     * @return static
     */
    public function oneOf(array $allowed, string|Stringable|null $message = null): static
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
