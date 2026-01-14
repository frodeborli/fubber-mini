<?php

namespace mini\Authorizer;

/**
 * Standard authorization abilities for entity operations
 *
 * Framework-provided abilities have default behavior (deny if unhandled).
 * Custom string abilities must be registered explicitly via Authorization::registerAbility().
 *
 * Usage:
 * ```php
 * can(Ability::List, User::class);
 * can(Ability::Delete, $user);
 * can(Ability::Update, $user, 'role');
 * ```
 */
enum Ability: string
{
    case List = 'list';
    case Create = 'create';
    case Read = 'read';
    case Update = 'update';
    case Delete = 'delete';
}
