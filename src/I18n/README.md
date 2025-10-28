# Mini I18n

Comprehensive internationalization and localization system for the Mini framework. Provides translation management, locale-aware formatting, and full ICU MessageFormatter support.

## Features

- **ICU MessageFormatter** - Industry-standard message formatting with plurals, ordinals, dates, numbers
- **Translation Management** - File-based translations with automatic fallback chains
- **Conditional Translations** - Multi-variable business logic in translations
- **Locale-Aware Formatting** - Numbers, currencies, dates, times formatted per locale
- **Lazy Initialization** - Translations and formatters loaded only when needed
- **Auto-Create Defaults** - Missing translations automatically added to default language files
- **Framework Scoping** - Allows frameworks/packages to provide their own translations

## Installation

Currently bundled with `fubber/mini`. In the future:

```bash
composer require fubber/mini-i18n
```

## Basic Usage

### Simple Translation

```php
use function mini\t;

// Basic translation
echo t("Hello, World!");

// With variable interpolation
echo t("Hello, {name}!", ['name' => 'John']);

// In templates
<h1><?= t("Welcome to {site}", ['site' => 'Mini Framework']) ?></h1>
```

### ICU MessageFormatter

```php
// Pluralization
echo t("{count, plural, =0{no messages} =1{one message} other{# messages}}", ['count' => 5]);
// Output: "5 messages"

// Ordinals
echo t("You finished {place, selectordinal, one{#st} two{#nd} few{#rd} other{#th}}!", ['place' => 21]);
// Output: "You finished 21st!"

// Gender selection
echo t("{gender, select, male{He} female{She} other{They}} is online", ['gender' => 'female']);
// Output: "She is online"

// Date formatting
echo t("Today is {date, date, full}", ['date' => new DateTime()]);
// Output: "Today is Monday, October 27, 2025"

// Number formatting
echo t("Price: {amount, number, currency}", ['amount' => 19.99]);
// Output: "Price: $19.99" (depends on locale)
```

### Formatting Shortcuts

```php
use function mini\fmt;

// Currency formatting
echo fmt()->currency(19.99, 'USD'); // "$19.99"
echo fmt()->currency(19.99, 'EUR'); // "€19.99"

// Number formatting
echo fmt()->number(1234567.89);          // "1,234,567.89"
echo fmt()->percent(0.85, 1);           // "85.0%"

// Date formatting
echo fmt()->dateShort(new DateTime());   // "10/27/25"
echo fmt()->dateLong(new DateTime());    // "October 27, 2025"
echo fmt()->time(new DateTime());        // "2:30 PM"
```

## Translation Files

### File Structure

```
_translations/
├── default/                 # Default language (English)
│   ├── pages/index.php.json
│   └── components/header.php.json
├── no/                     # Norwegian
│   ├── pages/index.php.json
│   └── components/header.php.json
└── de/                     # German
    └── pages/index.php.json
```

### Translation File Format

**_translations/default/pages/index.php.json:**
```json
{
  "Welcome to Mini Framework": "Welcome to Mini Framework",
  "Get Started": "Get Started",
  "Hello, {name}!": "Hello, {name}!",
  "You have {count, plural, =0{no messages} =1{one message} other{# messages}}": "You have {count, plural, =0{no messages} =1{one message} other{# messages}}"
}
```

**_translations/no/pages/index.php.json:**
```json
{
  "Welcome to Mini Framework": "Velkommen til Mini Framework",
  "Get Started": "Kom i gang",
  "Hello, {name}!": "Hei, {name}!",
  "You have {count, plural, =0{no messages} =1{one message} other{# messages}}": "Du har {count, plural, =0{ingen meldinger} =1{én melding} other{# meldinger}}"
}
```

## Conditional Translations

For complex business logic that varies by language:

```json
{
  "shipping_message": {
    "total:gte=50&country=US": "🚛 Free shipping to US!",
    "total:gte=100&country=NO": "🚛 Gratis frakt til Norge!",
    "": "Standard shipping applies"
  }
}
```

