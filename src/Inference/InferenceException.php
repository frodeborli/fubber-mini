<?php

namespace mini\Inference;

/**
 * Exception thrown when inference fails
 *
 * This may occur when:
 * - The LLM service is unavailable
 * - The response doesn't match the expected schema
 * - The request times out
 * - Rate limits are exceeded
 */
class InferenceException extends \RuntimeException
{
    public function __construct(string|\Stringable $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct((string) $message, $code, $previous);
    }
}
