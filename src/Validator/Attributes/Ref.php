<?php

declare(strict_types=1);

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Reference validation rules from another class property
 *
 * Query: `validator(Form::class)->$prop` resolves Ref automatically — never use ReflectionClass directly.
 *
 * Usage:
 *   #[Ref(User::class, 'email')]
 *   public string $email = '';
 *
 * This applies the validation rules from validator(User::class)->email
 * to this property, avoiding duplication of validation attributes.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Ref
{
    public function __construct(
        public string $class,
        public string $property,
    ) {}
}
