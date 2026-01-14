<?php

namespace mini;

use mini\Validator\Validator;
use mini\Validator\ValidatorStore;
use mini\Validator\AttributeValidatorFactory;
use mini\Validator\Purpose;

// Register Validator services
Mini::$mini->addService(Validator::class, Lifetime::Transient, fn() => new Validator());
Mini::$mini->addService(ValidatorStore::class, Lifetime::Singleton, fn() => new ValidatorStore());
Mini::$mini->addService(AttributeValidatorFactory::class, Lifetime::Singleton, fn() => new AttributeValidatorFactory());

/**
 * Get or create a Validator instance
 *
 * With no arguments: Returns a new Validator for building validation rules.
 * With class name: Returns the core validator (auto-built from class attributes).
 * With class name + purpose: Returns the purpose-specific validator.
 *
 * ## Examples
 *
 * ```php
 * // New validator for building rules
 * $v = validator()->type('string')->minLength(5);
 *
 * // Core validator (auto-built from class attributes)
 * $v = validator(User::class);
 *
 * // Purpose-specific validators
 * $v = validator(User::class, Purpose::Create);
 * $v = validator(User::class, Purpose::Update);
 *
 * // Custom purpose (string)
 * $v = validator(User::class, 'password-reset');
 * ```
 *
 * ## Validation Flow
 *
 * Purpose validation is done in the application layer:
 * ```php
 * // In controller/service
 * if ($error = validator(User::class, Purpose::Create)->isInvalid($user)) {
 *     throw new ValidationException($error);
 * }
 * ```
 *
 * Core validation is done in the repository layer:
 * ```php
 * // In repository
 * if ($error = validator(User::class)->isInvalid($user)) {
 *     throw new ValidationException($error);
 * }
 * ```
 *
 * @param class-string|string|null $classOrName Class name or custom identifier
 * @param Purpose|string|null $purpose Optional purpose scope (Create, Update, or custom string)
 * @return Validator Validator instance
 * @throws \InvalidArgumentException If identifier not found and not a valid class
 */
function validator(?string $classOrName = null, Purpose|string|null $purpose = null): Validator {
    // No argument: return new validator
    if ($classOrName === null) {
        return Mini::$mini->get(Validator::class);
    }

    $store = Mini::$mini->get(ValidatorStore::class);

    // Get from store (auto-builds from attributes if class/interface and no purpose)
    $validator = $store->get($classOrName, $purpose);

    if ($validator === null) {
        // Standard purposes (Purpose enum) are opt-in: return empty validator
        if ($purpose instanceof Purpose) {
            return Mini::$mini->get(Validator::class);
        }

        // Custom string purposes and core validators: throw if not found
        $msg = $purpose !== null
            ? "Validator '$classOrName' with purpose '$purpose' not found. Register it in ValidatorStore."
            : "Validator '$classOrName' not found. Register it in ValidatorStore or ensure the class exists.";
        throw new \InvalidArgumentException($msg);
    }

    // Return clone to allow modifications without affecting cached version
    return clone $validator;
}
