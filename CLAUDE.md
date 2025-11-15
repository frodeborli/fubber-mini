# Mini Framework - Claude Code Quick Reference

## Development Status

**Active internal development** - prioritize clean code over compatibility:
- Breaking changes documented in **CHANGE-LOG.md**
- No semantic versioning - continuous iteration
- Old implementations removed when better approaches found

## Core Philosophy

**Use PHP's engine, not userland abstractions:**
- Native superglobals: `$_GET`, `$_POST`, `$_COOKIE`, `$_SESSION`
- Native output: `echo`, `header()`, `http_response_code()`
- Native locale: `\Locale::setDefault()`, `date_default_timezone_set()`
- Zero required dependencies (PSR interfaces only)
- File-based routing (OS-cached, instant lookup)
- Lazy loading (features load when touched)

## Documentation

**Read actual documentation instead of this file:**
- **README.md** - Getting started, philosophy, performance
- **REFERENCE.md** - Complete API reference
- **src/\*/README.md** - Feature-specific docs (Database, Auth, I18n, etc.)
- **Source docblocks** - Implementation details

## Code Examples

### Entry Point
```php
// html/index.php
<?php require_once __DIR__ . '/../vendor/autoload.php'; mini\router();
```

### Routing
```php
// _routes/users.php → handles /users
<?php echo json_encode(db()->query("SELECT * FROM users")->fetchAll());

// _routes/blog/__DEFAULT__.php → pattern routing
return ['/{slug}' => fn($slug) => "post.php?slug=$slug"];

// _routes/users/_.php → wildcard /users/123
<?php $id = $_GET[0]; // captures "123"
```

### Database (src/Database/README.md)
```php
$users = db()->query("SELECT * FROM users WHERE id = ?", [1])->fetchAll();
db()->insert('users', ['name' => 'John', 'email' => 'john@example.com']);
db()->update('users', ['active' => 0], 'id = ?', [123]);
db()->delete('users', 'id = ?', [123]);
```

### I18n (src/I18n/README.md)
```php
\Locale::setDefault('de_DE');  // Engine-level locale
echo t("Hello {name}", ['name' => 'World']);
echo t("{n, plural, one{# item} other{# items}}", ['n' => 3]);
echo fmt()->currency(19.99, 'EUR');  // "19,99 €"
```

### Auth (src/Auth/README.md)
```php
session();
$_SESSION['user_id'] = auth()->login($email, $password);
if (!auth()->check()) { http_response_code(401); exit; }
```

### Templates (src/Template/README.md)
```php
echo render('user/profile', ['user' => $user]);
```

## Key Concepts

**Singleton:** `Mini::$mini->root`, `->docRoot`, `->baseUrl`, `->debug`, `->locale`, `->timezone`

**Lifecycle:** Bootstrap via `vendor/fubber/mini/bootstrap.php` → Phase system (Initializing → Bootstrap → Ready → Shutdown)

**Helpers:** `db()`, `cache()`, `t()`, `fmt()`, `render()`, `session()`, `auth()`, `mail()`

**Convention:** `_routes/` (handlers), `_config/` (config), `_errors/` (error pages), `_translations/` (i18n), `_views/` (templates)

## Common Patterns

**Simple middleware:** Just call functions at route start
```php
// _routes/admin/users.php
<?php requireAuth(); // throws or exits if unauthorized
```

**Sub-applications:** Mount PSR-15 apps without dependency conflicts
```php
// _routes/marketing/__DEFAULT__.php
return new SlimApp();  // Can use different dependency versions
```
