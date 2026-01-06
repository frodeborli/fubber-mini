<?php

namespace mini\I18n;

use IntlDateFormatter;

/**
 * Stateless formatting utility that queries the current request locale
 *
 * All methods query the current locale via \Locale::getDefault() and delegate to PHP's intl classes.
 * This class holds no state - it's purely a convenience wrapper that reads request state on each call.
 *
 * Date/time methods accept DateTimeInterface or SQL datetime strings (assumed UTC).
 * All output is converted to the application timezone (date_default_timezone_get()).
 */
class Fmt
{
    /**
     * Normalize date input to DateTimeImmutable in local timezone.
     * Strings are assumed to be UTC (e.g., from database).
     */
    private static function ensureDateTime(\DateTimeInterface|string $datetime): \DateTimeImmutable
    {
        if (is_string($datetime)) {
            $datetime = new \DateTimeImmutable($datetime, new \DateTimeZone('UTC'));
        } elseif ($datetime instanceof \DateTime) {
            $datetime = \DateTimeImmutable::createFromMutable($datetime);
        }

        return $datetime->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
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
    public static function dateShort(\DateTimeInterface|string $date): string
    {
        $date = self::ensureDateTime($date);
        $result = \IntlDateFormatter::formatObject($date, [\IntlDateFormatter::SHORT, \IntlDateFormatter::NONE], \Locale::getDefault());
        return $result ?: $date->format('Y-m-d');
    }

    /**
     * Format date in long format (e.g., "December 31, 2023", "31. desember 2023")
     */
    public static function dateLong(\DateTimeInterface|string $date): string
    {
        $date = self::ensureDateTime($date);
        $result = \IntlDateFormatter::formatObject($date, [\IntlDateFormatter::LONG, \IntlDateFormatter::NONE], \Locale::getDefault());
        return $result ?: $date->format('F j, Y');
    }

    /**
     * Format time in short format (e.g., "2:30 PM", "14:30")
     */
    public static function timeShort(\DateTimeInterface|string $time): string
    {
        $time = self::ensureDateTime($time);
        $result = \IntlDateFormatter::formatObject($time, [\IntlDateFormatter::NONE, \IntlDateFormatter::SHORT], \Locale::getDefault());
        return $result ?: $time->format('H:i');
    }

    /**
     * Format datetime in short format
     */
    public static function dateTimeShort(\DateTimeInterface|string $dateTime): string
    {
        $dateTime = self::ensureDateTime($dateTime);
        $result = \IntlDateFormatter::formatObject($dateTime, [\IntlDateFormatter::SHORT, \IntlDateFormatter::SHORT], \Locale::getDefault());
        return $result ?: $dateTime->format('Y-m-d H:i');
    }

    /**
     * Format datetime in long format
     */
    public static function dateTimeLong(\DateTimeInterface|string $dateTime): string
    {
        $dateTime = self::ensureDateTime($dateTime);
        $result = \IntlDateFormatter::formatObject($dateTime, [\IntlDateFormatter::LONG, \IntlDateFormatter::SHORT], \Locale::getDefault());
        return $result ?: $dateTime->format('F j, Y H:i');
    }
}