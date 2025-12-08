<?php

namespace mini\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * Logger decorator that prefixes messages with a scope identifier
 *
 * Useful for adding component/module context to log messages without
 * coupling code to a specific logger implementation.
 *
 * Usage:
 *   $log = new ScopedLogger('http', \mini\log());
 *   $log->info("Request received");
 *   // Output: [http] Request received
 */
final class ScopedLogger implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(
        private string $scope,
        private LoggerInterface $inner,
    ) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $scopedMessage = "[{$this->scope}] {$message}";
        $context['scope'] ??= $this->scope;

        $this->inner->log($level, $scopedMessage, $context);
    }
}
