# Mini Framework - Development Guide for Claude Code

## Philosophy

The mini framework follows **old-school PHP principles** with modern conveniences:

- **Simple, readable PHP** - No complex abstractions or heavy frameworks
- **Lazy initialization** - Utilities initialize themselves on-demand, not in bootstrap
- **Minimal magic** - Explicit function calls, clear data flow
- **Separation of concerns** - Framework handles core functionality, applications handle business logic
- **AI-friendly architecture** - Designed specifically for efficient Claude Code development
- **Convention over configuration** - Sensible defaults with minimal setup required

## Code Style & Standards

### **General Rules**
- **No comments** unless explicitly requested or documenting public APIs
- **Descriptive variable names** over comments
- **Single responsibility** - Each class/method does one thing well
- **Null-safe operations** - Always handle null/missing values gracefully

### **Error Handling**
- **Graceful degradation** - Return sensible defaults rather than crashing
- **Clear error messages** - `[missing variable 'name']`, `[unknown filter 'invalid']`
- **Silent fallbacks** - Log errors internally but don't break user experience

## File Organization

### **Core Structure**
```
mini/
├── src/                    # Core framework classes
│   ├── Util/              # Utility classes (QueryParser, StringInterpolator)
│   ├── Cache/             # Cache implementations
│   ├── Translator.php     # Translation system
│   ├── Fmt.php           # Localized formatting
│   └── DB.php            # Database abstraction
├── tests/                 # Test files
├── functions.php          # Global helper functions
└── CLAUDE.md             # This file
```

### **Test File Naming**
- `{ClassName}.php` - Tests entire class (e.g., `StringInterpolator.php`)
- `{ClassName}.{feature}.php` - Tests specific feature (e.g., `Translator.transformations.php`)
- `{functionName}.php` - Tests for specific function in the mini\ namespace.

## Architectural Patterns

### **Singleton Pattern**
Core utilities use lazy singletons accessed via global functions:
```php
function translator(): Translator { /* lazy init */ }
function db(): DB { /* lazy init */ }
function fmt(): Fmt { /* lazy init */ }
function cache(?string $namespace = null): Cache\SimpleCacheInterface { /* lazy init */ }
```

### **Filter Handler Pattern**
Extensible filter system using handler registration:
```php
$interpolator->addFilterHandler(function($value, $filterName) {
    if ($filterName === 'myfilter') return processValue($value);
    return null; // Pass to next handler
});
```

### **Configuration Priority**
1. URL parameters (e.g., `?lang=de`)
2. User preferences (database)
3. Browser detection (Accept-Language)
4. Framework defaults

## Internationalization Factory System

### **Core Principle**
Mini provides factory functions that give developers access to properly configured PHP intl classes while maintaining consistent locale behavior across the application.

### **Factory Functions**
```php
// Locale resolution and utilities
function locale(): string                              // Get current locale string
function localeLanguage(?string $locale = null): string    // Get language code ('en' from 'en_US')
function localeRegion(?string $locale = null): ?string     // Get region code ('US' from 'en_US')
function parseLocale(?string $locale = null): array        // Parse locale into components
function canonicalizeLocale(string $locale): string        // Canonicalize locale string

// Formatter factories (use config files for customization)
function numberFormatter(?string $locale = null, int $style = NumberFormatter::DECIMAL): NumberFormatter
function messageFormatter(string $pattern, ?string $locale = null): MessageFormatter
function intlDateFormatter(?int $dateType = IntlDateFormatter::MEDIUM, ?int $timeType = IntlDateFormatter::SHORT, ?string $locale = null, ?string $timezone = null, ?string $pattern = null): IntlDateFormatter
function collator(): Collator

// Convenient stateless formatter
function fmt(): Fmt  // Returns stateless instance, all methods are static
```

### **Usage Patterns**

**Direct PHP intl usage with consistent locale:**
```php
$formatter = numberFormatter('nb_NO', NumberFormatter::CURRENCY);
echo $formatter->formatCurrency(19.99, 'NOK'); // "kr 19,99"

$dateFormatter = intlDateFormatter(IntlDateFormatter::FULL, IntlDateFormatter::SHORT, 'nb_NO');
echo $dateFormatter->format(new DateTime()); // "torsdag 26. september 2024 kl. 14:30"
```

**Convenient shortcuts for common cases:**
```php
echo Fmt::currency(19.99, 'NOK');     // Uses current locale automatically
echo Fmt::dateShort(new DateTime());  // Uses current locale automatically
echo Fmt::percent(0.75, 1);          // "75.0%"
```

