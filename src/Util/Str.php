<?php

declare(strict_types=1);

namespace mini\Util;

/**
 * String utility methods
 */
final class Str
{
    /**
     * Convert a string to a URL-friendly slug
     *
     * Transliterates Unicode to ASCII (e.g. "Børli" → "borli"),
     * lowercases, replaces non-alphanumeric characters with dashes,
     * and trims.
     */
    public static function slugify(string $text): string
    {
        // Transliterate to ASCII
        $transliterator = \Transliterator::create('NFKD; Any-Latin; Latin-ASCII;');
        $text = $transliterator->transliterate($text);

        // Lowercase (multibyte-safe)
        $text = mb_strtolower($text, 'UTF-8');

        // Replace non-alphanumeric characters with dashes
        $text = preg_replace('/[^a-z0-9]+/u', '-', $text);

        // Collapse multiple dashes
        $text = preg_replace('/-+/', '-', $text);

        return trim($text, '-');
    }
}
