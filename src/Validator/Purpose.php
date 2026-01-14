<?php

namespace mini\Validator;

/**
 * Standard validation purposes for entity lifecycle operations
 *
 * Used to retrieve purpose-specific validators from ValidatorStore.
 * Custom string purposes are also supported for application-specific needs.
 *
 * Example:
 * ```php
 * // Standard purposes
 * $createValidator = validator(User::class, Purpose::Create);
 * $updateValidator = validator(User::class, Purpose::Update);
 *
 * // Custom purpose (string)
 * $passwordResetValidator = validator(User::class, 'password-reset');
 * ```
 */
enum Purpose: string
{
    case Create = 'create';
    case Update = 'update';
}
