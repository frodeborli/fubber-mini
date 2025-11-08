# Code Translation Approach for Swoole Compatibility

## The Idea

Use PHP's tokenizer to automatically rewrite function calls:
```php
// Original code
header('Location: /home');

// Automatically becomes
mini\header('Location: /home');
```

## Your PhpCodeTranslator Implementation

The tokenizer approach correctly:
- ✅ Detects root-namespace function calls (not `\header()` or `Foo\header()`)
- ✅ Only rewrites function calls (checks for `(` after function name)
- ✅ Preserves all whitespace, comments, and formatting
- ✅ Uses token stream for accurate parsing (not regex)

## Integration Strategies

### Option 1: Stream Wrapper (Transparent)

**Most transparent approach** - intercept all `include`/`require`:

```php
// vendor/fubber/mini-swoole/src/StreamWrapper.php
namespace mini\Swoole;

class TranslatingStreamWrapper {
    private static PhpCodeTranslator $translator;
    private $resource;
    private $path;

    public static function register(array $functionMap): void {
        self::$translator = new PhpCodeTranslator($functionMap);
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);
    }

    public function stream_open($path, $mode, $options, &$opened_path) {
        // Only translate .php files being read
        if ($mode[0] === 'r' && str_ends_with($path, '.php')) {
            $realPath = str_replace('file://', '', $path);

            // Check cache first
            $cacheKey = 'translated_' . md5($realPath . filemtime($realPath));
            $translated = apcu_fetch($cacheKey);

            if ($translated === false) {
                $source = file_get_contents($realPath);
                $translated = self::$translator->translate($source);
                apcu_store($cacheKey, $translated, 3600);
            }

            // Create in-memory stream with translated code
            $this->resource = fopen('php://memory', 'r+');
            fwrite($this->resource, $translated);
            rewind($this->resource);
            return true;
        }

        // Pass through non-PHP files
        $this->resource = fopen($path, $mode);
        return $this->resource !== false;
    }

    public function stream_read($count) {
        return fread($this->resource, $count);
    }

    // ... implement other stream methods
}

// Enable in swoole-server.php
TranslatingStreamWrapper::register([
    'header' => 'mini\\header',
    'http_response_code' => 'mini\\http_response_code',
    'session_start' => 'mini\\session_start',
]);
```

**Pros:**
- Completely transparent
- Works with all includes
- Can cache translations in APCu

**Cons:**
- Stream wrapper overhead on every file access
- Tricky to implement correctly (many edge cases)
- Can break opcache (opcache caches original source)
- Debugging shows translated code in stack traces

### Option 2: Composer Autoloader Integration

**Hook into Composer's autoloader:**

```php
// vendor/fubber/mini-swoole/bootstrap.php
namespace mini\Swoole;

$translator = new PhpCodeTranslator([
    'header' => 'mini\\header',
    'http_response_code' => 'mini\\http_response_code',
]);

// Prepend to autoloader
spl_autoload_register(function($class) use ($translator) {
    $file = /* resolve class to file via composer classmap */;

    if ($file && str_starts_with($file, PROJECT_ROOT)) {
        // Only translate project files, not vendor
        $cacheKey = 'class_' . $class . '_' . filemtime($file);
        $translated = apcu_fetch($cacheKey);

        if ($translated === false) {
            $source = file_get_contents($file);
            $translated = $translator->translate($source);
            apcu_store($cacheKey, $translated, 3600);
        }

        eval('?>' . $translated);
        return true;
    }
}, true, true); // prepend
```

**Pros:**
- Only affects autoloaded classes
- Easy to limit to project files only
- Works with opcache (for non-translated files)

**Cons:**
- Doesn't affect procedurally loaded files (route handlers)
- `eval()` makes debugging harder
- Still has translation overhead

### Option 3: Build Step (Production-Ready)

**Pre-translate during deployment:**

```php
// bin/mini-swoole-build
#!/usr/bin/env php
<?php
require 'vendor/autoload.php';

$translator = new mini\Swoole\PhpCodeTranslator([
    'header' => 'mini\\header',
    'http_response_code' => 'mini\\http_response_code',
]);

$buildDir = __DIR__ . '/../build';
$sourceDir = __DIR__ . '/..';

// Copy and translate project files
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceDir)
);

foreach ($files as $file) {
    if ($file->getExtension() !== 'php') continue;

    $source = file_get_contents($file);
    $translated = $translator->translate($source);

    $relativePath = str_replace($sourceDir, '', $file->getPathname());
    $targetPath = $buildDir . $relativePath;

    mkdir(dirname($targetPath), 0755, true);
    file_put_contents($targetPath, $translated);
}

echo "Build complete. Run Swoole server from: $buildDir\n";
```

