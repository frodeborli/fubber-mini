# Mini Framework Reference

## Core Functions

### Translation
```php
t(string $text, array $vars = []): Translatable
```
Create translatable text: `t("Hello {name}!", ['name' => 'World'])`

### HTML Escaping
```php
h(string $str): string
```
Escape HTML: `<title><?= h($userTitle) ?></title>`

### URL Generation
```php
url(string $path = ''): string
```
Generate URLs: `<a href="<?= url('login.php') ?>">Login</a>`

### Template Rendering
```php
render(string $template, array $vars = []): string
```
Render template: `echo render('tpl/page.php', ['title' => t('Home'), 'data' => $data])`

### Session Management
```php
session(): bool
```
Start session safely: `session(); $_SESSION['user_id'] = $id;`

## Routing System

### Implicit Routes
Create PHP files that map to URLs automatically:
- `/users/index.php` → `/users/` (preferred pattern - nginx adds trailing slash)
- `/users.php` → `/users`
- `/api/user.php` → `/api/user`

### Explicit Routes
```php
// config/routes.php
return [
    "/users/{id}" => fn($id) => "/user_detail.php?id=$id",
    "/api/users/{id}/posts/{slug}" => fn($slug, $id) => "/api_posts.php?user_id=$id&slug=$slug",
    "/blog/{year}/{month}" => fn($month, $year) => "/blog_archive.php?year=$year&month=$month"
];
```
Router only handles URL rewriting, not HTTP verbs (GET/POST/etc).

### HTTP Exception Handling
```php
// Only works when using router
throw new \mini\Http\NotFoundException("Page not found");           // 404
throw new \mini\Http\AccessDeniedException("Access denied");        // 403
throw new \mini\Http\BadRequestException("Invalid request");        // 400
throw new \mini\HttpException(500, "Internal error");               // Custom codes
```
Exceptions automatically render appropriate error pages.

### HTTP Components
- **ErrorHandler**: Handles HTTP exceptions and renders error pages
- **OutputBuffer**: Manages output buffering for clean error handling
- **SimpleRouter**: File-based routing with pattern matching

## Database & Repositories

Mini provides a powerful, layered approach to data access. You can work directly with the database for simple queries or use the high-level Repository system and query builder for more structured, object-oriented access.

### High-Level: The Repository System

#### Table Query Builder
Immutable query builder for repositories:
```php
table('users')->eq('status', 'active')->order('name')->limit(10)->all()
table('posts')->gte('created_at', $date)->first()
table('products')->lte('price', 100)->count()
```

#### Table Methods
```php
$table->eq(string $field, mixed $value): Table               # Equality condition
$table->gte(string $field, mixed $value): Table             # Greater than or equal
$table->lte(string $field, mixed $value): Table             # Less than or equal
$table->gt(string $field, mixed $value): Table              # Greater than
$table->lt(string $field, mixed $value): Table              # Less than
$table->like(string $field, string $pattern): Table         # Pattern matching
$table->in(string $field, array $values): Table             # Value in array
$table->query(array|string $params): Table                  # Query with parameters
$table->orderBy(string $field, string $direction = 'asc'): Table  # Order results
$table->limit(int $limit): Table                            # Limit results
$table->offset(int $offset): Table                          # Offset results
$table->all(): array                                        # Get all results
$table->one(): ?object                                      # Get first result (alias)
$table->first(): ?object                                    # Get first result
$table->count(): int                                        # Count results
$table->page(int $page, int $perPage = 20): array          # Paginated results
$table->load(mixed $id): object                             # Load by ID
$table->create(): object                                    # Create new model
```

#### Repository Interface
```php
interface RepositoryInterface {
    public function findById(mixed $id): ?object
    public function save(object $model): object
    public function delete(object $model): bool
    public function validate(object $model): array
    public function create(): object
    public function convertConditionValue(string $field, mixed $value): mixed
}
```

#### Repository Access
```php
repositories(): CollectionInterface
table(string $name): Table
```
Access data: `table('users')->eq('active', true)->first()`, Get repos: `repositories()->get('users')`

