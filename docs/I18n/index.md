# I18n - Internationalization

Translate text with `mini\t()` and format locale-specific data with `mini\fmt()`.

## Translation

```php
<?php
// Simple translation
echo mini\t("Hello, World!");

// With placeholders
echo mini\t("Hello, {name}!", ['name' => 'John']);

// ICU MessageFormat for plurals
echo mini\t("{count, plural, =0{no items} one{# item} other{# items}}",
    ['count' => 3]);
// Output: "3 items"

// ICU MessageFormat for gender
echo mini\t("{gender, select, male{He} female{She} other{They}} is online",
    ['gender' => 'female']);
// Output: "She is online"
```

## Formatting

```php
<?php
use mini\I18n\Fmt;

// Currency
echo Fmt::currency(19.99, 'USD');  // "$19.99" (US)
echo Fmt::currency(19.99, 'EUR');  // "19,99 €" (EU)

// Numbers
echo Fmt::number(1234567.89);      // "1,234,567.89" (US)

// Dates
$date = new DateTime('2025-01-15');
echo Fmt::dateShort($date);        // "1/15/25" (US)
echo Fmt::dateMedium($date);       // "Jan 15, 2025" (US)
echo Fmt::dateLong($date);         // "January 15, 2025" (US)

// Times
echo Fmt::timeShort($date);        // "3:30 PM"
echo Fmt::timeMedium($date);       // "3:30:45 PM"
```

## Setting Locale

```php
<?php
// Per-request locale
\Locale::setDefault('de_DE');
date_default_timezone_set('Europe/Berlin');

// Now all t() and Fmt calls use German locale
echo mini\t("Hello");              // "Hallo"
echo Fmt::currency(19.99, 'EUR');  // "19,99 €"
```

## Translation Files

Store translations in `_translations/{lang}.php`:

```php
<?php
// _translations/de.php
return [
    "Hello, World!" => "Hallo, Welt!",
    "Welcome, {name}!" => "Willkommen, {name}!",
];
```

## API Reference

See `mini\I18n\Fmt` for all formatting methods.
