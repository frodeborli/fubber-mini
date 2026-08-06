# I18n - Internationalization

Translation and locale-aware formatting built on PHP's `intl` extension and ICU MessageFormat — Lindy, standards-based i18n a forkable core can rely on without bundles or gettext toolchains.

## Overview

Mini's I18n system provides translations and locale-aware formatting using PHP's native `intl` extension and ICU MessageFormat.

**How it works:**
1. Call `t("Hello {name}", ['name' => 'World'])` in your code
2. Translator looks up the string in `_translations/{lang}/_routes/yourfile.php.json`
3. ICU MessageFormatter handles variable substitution and pluralization
4. Result is returned in the user's language

**Key features:**
- **File-per-source organization** - translations mirror your source file structure
- **Auto-creation** - missing strings automatically added to `default/` folder
- **Fallback chain** - tries current locale → default language → `default` folder
- **ICU MessageFormat** - industry-standard pluralization and formatting

## Adding a New Language

Use the translations tool to add a new language:

```bash
# 1. Ensure default translations exist for all t() calls
vendor/bin/mini translations add-missing

# 2. Create the new language files
vendor/bin/mini translations add-language nb
```

This creates `_translations/nb/` with all your translation strings set to `null` (falling back to default until translated). Now translate the strings in those files.

### 3. Set the locale per request

```php
// In your route or bootstrap
\Locale::setDefault('nb');
date_default_timezone_set('Europe/Oslo');
```

That's it. No configuration files or registration needed.

### Manual approach

You can also create language folders manually. Translation files are JSON and mirror your source file paths:

```
_translations/
├── default/          # Fallback (usually English)
│   └── _routes/
│       └── index.php.json
└── nb/               # Norwegian Bokmål
    └── _routes/
        └── index.php.json
```

```json
// _translations/nb/_routes/index.php.json
{
    "Welcome": "Velkommen",
    "Hello {name}": "Hei {name}",
    "{count, plural, =0{no messages} one{# message} other{# messages}}": "{count, plural, =0{ingen meldinger} one{# melding} other{# meldinger}}"
}
```

## Using Translations

### The t() function

```php
// Simple text
echo t("Welcome");  // "Velkommen"

// With variables
echo t("Hello {name}", ['name' => 'Ola']);  // "Hei Ola"

// Pluralization
echo t("{count, plural, =0{no items} one{# item} other{# items}}", ['count' => 5]);
// "5 items"
```

### Locale-aware formatting

```php
\Locale::setDefault('nb');

echo Fmt::currency(1234.56, 'NOK');  // "kr 1 234,56"
echo Fmt::number(1234567.89);        // "1 234 567,89"
echo Fmt::dateShort(new DateTime()); // "19.11.2025"
```

## Translation File Structure

Files are organized to mirror your source code:

```
_translations/
├── default/                    # Fallback language
│   ├── _routes/
│   │   ├── index.php.json     # For _routes/index.php
│   │   └── users/
│   │       └── profile.php.json
│   └── src/
│       └── Services/
│           └── Mailer.php.json
├── nb/                         # Norwegian
│   └── _routes/
│       └── index.php.json
└── de/                         # German
    └── _routes/
        └── index.php.json
```

**Why this structure?**
- Easy to find which file needs translation
- Each file stays small and focused
- Git diffs show exactly what changed
- Auto-creation puts new strings in the right place

## Setting the Locale

### From user preference

```php
if (isset($_SESSION['locale'])) {
    \Locale::setDefault($_SESSION['locale']);
}
```

### From browser

```php
$locale = \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
if ($locale) {
    \Locale::setDefault($locale);
}
```

### From URL

```php
if (isset($_GET['lang'])) {
    $_SESSION['locale'] = $_GET['lang'];
    \Locale::setDefault($_GET['lang']);
}
```

## ICU MessageFormat Patterns

### Pluralization

