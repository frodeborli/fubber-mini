<?php

namespace mini\Validator;

use mini\Util\InstanceStore;
use mini\Mini;

/**
 * Registry for validator instances with auto-building from attributes
 *
 * Stores validators by class name or custom identifiers.
 * Automatically builds validators from class attributes when accessing unknown classes.
 *
 * Accessed via Mini::$mini->get(ValidatorStore::class)
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
     * Get validator by key, auto-building from class attributes if needed
     *
     * @param string $key Class name or custom identifier
     * @return Validator|null Validator instance, or null if not found and not a class
     */
    public function get(mixed $key): mixed
    {
        // Return cached if exists
        if ($this->has($key)) {
            return parent::get($key);
        }

        // If it's a class or interface, build from attributes
        if (class_exists($key) || interface_exists($key)) {
            $factory = Mini::$mini->get(AttributeValidatorFactory::class);
            $validator = $factory->forClass($key);

            // Cache it
            $this->set($key, $validator);

            return $validator;
        }

        // Not found and not a class
        return null;
    }

    /**
     * Magic getter - auto-builds from attributes if needed
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
