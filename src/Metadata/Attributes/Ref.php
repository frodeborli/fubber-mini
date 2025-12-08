<?php

namespace mini\Metadata\Attributes;

use Attribute;

/**
 * Reference another class's metadata for this property
 *
 * Use this to override the default type-based metadata resolution,
 * or to specify a reference when the property type doesn't indicate
 * the target class (e.g., mixed, array, interface).
 *
 * @example
 * class User {
 *     // Override: use AdminGroup metadata instead of Group
 *     #[Ref(AdminGroup::class)]
 *     public Group $group;
 *
 *     // Specify reference for untyped property
 *     #[Ref(Address::class)]
 *     public mixed $address;
 * }
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Ref
{
    public function __construct(
        /** @var class-string */
        public string $class
    ) {}
}
