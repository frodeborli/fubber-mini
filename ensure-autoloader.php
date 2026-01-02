<?php
/**
 * Autoloader Discovery for Mini CLI Tools
 *
 * Finds the nearest vendor/autoload.php for a fubber/mini project and loads it.
 * Mini::bootstrap() handles project root detection separately.
 *
 * Usage in CLI tools:
 *   require __DIR__ . '/find-autoloader.php';
 *   // mini\Mini::$mini is now available with correct root
 */

use Composer\Autoload\ClassLoader;

if (class_exists(ClassLoader::class, false)) {
    // Already loaded (e.g., called from within a project)
    return;
}

$cwd = getcwd();
$autoloaderPath = null;

// First: check if CWD is fubber/mini itself (dev mode inside vendor/)
$cwdComposer = $cwd . '/composer.json';
if (file_exists($cwdComposer)) {
    $composer = json_decode(file_get_contents($cwdComposer), true);
    if (($composer['name'] ?? '') === 'fubber/mini') {
        // Find autoloader by walking up (it's in parent project's vendor/)
        $dir = dirname($cwd);
        while ($dir !== '/' && $dir !== '') {
            if (file_exists($dir . '/vendor/autoload.php')) {
                $autoloaderPath = $dir . '/vendor/autoload.php';
                break;
            }
            $dir = dirname($dir);
        }
    }
}

// Second: walk up to find a project that has vendor/autoload.php and uses fubber/mini
if ($autoloaderPath === null) {
    $dir = $cwd;
    while ($dir !== '/' && $dir !== '') {
        $autoloader = $dir . '/vendor/autoload.php';
        $composerJson = $dir . '/composer.json';

        if (file_exists($autoloader) && file_exists($composerJson)) {
            $composer = json_decode(file_get_contents($composerJson), true);
            $isMini = ($composer['name'] ?? '') === 'fubber/mini';
            $requires = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);

            if ($isMini || isset($requires['fubber/mini'])) {
                $autoloaderPath = $autoloader;
                break;
            }
        }
        $dir = dirname($dir);
    }
}

if ($autoloaderPath === null) {
    fwrite(STDERR, "Error: Could not find Composer autoloader for a fubber/mini project\n");
    fwrite(STDERR, "Run this from within a project that uses fubber/mini\n");
    exit(1);
}

// Export useful paths for scripts that need them
$MINI_AUTOLOADER_PATH = $autoloaderPath;
$MINI_VENDOR_DIR = dirname($autoloaderPath);
$MINI_COMPOSER_DIR = $MINI_VENDOR_DIR . '/composer';

require_once $autoloaderPath;