#### Repository Types
- **Abstract Database Repository**: Base class for database repositories with built-in validation, field mapping, and ModelTracker integration
- **CSV Repository**: File-based repository for CSV data sources with full Table query support

### Low-Level: Direct Database Access

#### Database Access
```php
db(): DB
```
Get database: `$user = db()->queryOne('SELECT * FROM users WHERE id = ?', [$id])`

#### Database Query Methods
```php
$db->query(string $sql, array $params = []): array           # All results as arrays
$db->queryOne(string $sql, array $params = []): ?array      # First row only
$db->queryField(string $sql, array $params = []): mixed     # First column of first row
$db->queryColumn(string $sql, array $params = []): array    # First column as array
$db->exec(string $sql, array $params = []): bool            # Execute statement
$db->lastInsertId(): ?string                                 # Last inserted ID
$db->tableExists(string $tableName): bool                   # Check table existence
$db->transaction(\Closure $task): mixed                     # Execute in transaction
$db->getPdo(): PDO                                          # Access underlying PDO
```

## Internationalization (i18n)

Mini's i18n system is enterprise-grade, providing a complete solution for translation, locale-aware formatting, and language management.

### Translation System

#### Translatable Class
Immutable translation request object implementing Stringable:
```php
$translatable = t("Hello {name}!", ['name' => 'World']);
echo $translatable;  // Automatically translated via __toString()
$translatable->getSourceText();     # Get original text
$translatable->getVars();           # Get variables
$translatable->getSourceFile();     # Get calling file context
```

#### File Structure
```
translations/
├── default/           # Auto-generated source strings
│   └── controller.php.json
├── nb/               # Norwegian translations (language only)
│   └── controller.php.json
└── es/               # Spanish translations
    └── controller.php.json
```

#### Advanced Translation Features
```php
// Conditional translations using QueryParser syntax
{
  "You have {count} items": {
    "count=0": "You have no items",
    "count=1": "You have one item",
    "count[>]=2": "You have {count} items",
    "count[>=]=100&premium=true": "You have {count} premium items"
  }
}

// ICU MessageFormat support for complex pluralization
{
  "apples": "{count, plural, =0 {no apples} =1 {one apple} other {# apples}}"
}

// Custom interpolation filters
translator()->getInterpolator()->addFilterHandler(function($value, $filter) {
    if ($filter === 'reverse') return strrev($value);
    if ($filter === 'upper') return strtoupper($value);
    return null; // Let other handlers try
});

// Advanced QueryParser conditions
// Supports: =, !=, <, <=, >, >=, [in], [not_in], [like], [not_like]
// Boolean logic: &(AND), |(OR), !(NOT)
// Example: "status=active&(role=admin|role=moderator)&!banned=true"
```

#### Translator Access
```php
translator(): Translator
```
Get translator: `translator()->trySetLanguageCode('nb')`

### Locale-Aware Formatting (Fmt Class)

All methods are static and use `Locale::getDefault()`.

#### Currency
```php
Fmt::currency(float $amount, string $currencyCode): string
```
Display currency: `echo Fmt::currency(19.99, 'NOK')` // kr 19,99

#### Dates
```php
Fmt::dateShort(DateTimeInterface $date): string
Fmt::dateLong(DateTimeInterface $date): string
Fmt::dateTime(DateTimeInterface $date): string
Fmt::time(DateTimeInterface $date): string
```
Format dates: `echo Fmt::dateShort(new DateTime())` // 26.9.2024

#### Numbers
```php
Fmt::number(float|int $number, int $decimals = 0): string
Fmt::percent(float $ratio, int $decimals = 1): string
```
Display numbers: `echo Fmt::percent(0.85, 1)` // 85,0%

#### File Sizes
```php
Fmt::fileSize(int $bytes): string
```
Display file size: `echo Fmt::fileSize(1048576)` // 1,0 MB

### Core Locale Management

Current locale is managed via `Locale::setDefault()` and `Locale::getDefault()`. Region (e.g., `nb_NO`) affects formatting via `Fmt`, while language (e.g., `nb`) affects translations via `t()`.

```php
Locale::setDefault('nb_NO'); // Sets Norwegian (Norway) for formatting
// Translation files use language only: translations/nb/
// Formatting uses full locale: currency, dates, numbers
```

