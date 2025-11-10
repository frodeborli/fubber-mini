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
 * With class name: Returns a cached validator built from class attributes.
 * With custom name: Returns a cached validator by identifier.
 *
 * Examples:
 * ```php
 * // New validator
 * $v = validator()->type('string')->minLength(5);
 *
 * // From class attributes
 * $v = validator(User::class);
 *
 * // From custom identifier
 * Mini::$mini->get(ValidatorStore::class)['email'] = validator()->type('string')->format('email');
 * $v = validator('email');
 * ```
 *
 * @param class-string|string|null $classOrName Class name or custom identifier
 * @return Validator Validator instance
 */
function validator(?string $classOrName = null): Validator {
    // No argument: return new validator
    if ($classOrName === null) {
        return Mini::$mini->get(Validator::class);
    }

    $store = Mini::$mini->get(ValidatorStore::class);

    // Check if already in registry
    if ($store->has($classOrName)) {
        return clone $store->get($classOrName);
    }

    // If it's a class or interface, build from attributes
    if (class_exists($classOrName) || interface_exists($classOrName)) {
        $factory = Mini::$mini->get(AttributeValidatorFactory::class);
        $validator = $factory->forClass($classOrName);

        // Cache it
        $store->set($classOrName, $validator);

        return clone $validator;
    }

    // Not found and not a class
    throw new \InvalidArgumentException("Validator '$classOrName' not found. Register it in ValidatorStore or ensure the class exists.");
}