Usage:
```php
echo t("shipping_message", [
    'total' => $orderTotal,
    'country' => $userCountry
]);
```

### Query Operators

- `=` - Equals
- `:gt`, `:gte` - Greater than, greater than or equal
- `:lt`, `:lte` - Less than, less than or equal
- `:neq` - Not equal
- `:in` - In array
- `:like` - Pattern matching with `*` wildcards
- `&` - AND logic

## Locale Management

### Setting Locale

The Translator reads locale dynamically from `\Locale::getDefault()`, so you can set the locale early in your request:

```php
// Early in bootstrap or middleware
// Priority order:
// 1. URL parameter
if (isset($_GET['lang'])) {
    \Locale::setDefault($_GET['lang']);
}
// 2. Session preference
elseif (isset($_SESSION['language'])) {
    \Locale::setDefault($_SESSION['language']);
}
// 3. Browser detection
else {
    $browserLang = \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    \Locale::setDefault($browserLang);
}

// All t() calls now use the correct locale automatically!
```

Alternatively, use the Translator helper methods:

```php
use mini\Mini;
use mini\I18n\Translator;

$translator = Mini::$mini->get(Translator::class);

// Try to set language (with validation)
if ($translator->trySetLanguageCode('nb')) {
    // Language was valid and set
}

// Or set directly (no validation)
$translator->setLanguageCode('de');
```

**How it works**: The Translator is a Singleton that reads `\Locale::getDefault()` on each translation, so all users share the same Translator instance, but each request can have its own locale.

### Getting Locale Information

```php
// Get current locale
$locale = \Locale::getDefault(); // e.g., "en_US"

// Parse locale components
$language = \Locale::getPrimaryLanguage($locale); // "en"
$region = \Locale::getRegion($locale);           // "US"
$script = \Locale::getScript($locale);           // ""
```

## Advanced Features

### Direct Access to Translator

For advanced use cases like changing language or getting translation stats, access the Translator instance from the container:

```php
use mini\Mini;
use mini\I18n\Translator;

// Get translator instance
$translator = Mini::$mini->get(Translator::class);

// Change language
$translator->setLanguageCode('de');

// Get translation stats
$stats = $translator->getTranslationStats('no');
```

### Using PHP's Intl Classes Directly

If you need more control than `fmt()` provides, use PHP's intl classes directly with `\Locale::getDefault()`:

```php
// NumberFormatter
$formatter = new \NumberFormatter(\Locale::getDefault(), \NumberFormatter::ORDINAL);
echo $formatter->format(21); // "21st"

// MessageFormatter
$formatter = new \MessageFormatter(\Locale::getDefault(), "{count, plural, =0{no items} one{# item} other{# items}}");
echo $formatter->format(['count' => 5]); // "5 items"

// IntlDateFormatter with custom pattern
$formatter = new \IntlDateFormatter(\Locale::getDefault(), null, null);
$formatter->setPattern('yyyy-MM-dd');
echo $formatter->format(new DateTime()); // "2025-10-27"
```

### Auto-Create Missing Translations

When `autoCreateDefaults` is enabled (default), missing translations are automatically added to the default language file:

```php
// First request - translation missing
echo t("New feature title");

// File automatically updated:
// _translations/default/pages/current.php.json
{
  "New feature title": "New feature title"
}
```

### Translation Scopes

Frameworks and packages can provide their own translations:

```php
use mini\Mini;
use mini\I18n\Translator;

// Get translator and register a package scope
$translator = Mini::$mini->get(Translator::class);
$translator->addNamedScope('MY-PACKAGE', __DIR__ . '/vendor/mypackage');

// Package translations:
// vendor/mypackage/_translations/default/src/Component.php.json
```

### Translation Statistics

```php
use mini\Mini;
use mini\I18n\Translator;

$translator = Mini::$mini->get(Translator::class);
$stats = $translator->getTranslationStats('no');
// [
//   'pages/index.php' => [
//     'total' => 50,
//     'translated' => 45,
//     'percentage' => 90.0
//   ]
// ]
```

