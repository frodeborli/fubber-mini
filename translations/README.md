# Framework translations

This directory is the **framework-translations layer**: the shipped, lowest-priority
source of translated strings for `t()` calls that originate inside Mini itself.

It is registered unconditionally at boot in
[`src/I18n/functions.php`](../src/I18n/functions.php):

```php
$primaryTranslationsPath = $_ENV['MINI_TRANSLATIONS_ROOT'] ?? (Mini::$mini->root . '/_translations');
Mini::$mini->paths->translations = new PathsRegistry($primaryTranslationsPath);
$frameworkTranslationsPath = \dirname((new \ReflectionClass(Mini::class))->getFileName(), 2) . '/translations';
Mini::$mini->paths->translations->addPath($frameworkTranslationsPath);
```

## Two layers, in priority order

| Priority | Path | Owner | Tracked by git |
| --- | --- | --- | --- |
| 1 (wins) | `<app root>/_translations/` — or `$_ENV['MINI_TRANSLATIONS_ROOT']` | the **application** | yes, in the app's repo |
| 2 (fallback) | `<mini root>/translations/` — this directory | the **framework** | yes, in this repo |

An application overrides any framework string by placing a file with the same
relative name under its own `_translations/`. Nothing is merged key-by-key
across layers: `PathsRegistry` returns the first matching file, so an override
file replaces the framework file for that source file.

## Layout

```
translations/<language>/<alias>/<path/to/source.php>.json
```

`<alias>` comes from `Translator::addPathAlias()` — Mini's own source is
registered under the `MINI` alias. So a `t()` call in `src/Validator/Validator.php`
resolves, in order, to:

```
_translations/default/MINI/src/Validator/Validator.php.json   (application override)
translations/default/MINI/src/Validator/Validator.php.json    (framework, this directory)
```

Each JSON file maps source text to translated text for that one source file.

## Why this directory is (currently) otherwise empty

Mini ships no translated strings yet — English source text is the default
language, and `Translator` falls through to the source text when no file
matches. `PathsRegistry::addPath()` never stats the path, so an empty or absent
directory is not an error; it simply never matches.

Do **not** commit auto-generated identity-mapping files here. Files whose
values equal their keys carry no information. They are produced by
`Translator::autoCreateDefaults`, which writes to the *application* layer
(`_translations/`), and that layer is gitignored in this repo precisely because
running the test suite or `bin/mini` from a framework checkout would otherwise
spill them into the working tree.

Add a file here only when it contains a real translation of a real Mini string.
