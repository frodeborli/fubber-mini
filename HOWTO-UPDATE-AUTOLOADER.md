# How to Update Autoloader After Adding Files to Mini

When developing Mini framework with `"preferred-install": "source"`, Composer caches the autoload configuration in `vendor/composer/installed.json` at install time. Simply running `composer dump-autoload` regenerates from this cache, not from the package's current `composer.json`.

## Problem

After adding a new file to `autoload.files` in `vendor/fubber/mini/composer.json`:
```json
"files": [
    "src/NewFeature/functions.php"  // Added this
]
```

Running `composer dump-autoload` won't pick it up because `installed.json` still has the old list.

## Solution

Run this command from the project root to sync the autoload config:

```bash
php -r "
\$file = 'vendor/composer/installed.json';
\$data = json_decode(file_get_contents(\$file), true);

foreach (\$data['packages'] as &\$pkg) {
    if (\$pkg['name'] === 'fubber/mini') {
        \$pkgComposer = json_decode(file_get_contents('vendor/fubber/mini/composer.json'), true);
        \$pkg['autoload'] = \$pkgComposer['autoload'];
        echo \"Updated autoload for fubber/mini\\n\";
        break;
    }
}

file_put_contents(\$file, json_encode(\$data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
" && composer dump-autoload
```

Or as a one-liner:
```bash
php -r "\$f='vendor/composer/installed.json';\$d=json_decode(file_get_contents(\$f),true);foreach(\$d['packages'] as &\$p)if(\$p['name']==='fubber/mini'){\$p['autoload']=json_decode(file_get_contents('vendor/fubber/mini/composer.json'),true)['autoload'];break;}file_put_contents(\$f,json_encode(\$d,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));" && composer dump-autoload
```

## Why This Happens

1. Mini is installed from source (`"preferred-install": {"fubber/mini": "source"}`)
2. Composer reads package's `composer.json` once at install/update time
3. Autoload config is cached in `vendor/composer/installed.json`
4. `composer dump-autoload` regenerates from `installed.json`, not from package's `composer.json`

## Alternative: Full Update

If you've committed changes to the Mini repo, you can also:
```bash
composer update fubber/mini
```

But this requires the changes to be committed and may update other dependencies.
