#!/usr/bin/env php
<?php
/**
 * Development Server Launcher
 *
 * Starts PHP's built-in development server with the correct document root.
 */

// Find composer autoload
function findAutoload(): ?string {
    $dir = __DIR__;
    while ($dir !== dirname($dir)) {
        $autoload = $dir . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            // Check if this is the project root vendor, not a nested vendor
            // Skip if we're inside vendor/fubber/mini/vendor (nested vendor directory)
            $vendorDir = dirname($autoload);
            $parentDir = dirname($vendorDir);

            // If parent of vendor is 'mini' and grandparent is 'fubber', skip this vendor
            if (basename($parentDir) === 'mini' && basename(dirname($parentDir)) === 'fubber') {
                $dir = dirname($dir);
                continue;
            }

            return $autoload;
        }
        $dir = dirname($dir);
    }
    return null;
}

$autoload = findAutoload();
if (!$autoload) {
    echo "Error: Could not find vendor/autoload.php\n";
    echo "Run: composer install\n";
    exit(1);
}

require_once $autoload;

// Determine document root
$root = \mini\Mini::$mini->root;
$docRoot = \mini\Mini::$mini->docRoot;

if (!$docRoot || !is_dir($docRoot)) {
    echo "Error: Document root not found\n";
    echo "Expected: $root/html/ or $root/public/\n";
    echo "\n";
    echo "Create document root:\n";
    echo "  mkdir $root/html\n";
    echo "  echo '<?php require_once __DIR__ . \"/../vendor/autoload.php\"; mini\\router();' > $root/html/index.php\n";
    exit(1);
}

// Parse command line options using ArgManager
$args = \mini\args()->nextCommand(); // Skip 'mini' to get 'serve'
$args = $args->withSupportedArgs('h', ['host:', 'port:', 'help']);

if (isset($args->opts['help']) || isset($args->opts['h'])) {
    echo "Mini Development Server\n";
    echo "\n";
    echo "Usage:\n";
    echo "  composer exec mini serve [options]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --host <host>    Server host (default: 127.0.0.1)\n";
    echo "  --port <port>    Server port (default: 8080)\n";
    echo "  --help, -h       Show this help\n";
    echo "\n";
    echo "Example:\n";
    echo "  composer exec mini serve --host 0.0.0.0 --port 3000\n";
    echo "\n";
    exit(0);
}

$host = $args->opts['host'] ?? '127.0.0.1';
$port = (int)($args->opts['port'] ?? 8080);

$address = "$host:$port";

echo "Mini Development Server\n";
echo "=======================\n";
echo "\n";
echo "Document root: $docRoot\n";
echo "Listening on:  http://$address\n";
echo "\n";
echo "Press Ctrl+C to stop\n";
echo "\n";

// Prefer pcntl_exec to replace process (cleaner, no hanging processes)
if (function_exists('pcntl_exec')) {
    $phpBinary = PHP_BINARY;
    $args = [
        '-S',
        $address,
        '-t',
        $docRoot
    ];

    // Replace current process with PHP server
    // Note: pcntl_exec sets argv[0] automatically, don't include it in $args
    pcntl_exec($phpBinary, $args);

    // If we reach here, exec failed - fall through to passthru
    echo "Warning: pcntl_exec failed, falling back to passthru\n";
}

// Fallback: use passthru (works without pcntl extension)
$command = sprintf(
    'php -S %s -t %s',
    escapeshellarg($address),
    escapeshellarg($docRoot)
);

passthru($command, $exitCode);
exit($exitCode);
