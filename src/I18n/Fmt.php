<?php

namespace mini\I18n;

use IntlDateFormatter;

/**
 * Stateless formatting utility that provides shortcuts to common formatting tasks
 *
 * All methods use the current locale from mini\locale() and delegate to PHP's intl classes.
 * This class provides no caching - it's purely a convenience wrapper.
 */
class Fmt
{
    /**
     * Format a number with specified decimal places
     */
    public static function number(float|int $number, int $decimals = 0): string
    {
        $formatter = new \NumberFormatter(\Locale::getDefault(), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);
        return $formatter->format($number) ?: (string)$number;
    }

    /**
     * Format currency with explicit currency code
     *
     * Currency must be explicitly provided - no defaults to prevent pricing errors
     */
    public static function currency(float $amount, string $currencyCode): string
    {
        $formatter = new \NumberFormatter(\Locale::getDefault(), \NumberFormatter::CURRENCY);
        return $formatter->formatCurrency($amount, $currencyCode) ?: $amount . ' ' . $currencyCode;
    }

    /**
     * Format percentage (0.75 -> "75%")
     */
    public static function percent(float $ratio, int $decimals = 0): string
    {
        $formatter = new \NumberFormatter(\Locale::getDefault(), \NumberFormatter::PERCENT);
        $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);
        return $formatter->format($ratio) ?: (($ratio * 100) . '%');
    }

    /**
     * Format file size in human-readable format
     */
    public static function fileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen((string)$bytes) - 1) / 3);
        $factor = min($factor, count($units) - 1);

        $value = $bytes / pow(1024, $factor);
        $decimals = $factor > 0 ? 1 : 0;

        return self::number($value, $decimals) . ' ' . $units[$factor];
    }

    /**
     * Format date in short format (e.g., "12/31/2023", "31.12.2023")
     */
    public static function dateShort(\DateTimeInterface $date): string
    {
        $result = \IntlDateFormatter::formatObject($date, [\IntlDateFormatter::SHORT, \IntlDateFormatter::NONE], \Locale::getDefault());
        return $result ?: $date->format('Y-m-d');
    }

    /**
     * Format date in long format (e.g., "December 31, 2023", "31. desember 2023")
     */
    public static function dateLong(\DateTimeInterface $date): string
    {
        $result = \IntlDateFormatter::formatObject($date, [\IntlDateFormatter::LONG, \IntlDateFormatter::NONE], \Locale::getDefault());
        return $result ?: $date->format('F j, Y');
    }

    /**
     * Format time in short format (e.g., "2:30 PM", "14:30")
     */
    public static function timeShort(\DateTimeInterface $time): string
    {
        $result = \IntlDateFormatter::formatObject($time, [\IntlDateFormatter::NONE, \IntlDateFormatter::SHORT], \Locale::getDefault());
        return $result ?: $time->format('H:i');
    }

    /**
     * Format datetime in short format
     */
    public static function dateTimeShort(\DateTimeInterface $dateTime): string
    {
        $result = \IntlDateFormatter::formatObject($dateTime, [\IntlDateFormatter::SHORT, \IntlDateFormatter::SHORT], \Locale::getDefault());
        return $result ?: $dateTime->format('Y-m-d H:i');
    }

    /**
     * Format datetime in long format
     */
    public static function dateTimeLong(\DateTimeInterface $dateTime): string
    {
        $result = \IntlDateFormatter::formatObject($dateTime, [\IntlDateFormatter::LONG, \IntlDateFormatter::SHORT], \Locale::getDefault());
        return $result ?: $dateTime->format('F j, Y H:i');
    }
}