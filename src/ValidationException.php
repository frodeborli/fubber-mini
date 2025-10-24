<?php

namespace mini;

/**
 * Exception thrown when model validation fails
 */
class ValidationException extends \Exception
{
    /**
     * @param array<string, Translatable> $errors Validation errors
     */
    public function __construct(public readonly array $errors)
    {
        $errorCount = count($errors);
        parent::__construct("Validation failed with {$errorCount} error(s)");
    }

    /**
     * Get validation errors
     *
     * @return array<string, Translatable>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}