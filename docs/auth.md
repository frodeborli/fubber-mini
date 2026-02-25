# Authentication & Authorization

## Setup

1. Implement `AuthInterface` — register via `_config/mini/Auth/AuthInterface.php`
2. Register an exception converter for `AuthenticationRequiredException` to redirect to login
3. Use `auth()->requireLogin()` at protection points (Model `query()`, controllers, etc.)

## auth() Facade

```php
auth()->isAuthenticated()           // bool
auth()->getUserId()                 // mixed
auth()->getClaim('org_id')          // mixed
auth()->hasRole('admin')            // bool
auth()->hasPermission('edit_users') // bool
auth()->requireLogin()              // throws AuthenticationRequiredException
auth()->requireRole('admin')        // throws AccessDeniedException
auth()->requirePermission('...')    // throws AccessDeniedException
```

## Entity Authorization

Override `providecan*()` on Model subclasses. Return `true`/`false`/`null` (null = no opinion = allowed).

`save()` and `delete()` check these automatically. Check manually with `can(Ability::Update, $entity)`.

## Exceptions

- `AuthenticationRequiredException` (401) — not logged in
- `AccessDeniedException` (403) — logged in but not permitted
