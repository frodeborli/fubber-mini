#!/usr/bin/env php
<?php
/**
 * Development Server Launcher
 *
 * Starts PHP's built-in development server with the correct document root.
 */

require __DIR__ . '/../ensure-autoloader.php';

use mini\CLI\ArgManager;

mini\bootstrap();

mini\args(ArgManager::parse($argv)
    ->withFlag('h', 'help')
    ->withRequiredValue(null, 'host')
    ->withRequiredValue(null, 'port')
);

if (mini\args()->getFlag('help')) {
    echo <<<TXT
Mini Development Server

Usage:
  mini serve [options]

Options:
  --host <host>    Server host (default: 127.0.0.1)
  --port <port>    Server port (default: 8080)
  --help, -h       Show this help

Example:
  mini serve --host 0.0.0.0 --port 3000

TXT;
    exit(0);
}

// Determine document root
$root = mini\Mini::$mini->root;
$docRoot = mini\Mini::$mini->docRoot;

if (!$docRoot || !is_dir($docRoot)) {
    fwrite(STDERR, "Error: document root not found\n");
    fwrite(STDERR, "Expected: $root/html/ or $root/public/\n\n");
    fwrite(STDERR, "Create document root:\n");
    fwrite(STDERR, "  mkdir $root/html\n");
    fwrite(STDERR, "  echo '<?php require_once __DIR__ . \"/../vendor/autoload.php\"; mini\\\\dispatch();' > $root/html/index.php\n");
    exit(1);
}

$host = mini\args()->getOption('host') ?: '127.0.0.1';
$port = (int) (mini\args()->getOption('port') ?: 8080);
$address = "$host:$port";

// Use STDERR for the banner so it shows up even when STDOUT is redirected
// (pcntl_exec replaces the process image, and any buffered STDOUT output
// is dropped). STDERR is unbuffered.
fwrite(STDERR, <<<TXT
Mini Development Server
=======================

Document root: $docRoot
Listening on:  http://$address

Press Ctrl+C to stop


TXT);

// Preferred: pcntl_exec replaces the parent process with `php -S`. No child
// management or signal forwarding needed — the user's Ctrl+C goes straight
// to the PHP server, and there is no parent left to leave dangling.
if (function_exists('pcntl_exec')) {
    pcntl_exec(PHP_BINARY, ['-S', $address, '-t', $docRoot]);
    // Only reached if pcntl_exec failed (very rare).
    fwrite(STDERR, "Warning: pcntl_exec failed, falling back to proc_open\n");
}

// Fallback: spawn the server as a child and forward termination signals so
// that Ctrl+C / SIGTERM / SIGHUP cleanly stop the child instead of leaving
// it orphaned.
$process = proc_open(
    [PHP_BINARY, '-S', $address, '-t', $docRoot],
    [STDIN, STDOUT, STDERR],
    $pipes
);

if (!is_resource($process)) {
    fwrite(STDERR, "Failed to start PHP development server\n");
    exit(1);
}

$pcntlAvailable = function_exists('pcntl_signal') && function_exists('pcntl_signal_dispatch');

if ($pcntlAvailable) {
    $forward = function (int $signal) use (&$process): void {
        if (is_resource($process)) {
            $status = proc_get_status($process);
            if ($status['running'] ?? false) {
                proc_terminate($process, $signal);
            }
        }
    };
    pcntl_signal(SIGINT, $forward);
    pcntl_signal(SIGTERM, $forward);
    pcntl_signal(SIGHUP, $forward);
}

// Poll for child exit. We do NOT use proc_close() to wait, because PHP does
// not dispatch async signal handlers while blocked inside proc_close's
// waitpid call — handlers would only run after the wait returns, which is
// too late to forward the signal. Polling with explicit
// pcntl_signal_dispatch() lets handlers fire between iterations.
$exitCode = 0;
while (true) {
    if ($pcntlAvailable) {
        pcntl_signal_dispatch();
    }
    $status = proc_get_status($process);
    if (!$status || !$status['running']) {
        $exitCode = $status['exitcode'] ?? -1;
        break;
    }
    usleep(100_000); // 100 ms
}
proc_close($process);
exit($exitCode);