```php
// Basic plural
t("{n, plural, one{# item} other{# items}}", ['n' => 1]);
// 1 → "1 item", 2 → "2 items"

// With zero case
t("{n, plural, =0{no items} one{# item} other{# items}}", ['n' => 0]);
// 0 → "no items"

// Multiple variables
t("{name} has {n, plural, one{# message} other{# messages}}", [
    'name' => 'Ola',
    'n' => 3
]);
// "Ola has 3 messages"
```

### Select (gender, status, etc.)

```php
t("{gender, select, male{He} female{She} other{They}} liked your post", [
    'gender' => 'female'
]);
// "She liked your post"
```

### Ordinals

```php
t("You finished {place, selectordinal, one{#st} two{#nd} few{#rd} other{#th}}", [
    'place' => 2
]);
// "You finished 2nd"
```

## Formatting Functions

All formatting respects the current locale set via `\Locale::setDefault()`:

```php
// Numbers
Fmt::number(1234.56);           // "1,234.56" or "1.234,56"
Fmt::percent(0.75);             // "75%"

// Currency
Fmt::currency(19.99, 'USD');    // "$19.99"
Fmt::currency(19.99, 'EUR');    // "19,99 €"

// Dates
$date = new DateTime();
Fmt::dateShort($date);          // "11/19/25" or "19.11.25"
Fmt::dateLong($date);           // "November 19, 2025"
Fmt::timeShort($date);          // "2:30 PM" or "14:30"

// File sizes
Fmt::fileSize(1536000);         // "1.5 MB"
```

## Fallback Behavior

When looking up a translation, Mini tries in order:

1. **Current locale** (`_translations/nb/...`)
2. **Default language** (`_translations/en/...` if configured)
3. **Default folder** (`_translations/default/...`)
4. **Source text** (returns the original string)

If `autoCreateDefaults` is enabled (default), missing strings are automatically added to `_translations/default/`.

## Configuration

### Custom translator

```php
<?php
// _config/mini/I18n/TranslatorInterface.php

use mini\I18n\Translator;

return new Translator(
    translationsPaths: Mini::$mini->paths->translations,
    autoCreateDefaults: true
);
```

### Path aliases for packages

When using vendor packages that have their own translations:

```php
$translator->addPathAlias(
    absolutePath: __DIR__ . '/../../vendor/acme/blog',
    alias: 'ACME-BLOG'
);
```

This maps translations from that package to `_translations/{lang}/ACME-BLOG/...`.

### Environment variables

- `MINI_LOCALE` - Default locale (e.g., `en_US`, `nb_NO`)
- `MINI_TIMEZONE` - Default timezone (e.g., `Europe/Oslo`)

## Translation Management Tool

Mini includes a CLI tool for managing translation files:

```bash
vendor/bin/mini translations                    # Show translation status (missing/orphaned strings)
vendor/bin/mini translations add-missing        # Add missing strings to default files
vendor/bin/mini translations remove-orphans     # Remove strings no longer in source code
vendor/bin/mini translations add-language nb    # Create new language (Norwegian)
vendor/bin/mini translations update-language nb # Sync language files with default
```

### Keeping translations in sync

After adding new `t()` calls to your code:

```bash
# Add new strings to default
vendor/bin/mini translations add-missing

# Update all language files
vendor/bin/mini translations update-language nb
vendor/bin/mini translations update-language de
```

### Removing a language

Simply delete the language folder:

```bash
rm -rf _translations/nb
```

The tool scans your PHP files for `t()` calls and ensures translation files stay synchronized with your source code.

## Quick Reference

```php
// Set locale
\Locale::setDefault('nb');
date_default_timezone_set('Europe/Oslo');

// Translate
t("Hello {name}", ['name' => 'World']);

// Format
Fmt::currency(99.50, 'NOK');
Fmt::dateShort(new DateTime());
Fmt::number(1234.56);

// Plural pattern
{count, plural, =0{none} one{# item} other{# items}}

// Select pattern
{gender, select, male{He} female{She} other{They}}
```
