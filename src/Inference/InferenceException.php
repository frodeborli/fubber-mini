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
}