#### Factory Functions
```php
numberFormatter(?string $locale = null, int $style = NumberFormatter::DECIMAL): NumberFormatter
messageFormatter(string $pattern, ?string $locale = null): MessageFormatter
intlDateFormatter(?int $dateType = IntlDateFormatter::MEDIUM, ?int $timeType = IntlDateFormatter::SHORT, ?string $locale = null, ?string $timezone = null, ?string $pattern = null): IntlDateFormatter
collator(): Collator
```

Access PHP intl classes: `$formatter = numberFormatter('nb_NO', NumberFormatter::CURRENCY)`

#### Locale Utilities
```php
parseLocale(?string $locale = null): array           # Parse locale into components
localeLanguage(?string $locale = null): string       # Extract language (nb from nb_NO)
localeRegion(?string $locale = null): ?string        # Extract region (NO from nb_NO)
canonicalizeLocale(string $locale): string           # Canonicalize locale format
```
Parse locales: `parseLocale('nb_NO')` returns `['language' => 'nb', 'region' => 'NO']`

## Caching

### Cache Access
```php
cache(?string $namespace = null): SimpleCacheInterface
```
Get cache: `cache('users')->set('user:123', $userData, 3600)`

### Cache Methods (PSR-16 Compatible)
```php
$cache->get(string $key, mixed $default = null): mixed
$cache->set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
$cache->delete(string $key): bool
$cache->clear(): bool
$cache->has(string $key): bool
$cache->getMultiple(iterable $keys, mixed $default = null): iterable
$cache->setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
$cache->deleteMultiple(iterable $keys): bool
```

### Cache Implementations
- **DatabaseCache**: Database-backed cache with TTL support
- **NamespacedCache**: Wraps any cache with automatic key prefixing

## Security

### CSRF Protection
```php
CSRF::field(string $action): string          # Returns <input type="hidden" name="_token" value="...">
CSRF::check(array $data, string $action): void   # Validates $_POST/_GET data, dies on failure
CSRF::getToken(string $action): string       # Get raw token for AJAX/custom use
CSRF::verifyToken(string $token, string $action): bool  # Manual token verification
```
Protect forms: `echo CSRF::field('edit_user')` in form, `CSRF::check($_POST, 'edit_user')` in handler

### Input Validation
```php
Invalid::required(mixed $value): ?Translatable
Invalid::email(string $email): ?Translatable
Invalid::maxLength(string $value, int $max): ?Translatable
Invalid::minLength(string $value, int $min): ?Translatable
Invalid::numeric(mixed $value): ?Translatable
Invalid::url(string $url): ?Translatable
```
Validate input: `if ($error = Invalid::email($email)) $errors[] = $error;`

## HTTP Utilities

### HTTP Functions
```php
redirect(string $url): void
current_url(): string
flash_set(string $type, string $message): void
flash_get(): array
```
Redirect: `redirect(url('login.php'))`, Flash messages: `flash_set('success', 'Saved!')`

## Utility Classes

### QueryParser
Parse and evaluate query conditions with advanced operators:
```php
$parser = new QueryParser();
$parser->evaluate("status=active&role[in]=admin,moderator", $data)
$parser->evaluate("count>10&(premium=true|vip=true)", $data)
```
Supports: `=`, `!=`, `<`, `<=`, `>`, `>=`, `[in]`, `[not_in]`, `[like]`, `[not_like]`, boolean logic `&`, `|`, `!`

### StringInterpolator
Advanced string interpolation with filter support:
```php
$interpolator = new StringInterpolator();
$interpolator->addFilterHandler(fn($value, $filter) => $filter === 'upper' ? strtoupper($value) : null);
$interpolator->interpolate("Hello {name:upper}!", ['name' => 'world']) // "Hello WORLD!"
```

### ModelTracker
Track changes to model objects for dirty checking:
```php
$tracker = new ModelTracker();
$tracker->track($model);
$tracker->isDirty($model, 'name')
$tracker->getChangedFields($model)
```

