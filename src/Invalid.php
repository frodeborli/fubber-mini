<?php

namespace mini;

class Invalid
{
    /**
     * Check if a value is required (not null or empty string)
     */
    public static function required($value, ?Translatable $message = null): ?Translatable
    {
        if ($value === null || $value === '') {
            return $message ?? t("This field is required.");
        }
        return null;
    }

    /**
     * Validate email address
     */
    public static function email($value, ?Translatable $message = null): ?Translatable
    {
        if ($value === null) {
            return null;
        }

        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return $message ?? t("Please enter a valid email address.");
        }
        return null;
    }

    /**
     * Validate URL
     */
    public static function url($value, ?Translatable $message = null): ?Translatable
    {
        if ($value === null) {
            return null;
        }

        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return $message ?? t("Please enter a valid URL.");
        }
        return null;
    }

    /**
     * Validate slug (URL-safe string)
     */
    public static function slug($value, ?Translatable $message = null): ?Translatable
    {
        if ($value === null) {
            return null;
        }

        if (!preg_match('/^[a-z0-9\-_]+$/i', $value)) {
            return $message ?? t("Only letters, numbers, hyphens and underscores are allowed.");
        }
        return null;
    }

    /**
     * Validate integer
     */
    public static function integer($value, ?Translatable $message = null): ?Translatable
    {
        if ($value === null) {
            return null;
        }

        if (!filter_var($value, FILTER_VALIDATE_INT)) {
            return $message ?? t("Please enter a valid integer.");
        }
        return null;
    }

    /**
     * Validate number (int or float)
     */
    public static function number($value, ?Translatable $message = null): ?Translatable
    {
        if ($value === null) {
            return null;
        }

        if (!is_numeric($value)) {
            return $message ?? t("Please enter a valid number.");
        }
        return null;
    }

    /**
     * Validate minimum length
     */
    public static function minLength($value, int $min, ?Translatable $message = null): ?Translatable
    {
        if ($value === null) {
            return null;
        }

        if (strlen($value) < $min) {
            return $message ?? t("Must be at least {min} characters long.", ['min' => $min]);
        }
        return null;
    }

    /**
     * Validate maximum length
     */
    public static function maxLength($value, int $max, ?Translatable $message = null): ?Translatable
    {
        if ($value === null) {
            return null;
        }

        if (strlen($value) > $max) {
            return $message ?? t("Must be {max} characters or less.", ['max' => $max]);
        }
        return null;
    }

    /**
     * Validate minimum value (inclusive)
     */
    public static function minVal($value, $min, ?Translatable $message = null): ?Translatable
    {
        if ($value === null) {
            return null;
        }

        if ($value < $min) {
            return $message ?? t("Must be at least {min}.", ['min' => $min]);
        }
        return null;
    }

    /**
     * Validate minimum value (exclusive)
     */
    public static function minValEx($value, $min, ?Translatable $message = null): ?Translatable
    {
        if ($value === null) {
            return null;
        }

        if ($value <= $min) {
            return $message ?? t("Must be greater than {min}.", ['min' => $min]);
        }
        return null;
    }

    /**
     * Validate maximum value (inclusive)
     */
    public static function maxVal($value, $max, ?Translatable $message = null): ?Translatable
    {
        if ($value === null) {
            return null;
        }

        if ($value > $max) {
            return $message ?? t("Must be {max} or less.", ['max' => $max]);
        }
        return null;
    }

    /**
     * Validate maximum value (exclusive)
     */
    public static function maxValEx($value, $max, ?Translatable $message = null): ?Translatable
    {
        if ($value === null) {
            return null;
        }

        if ($value >= $max) {
            return $message ?? t("Must be less than {max}.", ['max' => $max]);
        }
        return null;
    }

    /**
     * Validate that value is in allowed list
     */
    public static function oneOf($value, array $allowed, ?Translatable $message = null): ?Translatable
    {
        if ($value === null) {
            return null;
        }

        if (!in_array($value, $allowed, true)) {
            return $message ?? t("Please select a valid option.");
        }
        return null;
    }

    /**
     * Validate regex pattern
     */
    public static function pattern($value, string $pattern, ?Translatable $message = null): ?Translatable
    {
        if ($value === null) {
            return null;
        }

        if (!preg_match($pattern, $value)) {
            return $message ?? t("Invalid format.");
        }
        return null;
    }
}