**Pros:**
- Zero runtime overhead
- opcache works normally
- Easy to diff translated code
- Production-ready

**Cons:**
- Extra build step
- Need to rebuild on code changes
- More complex deployment

### Option 4: Opcache Preload with Translation

**Best of both worlds:**

```php
// config/opcache-preload.php
$translator = new mini\Swoole\PhpCodeTranslator([
    'header' => 'mini\\header',
    'http_response_code' => 'mini\\http_response_code',
]);

$files = [
    __DIR__ . '/../src/Mini.php',
    __DIR__ . '/../functions.php',
    // ... list all files to preload
];

foreach ($files as $file) {
    $source = file_get_contents($file);
    $translated = $translator->translate($source);

    // Store translated version for opcache
    $tempFile = sys_get_temp_dir() . '/mini_translated_' . md5($file) . '.php';
    file_put_contents($tempFile, $translated);

    opcache_compile_file($tempFile);
}
```

**php.ini:**
```ini
opcache.preload=/path/to/config/opcache-preload.php
```

**Pros:**
- Translations cached in opcache
- Zero per-request overhead
- Works with existing code

**Cons:**
- Requires opcache
- PHP 7.4+ only
- Needs server restart to update

## Performance Analysis

### Without Caching
```php
// Tokenize + translate on every request
// ~0.5-2ms per file depending on size
// 100 files = 50-200ms overhead per request (BAD!)
```

### With APCu Caching
```php
// First request: translate + cache (2ms)
// Subsequent: APCu fetch (0.01ms)
// Negligible overhead after warmup
```

### With Build Step
```php
// Zero runtime overhead
// Translation happens once during deployment
```

## Edge Cases to Consider

### 1. Dynamic Function Calls
```php
$func = 'header';
$func('Location: /');  // Can't detect this!
```

**Solution**: Can't translate. Document that dynamic calls need explicit namespace.

### 2. Function Exists Checks
```php
if (function_exists('header')) { ... }  // Still true
```

**Solution**: Works fine - native function still exists.

### 3. Namespaced Code
```php
namespace App\Controllers;

header('Location: /');  // Should become mini\header
\header('Location: /'); // Should stay \header (explicit root)
```

**Your implementation handles this correctly** ✅

### 4. Function References
```php
$ref = new ReflectionFunction('header');  // Gets native function
```

**Solution**: Document limitation. Rare in practice.

### 5. Variable Function Names
```php
$fn = 'hea' . 'der';
$fn('X-Foo: bar');  // Can't detect
```

**Solution**: Can't translate. Document limitation.

## Recommendation

**For Development**: Stream wrapper or autoloader integration with APCu caching
**For Production**: Build step approach (pre-translate)

### Hybrid Approach
```php
// config/mini-swoole.php
return [
    'translation' => [
        'enabled' => getenv('MINI_ENV') !== 'production',
        'cache' => 'apcu',
        'build_on_deploy' => true,
    ],
    'function_map' => [
        'header' => 'mini\\header',
        'http_response_code' => 'mini\\http_response_code',
        'session_start' => 'mini\\session_start',
        'setcookie' => 'mini\\setcookie',
    ],
];

// Development: Translate on-the-fly with caching
// Production: Use pre-translated build
```

## Alternative: PHP Extension

For ultimate performance, implement as PHP extension:

```c
// mini_swoole.c
ZEND_FUNCTION(header) {
    if (swoole_coroutine_get_cid() > 0) {
        // Call mini\header
        zend_fcall_info fci;
        // ... call mini\header with same args
    } else {
        // Call original header
        original_header(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    }
}
```

**Pros**: Zero overhead, transparent
**Cons**: Requires C extension, maintenance burden

## Conclusion

Your `PhpCodeTranslator` is well-designed and handles the tokenization correctly. The question is **where** to integrate it:

**My recommendation**:
1. Start with **Build Step** (Option 3) for production readiness
2. Add **Stream Wrapper** (Option 1) with APCu for development ease
3. Document that developers CAN just use `mini\header()` directly

This gives developers three options:
- Explicit: Use `mini\header()` everywhere (clearest)
- Transparent Dev: Enable stream wrapper (easiest for migration)
- Transparent Prod: Use build step (best performance)

The code translation approach is solid - it's about choosing the right integration point for your use case.