### Available Languages

```php
use mini\Mini;
use mini\I18n\Translator;

$translator = Mini::$mini->get(Translator::class);
$languages = $translator->getAvailableLanguages();
// ['default', 'no', 'de', 'fr']
```

## Best Practices

### 1. Use ICU MessageFormat for Standard i18n

```php
// Good: Industry standard
t("{count, plural, =0{no items} one{# item} other{# items}}", ['count' => $n])

// Avoid: Custom logic in code
if ($n == 0) {
    t("no items");
} elseif ($n == 1) {
    t("one item");
} else {
    t("{count} items", ['count' => $n]);
}
```

### 2. Keep Translation Keys Simple

```php
// Good: Natural source text
t("Welcome to our website")

// Avoid: Cryptic keys
t("welcome.site.header.title")
```

### 3. Format Values Before Passing

```php
// Good: Format in PHP
t("Price: {amount}", ['amount' => fmt()->currency(19.99, 'USD')])

// Avoid: Formatting in translation
t("Price: ${amount}", ['amount' => 19.99])
```

### 4. Use Conditional Translations for Business Logic

```php
// Good: Business rules in translations
t("discount_message", ['total' => $total, 'member' => $isMember])

// Avoid: Complex if/else in code
if ($isMember && $total >= 100) {
    t("Member discount: 20% off!");
} else if ($total >= 50) {
    t("Discount: 10% off!");
}
```

### 5. Provide Context in Variables

```php
// Good: Clear context
t("Order {orderId} from {customerName}", [
    'orderId' => $order->id,
    'customerName' => $order->customer->name
])

// Avoid: Ambiguous variables
t("Order {id} from {name}", ['id' => $id, 'name' => $name])
```

## Architecture

```
mini\I18n\
├── Translator.php          # Translation loading and management
├── Translatable.php        # Immutable translation request object
├── Fmt.php                # Formatting shortcuts
├── functions.php          # Global i18n functions
├── composer.json          # Package definition
└── README.md             # This file
```

### Translation Flow

1. `t("Hello {name}", ['name' => 'John'])` creates a `Translatable` instance
2. When converted to string, `Translatable` retrieves `Translator` (singleton) from container
3. `Translator` reads current locale from `\Locale::getDefault()`
4. `Translator` loads translations from files with fallback chain (cached in singleton)
5. ICU `MessageFormatter` interpolates variables with locale-specific formatting
6. Final translated string is returned

**Performance**: Translation files are loaded once and cached in the Translator singleton. Only the locale lookup (`\Locale::getDefault()`) happens per translation.

## Configuration

### Default Language

Set the default language for translation fallbacks using the `MINI_LANG` environment variable:

```bash
# .env file
MINI_LANG=en
```

This sets `Mini::$mini->defaultLanguage` which is used in the translation fallback chain:
1. Current user's language (from `\Locale::getDefault()`)
2. Default language (from `Mini::$mini->defaultLanguage`)
3. 'default' folder (English source text)

### Translations Path

By default, translations are stored in `{projectRoot}/_translations/`. This is automatically configured.

## Testing

Test translations in different locales:

```php
// tests/TranslationTest.php
use mini\Mini;
use mini\I18n\Translator;
use function mini\t;

$translator = Mini::$mini->get(Translator::class);

// Switch to Norwegian
$translator->setLanguageCode('nb');
echo t("Hello, World!");  // "Hei, verden!"

// Switch to German
$translator->setLanguageCode('de');
echo t("Hello, World!");  // "Hallo, Welt!"
```

## Future Separate Package

This feature is designed to be extracted as `fubber/mini-i18n`:
- Self-contained in `mini\I18n` namespace
- Depends on core `fubber/mini` framework
- Fully optional - core Mini works without I18n

## License

MIT License - see [LICENSE](../../LICENSE)
