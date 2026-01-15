<?php

namespace mini;

use mini\Validator\ValidationError;

/**
 * Exception thrown when validation fails
 *
 * Wraps a ValidationError and provides access to the error details.
 */
class ValidationException extends \Exception
{
    /**
     * @param ValidationError $error The validation error
     */
    public function __construct(public readonly ValidationError $error)
    {
        parent::__construct((string) $error);
    }

    /**
     * Get the validation error
     */
    public function getError(): ValidationError
    {
        return $this->error;
    }

    /**
     * Get property errors as array (for backwards compatibility)
     *
     * @return array<string, ValidationError>
     */
    public function getPropertyErrors(): array
    {
        return $this->error->getPropertyErrors();
    }
}