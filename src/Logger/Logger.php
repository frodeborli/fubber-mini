<?php

namespace mini\Logger;

use MessageFormatter;
use mini\Mini;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Built-in logger implementation that logs to PHP's error_log
 *
 * Uses MessageFormatter for string interpolation with the application's default locale.
 * Supports all PSR-3 log levels.
 */
class Logger extends AbstractLogger
{
    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $message = (string) $message;

        // Interpolate message with context using MessageFormatter
        if (!empty($context)) {
            $interpolated = $this->interpolate($message, $context);
        } else {
            $interpolated = $message;
        }

        // Format log entry
        $formattedMessage = $this->formatLogEntry($level, $interpolated, $context);

        // Write to PHP error log
        error_log($formattedMessage);
    }

    /**
     * Interpolate message with context values using MessageFormatter
     *
     * @param string $message
     * @param array $context
     * @return string
     */
    private function interpolate(string $message, array $context): string
    {
        try {
            // Use application's default locale (not per-user locale)
            $locale = Mini::$mini->locale;

            // Try MessageFormatter first
            $formatter = new MessageFormatter($locale, $message);
            $result = $formatter->format($context);

            if ($result !== false) {
                return $result;
            }

            // If MessageFormatter fails, fall back to simple placeholder replacement
            return $this->simplePlaceholderReplacement($message, $context);
        } catch (\Exception $e) {
            // If anything goes wrong, fall back to simple replacement
            return $this->simplePlaceholderReplacement($message, $context);
        }
    }

    /**
     * Simple placeholder replacement for basic {key} patterns
     *
     * @param string $message
     * @param array $context
     * @return string
     */
    private function simplePlaceholderReplacement(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            // Skip 'exception' key which is reserved for exception objects
            if ($key === 'exception' && $value instanceof \Throwable) {
                continue;
            }

            // Convert value to string
            if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
                $replace['{' . $key . '}'] = (string) $value;
            } elseif (is_array($value)) {
                $replace['{' . $key . '}'] = json_encode($value);
            } elseif (is_null($value)) {
                $replace['{' . $key . '}'] = 'null';
            } else {
                $replace['{' . $key . '}'] = '[' . gettype($value) . ']';
            }
        }

        return strtr($message, $replace);
    }

    /**
     * Format a log entry with timestamp and level
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @return string
     */
    private function formatLogEntry(mixed $level, string $message, array $context): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $levelStr = strtoupper((string) $level);

        $formatted = "[{$timestamp}] [{$levelStr}] {$message}";

        // Append exception details if present
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $exception = $context['exception'];
            $formatted .= "\n" . $this->formatException($exception);
        }

        return $formatted;
    }

    /**
     * Format exception for logging
     *
     * @param \Throwable $exception
     * @return string
     */
    private function formatException(\Throwable $exception): string
    {
        return sprintf(
            "Exception: %s\nMessage: %s\nFile: %s:%d\nTrace:\n%s",
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );
    }
}
