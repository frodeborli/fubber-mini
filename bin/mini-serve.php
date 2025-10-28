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

// Parse command line options
$host = '127.0.0.1';
$port = 8080;

foreach ($argv as $i => $arg) {
    if ($arg === '--host' && isset($argv[$i + 1])) {
        $host = $argv[$i + 1];
    } elseif ($arg === '--port' && isset($argv[$i + 1])) {
        $port = (int)$argv[$i + 1];
    } elseif ($arg === '--help' || $arg === '-h') {
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
}

$address = "$host:$port";

echo "Mini Development Server\n";
echo "=======================\n";
echo "\n";
echo "Document root: $docRoot\n";
echo "Listening on:  http://$address\n";
echo "\n";
echo "Press Ctrl+C to stop\n";
echo "\n";

// Start PHP built-in server
$command = sprintf(
    'php -S %s -t %s',
    escapeshellarg($address),
    escapeshellarg($docRoot)
);

passthru($command, $exitCode);
exit($exitCode);
