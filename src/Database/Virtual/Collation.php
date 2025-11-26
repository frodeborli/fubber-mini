<?php

namespace mini\Database\Virtual;

/**
 * Helper functions for creating common Collator configurations
 *
 * VirtualDatabase uses PHP's native \Collator for text comparison and sorting.
 * These helpers create properly configured collators for common use cases.
 */
class Collation
{
    /**
     * Create BINARY collator (case-sensitive, byte-order comparison)
     *
     * Equivalent to SQLite BINARY collation.
     * This is the fastest option and treats 'A' and 'a' as different.
     *
     * @return \Collator
     */
    public static function binary(): \Collator
    {
        $collator = new \Collator('root');
        $collator->setStrength(\Collator::IDENTICAL);
        return $collator;
    }

    /**
     * Create NOCASE collator (case-insensitive ASCII)
     *
     * Equivalent to SQLite NOCASE collation.
     * Treats 'Alice' and 'alice' as equal.
     *
     * @return \Collator
     */
    public static function nocase(): \Collator
    {
        $collator = new \Collator('root');
        $collator->setStrength(\Collator::PRIMARY);
        return $collator;
    }

    /**
     * Create locale-specific collator
     *
     * For proper international sorting (Swedish, German, etc.)
     *
     * Examples:
     * - 'sv_SE': Swedish (å, ä, ö at end)
     * - 'de_DE': German (ä between a and b)
     * - 'en_US': English
     *
     * @param string $locale Locale identifier
     * @return \Collator
     */
    public static function locale(string $locale): \Collator
    {
        return new \Collator($locale);
    }

    /**
     * Compare two values using a collator
     *
     * Handles NULL and numeric comparison with SQLite-compatible type ordering.
     *
     * @param \Collator $collator
     * @param mixed $a
     * @param mixed $b
     * @return int <0 if a<b, 0 if equal, >0 if a>b
     */
    public static function compare(\Collator $collator, mixed $a, mixed $b): int
    {
        // NULL handling
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return -1; // NULL < everything
        }
        if ($b === null) {
            return 1;
        }

        // Numeric comparison (both values numeric)
        if (is_numeric($a) && is_numeric($b)) {
            $numA = is_float($a) || str_contains((string)$a, '.') ? (float)$a : (int)$a;
            $numB = is_float($b) || str_contains((string)$b, '.') ? (float)$b : (int)$b;
            return $numA <=> $numB;
        }

        // Type ordering: NULL < numbers < strings
        $typeA = self::getTypeOrder($a);
        $typeB = self::getTypeOrder($b);

        if ($typeA !== $typeB) {
            return $typeA <=> $typeB;
        }

        // String comparison using collator
        $result = $collator->compare((string)$a, (string)$b);

        // Collator::compare can return false on error
        if ($result === false) {
            // Fallback to binary comparison
            return strcmp((string)$a, (string)$b);
        }

        return $result;
    }

    /**
     * Check if two values are equal using a collator
     *
     * @param \Collator $collator
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    public static function equals(\Collator $collator, mixed $a, mixed $b): bool
    {
        return self::compare($collator, $a, $b) === 0;
    }

    /**
     * Get collator identifier for comparison
     *
     * Returns a unique string identifying the collator configuration.
     * Used to check if two collators are compatible.
     *
     * Uses canonicalized locale to handle equivalent locales:
     * - 'no_NO' and 'nb_NO' both canonicalize to 'nb_NO'
     * - 'iw_IL' and 'he_IL' both canonicalize to 'he_IL'
     *
     * @param \Collator $collator
     * @return string
     */
    public static function getIdentifier(\Collator $collator): string
    {
        $locale = $collator->getLocale(\Locale::ACTUAL_LOCALE);
        $canonicalLocale = \Locale::canonicalize($locale);
        $strength = $collator->getStrength();
        return "$canonicalLocale:$strength";
    }

    /**
     * Create collator from name identifier
     *
     * Converts collation names to actual Collator instances:
     * - "BINARY" → binary() (case-sensitive)
     * - "NOCASE" → nocase() (case-insensitive)
     * - Locale codes → locale($name) (e.g., "sv_SE", "de_DE")
     *
     * @param string $name Collation identifier
     * @return \Collator
     */
    public static function fromName(string $name): \Collator
    {
        return match (strtoupper($name)) {
            'BINARY' => self::binary(),
            'NOCASE' => self::nocase(),
            default => self::locale($name),
        };
    }

    /**
     * Get collation name from Collator instance
     *
     * Reverse of fromName() - converts Collator to identifier string.
     * Returns canonicalized locale for consistency.
     *
     * @param \Collator $collator
     * @return string "BINARY", "NOCASE", or canonicalized locale code
     */
    public static function toName(\Collator $collator): string
    {
        $locale = $collator->getLocale(\Locale::ACTUAL_LOCALE);
        $strength = $collator->getStrength();

        // Check if it's BINARY (IDENTICAL strength with root locale)
        if ($locale === 'root' && $strength === \Collator::IDENTICAL) {
            return 'BINARY';
        }

        // Check if it's NOCASE (PRIMARY strength with root locale)
        if ($locale === 'root' && $strength === \Collator::PRIMARY) {
            return 'NOCASE';
        }

        // Otherwise return the canonicalized locale (handles no_NO -> nb_NO, etc.)
        return \Locale::canonicalize($locale);
    }

    private static function getTypeOrder(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }
        if (is_numeric($value)) {
            return 1;
        }
        return 2; // string
    }
}
