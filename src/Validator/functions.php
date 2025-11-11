<?php

namespace mini;

use mini\Validator\Validator;
use mini\Validator\ValidatorStore;
use mini\Validator\AttributeValidatorFactory;

// Register Validator services
Mini::$mini->addService(Validator::class, Lifetime::Transient, fn() => new Validator());
Mini::$mini->addService(ValidatorStore::class, Lifetime::Singleton, fn() => new ValidatorStore());
Mini::$mini->addService(AttributeValidatorFactory::class, Lifetime::Singleton, fn() => new AttributeValidatorFactory());

/**
 * Get or create a Validator instance
 *
 * With no arguments: Returns a new Validator for building validation rules.
 * With class name: Returns a validator built from class attributes (auto-cached by ValidatorStore).
 * With custom name: Returns a cached validator by identifier.
 *
 * Examples:
 * ```php
 * // New validator
 * $v = validator()->type('string')->minLength(5);
 *
 * // From class attributes (auto-built and cached)
 * $v = validator(User::class);
 *
 * // From custom identifier
 * Mini::$mini->get(ValidatorStore::class)['email'] = validator()->type('string')->format('email');
 * $v = validator('email');
 * ```
 *
 * @param class-string|string|null $classOrName Class name or custom identifier
 * @return Validator Validator instance
 * @throws \InvalidArgumentException If identifier not found and not a valid class
 */
function validator(?string $classOrName = null): Validator {
    // No argument: return new validator
    if ($classOrName === null) {
        return Mini::$mini->get(Validator::class);
    }

    $store = Mini::$mini->get(ValidatorStore::class);

    // Get from store (auto-builds from attributes if class/interface)
    $validator = $store->get($classOrName);

    if ($validator === null) {
        throw new \InvalidArgumentException(
            "Validator '$classOrName' not found. Register it in ValidatorStore or ensure the class exists."
        );
    }

    // Return clone to allow modifications without affecting cached version
    return clone $validator;
}
