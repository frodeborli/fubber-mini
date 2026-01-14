<?php

namespace mini;

use mini\Authorizer\Ability;
use mini\Authorizer\Authorization;

Mini::$mini->addService(Authorization::class, Lifetime::Singleton, fn() => new Authorization());

/**
 * Check authorization
 *
 * Checks if the current user can perform an ability on an entity.
 * Returns false if no handler allows the action.
 *
 * ## Usage
 *
 * ```php
 * // Collection-level
 * can(Ability::List, User::class);
 * can(Ability::Create, Post::class);
 *
 * // Instance-level
 * can(Ability::Read, $user);
 * can(Ability::Update, $post);
 * can(Ability::Delete, $comment);
 *
 * // Field-level
 * can(Ability::Update, $user, 'role');
 * can(Ability::Read, $employee, 'salary');
 * ```
 *
 * @param Ability|string $ability The ability to check
 * @param object|string $entity Entity instance or class name
 * @param string|null $field Optional field name for field-level checks
 * @return bool True if allowed, false if denied
 * @throws \InvalidArgumentException If string ability is not registered
 */
function can(Ability|string $ability, object|string $entity, ?string $field = null): bool
{
    return Mini::$mini->get(Authorization::class)->can($ability, $entity, $field);
}
