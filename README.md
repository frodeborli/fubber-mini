# Mini - PHP Micro-Framework

Minimalist PHP framework for building web applications without abstractions. Use native PHP (`$_GET`, `$_POST`, `$_SESSION`, `header()`) with optional convenience helpers.

## Installation

```bash
composer require fubber/mini
```

## Quick Start

```php
// html/index.php
<?php
require '../vendor/autoload.php';
mini\router();
```

```php
// _routes/index.php
<?php
echo "<h1>Hello, World!</h1>";
```

Visit `http://localhost:8080` and you're running!

## Core Features

### 🚀 **Routing** - `mini\router()`
File-based routing with zero configuration.

```php
// _routes/users.php handles /users
// _routes/api/posts.php handles /api/posts
```

[Documentation](docs/Routing/index.md) | [API Reference](src/Router/Router.php)

---

### 🗄️ **Database** - `mini\db()`
Direct SQL with parameter binding.

```php
$users = db()->query("SELECT * FROM users WHERE active = ?", [1])->fetchAll();
db()->insert('users', ['name' => 'John', 'email' => 'john@example.com']);
```

[Documentation](docs/Database/index.md) | [API Reference](src/Database/Database.php)

---

### 📦 **ORM** - `mini\table()`
Active Record pattern with PHP attributes.

```php
#[Entity(table: 'users')]
class User {
    #[Key] #[Generated]
    public ?int $id = null;

    #[VarcharColumn(100)]
    public string $username;
}

$user = table(User::class)->find($id);
```

[Documentation](docs/Tables/index.md) | [API Reference](src/Tables/Table.php)

---

### 🌐 **I18n** - `mini\t()` & `mini\fmt()`
Translation and locale-specific formatting.

```php
echo t("Hello, {name}!", ['name' => 'World']);
echo t("{n, plural, =0{no items} one{# item} other{# items}}", ['n' => 5]);
echo Fmt::currency(19.99, 'USD');  // "$19.99"
echo Fmt::dateShort(new DateTime());
```

[Quick Reference](src/I18n/README.md) | [Complete Guide](docs/i18n-guide.md) | [API Reference](src/I18n/Fmt.php)

---

### 🔐 **Auth** - `mini\auth()`
Simple authentication with role-based access.

```php
mini\require_login();  // Redirect if not authenticated
mini\require_role('admin');  // Check specific role

if (auth()->login($username, $password)) {
    redirect('/dashboard');
}
```

[Documentation](docs/Auth/index.md) | [API Reference](src/Auth/AuthService.php)

---

### 💾 **Cache** - `mini\cache()`
PSR-16 Simple Cache interface.

```php
cache()->set('user:123', $userData, ttl: 3600);
$user = cache()->get('user:123', default: null);
```

[Documentation](docs/Cache/index.md) | [API Reference](src/Cache/SimpleCache.php)

---

### 📝 **Logger** - `mini\log()`
PSR-3 logging interface.

```php
log()->info('User logged in', ['user_id' => 123]);
log()->error('Payment failed', ['order_id' => 456]);
```

[Documentation](docs/Logger/index.md) | [API Reference](src/Logger/Logger.php)

---

### 📧 **Mailer** - `mini\mailer()`
Send emails via Symfony Mailer.

```php
mailer()->send(
    Email::create()
        ->to('user@example.com')
        ->subject('Welcome!')
        ->html('<h1>Hello!</h1>')
);
```

[Documentation](docs/Mailer/index.md) | [API Reference](src/Mailer/Mailer.php)

---

### 🖥️ **CLI** - `mini\args()`
Parse command-line arguments for CLI tools.

```php
$root = args();
$cmd = $root->nextCommand();
$cmd = $cmd->withSupportedArgs('v', ['verbose', 'help'], 1);
```

[Documentation](docs/CLI/index.md) | [API Reference](src/CLI/ArgManager.php)

---

### 🌍 **HTTP** - Request/Response Helpers
Native PHP with convenience functions.

```php
redirect('/login');
$url = current_url();
flash_set('success', 'Saved!');
echo h($userInput);  // XSS protection
```

[Documentation](docs/Http/index.md)

---

## Philosophy

**Use native PHP.** Mini doesn't hide PHP behind abstractions:
- ✅ `$_GET`, `$_POST`, `$_SESSION`, `$_COOKIE`
- ✅ `header()`, `echo`, `http_response_code()`
- ✅ `\Locale::setDefault()`, `date_default_timezone_set()`

**Helpers are optional.** Use them when they help, skip them when raw PHP is clearer:
- `db()->query()` or raw `PDO`
- `render()` or `include`
- `t()` or `MessageFormatter`

**Configuration over code.** Customize via config files, not inheritance:
- `_config/mini/CLI/ArgManager.php` - Configure CLI parsing
- `_config/Psr/Log/LoggerInterface.php` - Override logger
- `.env` - Environment variables

## Directory Structure

```
project/
├── _routes/           # Route handlers (not web-accessible)
├── _views/            # Templates
├── _config/           # Configuration files
├── _translations/     # Translation files
├── html/              # Document root
│   └── index.php      # Entry point
└── vendor/            # Dependencies
```

## Development Server

```bash
vendor/bin/mini serve
vendor/bin/mini serve --host 0.0.0.0 --port 3000
```

## Documentation

Browse documentation via CLI:

```bash
vendor/bin/mini docs mini          # Namespace overview
vendor/bin/mini docs "mini\Mini"   # Class documentation
vendor/bin/mini docs search Router # Search
```

## License

MIT License - see [LICENSE](LICENSE) file.

## Links

- [GitHub](https://github.com/frodeborli/fubber-mini)
- [Documentation](https://frode.ennerd.com/mini/)
- [Issues](https://github.com/frodeborli/fubber-mini/issues)
