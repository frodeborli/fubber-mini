<?php

namespace mini\Validator;

use mini\Util\InstanceStore;

/**
 * Registry for validator instances
 *
 * Stores validators by class name or custom identifiers.
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
}
