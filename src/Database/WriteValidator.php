<?php

namespace mini\Database;

use mini\ValidationException;
use mini\Validator\Purpose;
use function mini\validator;

/**
 * Validates data before database writes
 *
 * Centralizes validation logic for insert/update operations.
 * Both PDODatabase and VirtualDatabase use this to ensure consistent validation.
 *
 * Validation flow:
 * 1. Purpose-scoped validation (Create or Update) - validates fields with that purpose
 * 2. Core validation - validates fields without purpose (always runs)
 *
 * Both must pass for the write to proceed.
 */
final class WriteValidator
{
    /**
     * Validate data for INSERT operation
     *
     * @param string $entityClass Entity class with validation attributes
     * @param array $data Data to be inserted (column => value)
     * @throws ValidationException If validation fails
     */
    public static function validateInsert(string $entityClass, array $data): void
    {
        self::validate($entityClass, $data, Purpose::Create);
    }

    /**
     * Validate data for UPDATE operation
     *
     * Merges current row with changes, then validates the complete entity state.
     *
     * @param string $entityClass Entity class with validation attributes
     * @param array $currentRow Current row data from database
     * @param array $changes Changes to apply (column => value)
     * @throws ValidationException If validation fails
     */
    public static function validateUpdate(string $entityClass, array $currentRow, array $changes): void
    {
        // Merge current state with changes
        $merged = array_merge($currentRow, $changes);
        self::validate($entityClass, $merged, Purpose::Update);
    }

    /**
     * Core validation logic
     *
     * @param string $entityClass Entity class with validation attributes
     * @param array $data Data to validate
     * @param Purpose $purpose Purpose::Create or Purpose::Update
     * @throws ValidationException If validation fails
     */
    private static function validate(string $entityClass, array $data, Purpose $purpose): void
    {
        // 1. Purpose-scoped validation (may be empty if no attributes have this purpose)
        $purposeValidator = validator($entityClass, $purpose);
        $error = $purposeValidator->isInvalid($data);
        if ($error !== null) {
            throw new ValidationException($error);
        }

        // 2. Core validation (always runs)
        $coreValidator = validator($entityClass);
        $error = $coreValidator->isInvalid($data);
        if ($error !== null) {
            throw new ValidationException($error);
        }
    }
}
