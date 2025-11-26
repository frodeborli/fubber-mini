<?php

namespace mini\Database\Attributes;

/**
 * Specifies foreign key relationship
 *
 * Inspired by Entity Framework Core's [ForeignKey] attribute.
 *
 * Can be applied to either the foreign key property or the navigation property.
 *
 * Example on foreign key property:
 * ```php
 * #[ForeignKey(navigation: 'user')]
 * public int $user_id;
 *
 * public User $user;
 * ```
 *
 * Example on navigation property:
 * ```php
 * public int $user_id;
 *
 * #[ForeignKey(property: 'user_id', references: 'users.id')]
 * public User $user;
 * ```
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class ForeignKey
{
    /**
     * @param string|null $property The property name that holds the foreign key value
     * @param string|null $navigation The navigation property name
     * @param string|null $references Referenced table.column (e.g., 'users.id')
     * @param string $onDelete Action on delete: CASCADE, SET NULL, RESTRICT, NO ACTION
     * @param string $onUpdate Action on update: CASCADE, SET NULL, RESTRICT, NO ACTION
     */
    public function __construct(
        public ?string $property = null,
        public ?string $navigation = null,
        public ?string $references = null,
        public string $onDelete = 'RESTRICT',
        public string $onUpdate = 'RESTRICT',
    ) {
    }
}