### **Configuration Files**
Each factory can be customized via config files in `mini/config/`:
- `number-formatter.php` - Custom NumberFormatter configuration
- `message-formatter.php` - Custom MessageFormatter configuration
- `intl-date-formatter.php` - Custom IntlDateFormatter configuration
- `collator.php` - Custom Collator configuration

**Example config file:**
```php
// mini/config/number-formatter.php
return function (string $locale, int $style): NumberFormatter {
    $formatter = new NumberFormatter($locale, $style);
    // Custom configuration for your app
    $formatter->setAttribute(NumberFormatter::GROUPING_SEPARATOR_SYMBOL, ' ');
    return $formatter;
};
```

## Translation System Architecture

### **Key Components**
- **Translatable objects** - Immutable translation requests
- **StringInterpolator** - Advanced variable interpolation with filters
- **QueryParser** - Condition matching for complex logic
- **transformations.json** - Language-specific transformation rules

### **Translation Flow**
1. `t("Hello {name}", ['name' => 'World'])` creates Translatable
2. Translator loads translation files with fallback chain
3. StringInterpolator processes variables and filters
4. Transformations.json provides language-specific formatting

### **Filter System**
```php
// Built-in transformation filters (from transformations.json)
echo t("You are {rank:ordinal}", ['rank' => 21]); // "You are 21st"

// Custom developer filters
translator()->getInterpolator()->addFilterHandler(function($value, $filter) {
    if ($filter === 'reverse') return strrev($value);
    return null;
});
```

## Database Integration

### **QueryParser Database Mapping**
QueryParser conditions can be converted to SQL WHERE clauses:
- `name=john` → `name = 'john'`
- `age:gte=18` → `age >= 18`
- `count:like=*1` → `count LIKE '%1'` (future enhancement)

### **Database-Friendly Design**
- **Indexable queries** - Avoid `neq`, `mod` operators
- **Use evaluation order** instead of complex boolean logic
- **SQLite3 semantics** - Type coercion and comparison rules

## Testing Conventions

### **Test Structure**
```php
function test(string $description, callable $test): void
function assertEqual($expected, $actual, string $message = ''): void
```

### **Autoloader Pattern**
```php
$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');
```

### **Test Categories**
- **Basic functionality** - Core features work correctly
- **Edge cases** - Handle unusual inputs gracefully
- **Error conditions** - Proper error messages and fallbacks
- **Integration** - Components work together properly
- **Future-proofing** - Infrastructure for planned features

## Future Enhancement Architecture

### **Planned Features**
The framework is architected to support:

1. **`like` Operator** - Pattern matching (`count:like=*1`)
2. **Function System** - Mathematical operations (`mod(count,10)`)
3. **Variable References** - Cross-variable comparisons (`age:gt={minimum}`)
4. **Complex Conditionals** - Nested boolean logic

### **Extension Points**
- **QueryParser operators** - Add via `$allowedOperators` array
- **StringInterpolator filters** - Add via `addFilterHandler()`
- **Fmt formatters** - Add methods to Fmt class
- **Cache adapters** - Implement SimpleCacheInterface

## Best Practices for Claude Code Development

### **When Adding New Features**
1. **Write tests first** - Create `mini/tests/{Class}.{feature}.php`
2. **Maintain backwards compatibility** - Don't break existing APIs
3. **Follow established patterns** - Use singleton, filter handler patterns
4. **Update this guide** - Document new conventions and patterns

### **Error Handling Philosophy**
- **Fail gracefully** - Show meaningful errors, don't crash
- **Provide fallbacks** - Return sensible defaults when possible
- **Log for developers** - Clear diagnostics in development
- **Hide complexity from users** - Simple, clean error messages

### **Performance Considerations**
- **Static caching** - Cache expensive operations (file loads, parsing)
- **Lazy initialization** - Don't load what you don't need
- **Database efficiency** - Design for indexable queries
- **Minimal memory footprint** - Clean up temporary resources

## Integration with Applications

### **Bootstrap Pattern**
```php
// Application config/bootstrap.php
use function mini\{translator, db, fmt};

// Custom filters
translator()->getInterpolator()->addFilterHandler(function($value, $filter) {
    if ($filter === 'myCustomFilter') return transform($value);
    return null;
});

// Language detection
$language = $_GET['lang'] ?? getUserLanguagePreference() ?? detectBrowserLanguage();
translator()->trySetLanguageCode($language);
```

### **Framework Boundaries**
- **Framework provides** - Core utilities, translation system, database abstraction
- **Application provides** - Business logic, custom filters, language detection
- **Clear separation** - Framework doesn't know about application-specific concerns

This architecture enables rapid development of internationalized applications while maintaining clean, testable, and maintainable code that works exceptionally well with Claude Code's development workflow.
