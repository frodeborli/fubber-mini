# Authorizer - Authorization System

## Overview

Check if a user can perform actions on resources:

```php
use mini\Authorizer\Ability;
use function mini\can;

if (can(Ability::Delete, $post)) {
    $post->delete();
}
```

## Setup

```php
use mini\Authorizer\{Authorization, Ability, AuthorizationQuery};
use function mini\can;
```

## Checking Authorization

```php
// Collection-level
can(Ability::List, User::class);
can(Ability::Create, Post::class);

// Instance-level
can(Ability::Read, $post);
can(Ability::Update, $post);
can(Ability::Delete, $post);

// Field-level
can(Ability::Update, $user, 'role');
can(Ability::Read, $employee, 'salary');

// Non-class resources
can(Ability::Read, 'reports.financial');
can(Ability::Update, 'virtualdatabase.countries');
```

## Registering Handlers

```php
$auth = Mini::$mini->get(Authorization::class);

$auth->for(User::class)->listen(function(AuthorizationQuery $q): ?bool {
    return match ($q->ability) {
        Ability::List => auth()->isAuthenticated(),
        Ability::Create => auth()->hasRole('admin'),
        Ability::Read => true,
        Ability::Update, Ability::Delete =>
            $q->instance()?->id === auth()->getUserId() || auth()->hasRole('admin'),
        default => null,
    };
});
```

Handlers return:
- `true` - Allow (stops processing)
- `false` - Deny (stops processing)
- `null` - Pass to next handler

## Handler Resolution

Mini checks the most specific handler first: entity class → marker interfaces → parent class → fallback. You usually only care about "class beats parent class"; interface handlers are for cross-cutting rules.

```php
// Cross-cutting: block access to other tenants' data
$auth->for(TenantScoped::class)->listen(function(AuthorizationQuery $q): ?bool {
    $entity = $q->instance();
    if ($entity && $entity->tenant_id !== auth()->getClaim('tenant_id')) {
        return false;  // Wrong tenant, deny
    }
    return null;  // Correct tenant, pass to next handler
});

// Generic: default rules for all Models
$auth->for(Model::class)->listen(fn($q) => auth()->isAuthenticated());

// Specific: custom rules for Product (checked before Model)
$auth->for(Product::class)->listen(fn($q) => ...);
```

For `class Product extends Model implements TenantScoped`:
```
Product → TenantScoped → Model → fallback
```

## AuthorizationQuery

```php
$auth->for(Post::class)->listen(function(AuthorizationQuery $q): ?bool {
    $q->ability;      // Ability::Update or 'publish'
    $q->entity;       // Post::class or $post instance
    $q->field;        // 'title' or null

    $q->className();  // 'App\Post' (works for both)
    $q->instance();   // $post or null (for class-level checks)

    return null;
});
```

## Field-Level Authorization

```php
$auth->for(User::class)->listen(function(AuthorizationQuery $q): ?bool {
    if ($q->field === 'role') {
        return auth()->hasRole('admin');
    }
    if ($q->field === 'salary') {
        return auth()->hasRole('hr');
    }
    return null;
});

// Check
if (can(Ability::Update, $user, 'role')) {
    echo '<select name="role">...</select>';
}
```

## Custom Abilities

```php
$auth->registerAbility('publish');
$auth->registerAbility('archive');

$auth->for(Post::class)->listen(function(AuthorizationQuery $q): ?bool {
    if ($q->ability === 'publish') {
        return auth()->hasRole('editor');
    }
    return null;
});

if (can('publish', $post)) {
    $post->publish();
}
```

Unregistered custom abilities throw `InvalidArgumentException`.

## Non-Class Resources

```php
$auth->for('reports.financial')->listen(function(AuthorizationQuery $q): ?bool {
    return auth()->hasRole('finance');
});

$auth->for('virtualdatabase.countries')->listen(function(AuthorizationQuery $q): ?bool {
    return match ($q->ability) {
        Ability::List, Ability::Read => true,
        default => auth()->hasRole('admin'),
    };
});

if (can(Ability::Read, 'reports.financial')) {
    echo render('reports/financial');
}
```

## Default Behavior

If no handler responds, authorization is **allowed**. To deny by default:

```php
$auth->fallback->listen(fn($q) => false);
```

## Standard Abilities

| Ability | Description |
|---------|-------------|
| `Ability::List` | View list of resources |
| `Ability::Create` | Create new resource |
| `Ability::Read` | View specific resource |
| `Ability::Update` | Modify resource |
| `Ability::Delete` | Remove resource |

## Examples

### Owner-Based Access

```php
$auth->for(Post::class)->listen(function(AuthorizationQuery $q): ?bool {
    $isOwner = $q->instance()?->author_id === auth()->getUserId();

    return match ($q->ability) {
        Ability::Read => true,
        Ability::Update, Ability::Delete => $isOwner || auth()->hasRole('admin'),
        default => null,
    };
});
```

### Route Protection

```php
// _routes/admin/users.php
if (!can(Ability::List, User::class)) {
    throw new \mini\Exceptions\AccessDeniedException();
}

$users = User::all();
```

### API Authorization

```php
// _routes/api/posts/{id}.php
$post = Post::find($id);

if (!can(Ability::Update, $post)) {
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden']));
}
```

### Combining with Validation

```php
function updateUser(int $id, array $data): void
{
    $user = User::find($id);

    if (!can(Ability::Update, $user)) {
        throw new AccessDeniedException();
    }

    foreach (array_keys($data) as $field) {
        if (!can(Ability::Update, $user, $field)) {
            throw new AccessDeniedException("Cannot modify: $field");
        }
    }

    if ($error = validator(User::class)->isInvalid($data)) {
        throw new ValidationException($error);
    }

    $user->update($data);
}
```
