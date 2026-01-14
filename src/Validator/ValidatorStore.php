<?php

namespace mini\Validator;

use mini\Util\InstanceStore;
use mini\Mini;

/**
 * Registry for validator instances with auto-building from attributes
 *
 * Stores validators by class name or custom identifiers, optionally scoped by purpose.
 * Automatically builds validators from class attributes when accessing unknown classes.
 *
 * ## Basic Usage
 *
 * ```php
 * $store = Mini::$mini->get(ValidatorStore::class);
 *
 * // Register core validator (no purpose)
 * $store->set(User::class, $coreValidator);
 *
 * // Register purpose-specific validators
 * $store->set(User::class, $createValidator, Purpose::Create);
 * $store->set(User::class, $updateValidator, Purpose::Update);
 *
 * // Custom purpose (string)
 * $store->set(User::class, $passwordResetValidator, 'password-reset');
 * ```
 *
 * ## Retrieval
 *
 * ```php
 * $core = $store->get(User::class);
 * $create = $store->get(User::class, Purpose::Create);
 * $custom = $store->get(User::class, 'password-reset');
 * ```
 *
 * @extends InstanceStore<Validator>
 */
class ValidatorStore extends InstanceStore
{
    public function __construct()
    {
        parent::__construct(Validator::class);
    }

    /**
     * Build cache key from class/identifier and optional purpose
     */
    private function buildCacheKey(string $key, Purpose|string|null $purpose): string
    {
        if ($purpose === null) {
            return $key;
        }

        $purposeString = $purpose instanceof Purpose ? $purpose->value : $purpose;
        return $key . ':' . $purposeString;
    }

    /**
     * Set a validator, optionally scoped by purpose
     *
     * @param string $key Class name or custom identifier
     * @param Validator $value Validator instance
     * @param Purpose|string|null $purpose Optional purpose scope
     */
    public function set(mixed $key, mixed $value, Purpose|string|null $purpose = null): void
    {
        $cacheKey = $this->buildCacheKey($key, $purpose);
        parent::set($cacheKey, $value);
    }

    /**
     * Check if a validator exists, optionally scoped by purpose
     *
     * @param string $key Class name or custom identifier
     * @param Purpose|string|null $purpose Optional purpose scope
     */
    public function has(mixed $key, Purpose|string|null $purpose = null): bool
    {
        $cacheKey = $this->buildCacheKey($key, $purpose);
        return parent::has($cacheKey);
    }

    /**
     * Get validator by key, auto-building from class attributes if needed
     *
     * @param string $key Class name or custom identifier
     * @param Purpose|string|null $purpose Optional purpose scope
     * @return Validator|null Validator instance, or null if not found and not a class
     */
    public function get(mixed $key, Purpose|string|null $purpose = null): mixed
    {
        $cacheKey = $this->buildCacheKey($key, $purpose);

        // Return cached if exists
        if (parent::has($cacheKey)) {
            return parent::get($cacheKey);
        }

        // Auto-build from class attributes if class exists
        if (class_exists($key) || interface_exists($key)) {
            $factory = Mini::$mini->get(AttributeValidatorFactory::class);
            $validator = $factory->forClass($key, $purpose);

            // Cache it
            parent::set($cacheKey, $validator);

            return $validator;
        }

        // Not found and not a class
        return null;
    }

    /**
     * Magic getter - auto-builds from attributes if needed (core validator only)
     *
     * @param string $key
     * @return Validator
     * @throws \RuntimeException If key not found and not a valid class
     */
    public function __get(mixed $key): mixed
    {
        $validator = $this->get($key);

        if ($validator === null) {
            throw new \RuntimeException("Validator '$key' not found. Register it in ValidatorStore or ensure the class exists.");
        }

        return $validator;
    }
}