### PathsRegistry
Manage searchable paths for configurations and templates:
```php
$registry = new PathsRegistry();
$registry->add('/path/to/configs');
$configFile = $registry->findFirst('database.php');
```

### InstanceStore
Generic singleton storage for framework components:
```php
$store = new InstanceStore();
$store->set('key', $instance);
$instance = $store->get('key');
```

## Configuration Files

All configuration files are in `/config/` and use PHP return statements.

### Required Configurations

#### `config.php`
Main configuration. Must return array with keys:
- `base_url` - Application base URL
- `dbfile` - SQLite database path (if using default PDO factory)
- `app['name']` - Application name
- `i18n['default_language']` - Default language code
- `i18n['supported_languages']` - Array of supported language codes

#### `bootstrap.php` (optional)
Project-specific initialization. Executed after mini framework setup.

#### `routes.php` (optional)
```php
return [
    "/pattern/{param}" => fn($param) => "/target.php?param=$param"
];
```
Define explicit URL routing patterns. Keys are URL patterns with `{param}` placeholders, values are callables that return target URLs.

### Factory Configurations

#### `pdo.php` (optional)
Must return a working PDO instance. Defaults to invoking `$config['pdo_factory']` if defined, finally defaults to SQLite at `/database.sqlite3`.

#### `number-formatter.php` (optional)
```php
return function(string $locale, int $style): NumberFormatter {
    $formatter = new NumberFormatter($locale, $style);
    // Custom configuration
    return $formatter;
};
```

#### `intl-date-formatter.php` (optional)
```php
return function(?int $dateType, ?int $timeType, ?string $locale, ?string $timezone, ?string $pattern): IntlDateFormatter {
    return new IntlDateFormatter($locale, $dateType, $timeType, $timezone, null, $pattern);
};
```

#### `message-formatter.php` (optional)
```php
return function(string $pattern, ?string $locale): MessageFormatter {
    return new MessageFormatter($locale, $pattern);
};
```

#### `collator.php` (optional)
```php
return function(?string $locale): Collator {
    $collator = new Collator($locale ?? Locale::getDefault());
    $collator->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
    return $collator;
};
```

## Framework Bootstrap

### Core Bootstrap
```php
bootstrap(array $options = [], bool $disable_router = false): void
router(): void
getCachedConfig(string $configKey, string $filename): mixed
```
Initialize framework: `bootstrap()` sets up locale, config, routing. Access config: `getCachedConfig('database', 'config.php')`

### Routing Functions
```php
handleCleanUrlRedirects(): void
tryFileBasedRouting(string $path, string $projectRoot, string $baseUrl): ?string
includeTarget(string $target, string $projectRoot): void
handle404(string $projectRoot): void
```
Internal routing functions used by the framework.

### Bootstrap Process

1. Framework loads `mini/functions.php`
2. Framework detects locale from Accept-Language header
3. Framework calls `Locale::setDefault()` with detected locale
4. Framework loads configuration from `config.php`
5. Framework executes `config/bootstrap.php` if it exists
6. Application code runs with properly configured environment

## Migration System

### Running Migrations
```bash
php bin/migrate.php
```

### Migration Files
```php
// migrations/001_create_table.php
return function($db) {
    $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
    echo "Created users table\n";
};
```

Files are executed in filename order. Each file must return a callable that accepts a DB instance.

## Command Line Interface

### Composer Scripts
```bash
composer exec mini migrations                    # Run all pending migrations
composer exec mini translations                 # Validate all translation files
composer exec mini translations add-missing     # Add missing translation strings
composer exec mini translations add-language es # Create new language files
composer exec mini translations remove-orphans  # Remove unused translations
composer exec mini serve                        # Start development server (if implemented)
composer exec mini test                         # Run framework tests (if implemented)
composer exec mini cache:clear                  # Clear application cache (if implemented)
composer exec mini routes                       # Display registered routes (if implemented)
```

## File Naming Conventions

### Controllers
Single PHP files: `login.php`, `project_edit.php`

### Templates
PHP files in `tpl/` directory: `tpl/login.php`, `tpl/layout.php`

### Tests
Test files in `mini/tests/`: `Translator.php`, `Fmt.basic.php`