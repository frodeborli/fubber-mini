<?php

namespace mini;

use mini\Validator\Validator;

// Register Validator service
Mini::$mini->addService(Validator::class, Lifetime::Transient, fn() => new Validator());

/**
 * Create a new Validator instance
 *
 * Returns a new Validator for building JSON Schema-compliant validation rules
 * with support for custom error messages and context-aware validation.
 *
 * @return Validator New validator instance
 */
function validator(): Validator {
    return Mini::$mini->get(Validator::class);
}
