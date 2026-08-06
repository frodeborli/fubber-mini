#!/usr/bin/env php
<?php

/**
 * Mini Framework CLI — thin dispatcher.
 *
 * Subcommands are discovered via the `extra.mini.commands` section of each
 * package's composer.json:
 *
 *   "extra": {
 *     "mini": {
 *       "commands": {
 *         "<name>": {
 *           "script": "bin/...php",
 *           "description": "One-line help text"
 *         }
 *       }
 *     }
 *   }
 *
 * Sources, in order of precedence (host wins on name collision):
 *   1. The host project's composer.json
 *   2. Each installed package, via vendor/composer/installed.json
 *
 * The dispatcher itself does not load Composer's autoloader or any
 * framework class — it only reads JSON and execs the chosen script
 * with a fresh PHP process.
 */

$argv0   = array_shift($argv);
$cmdName = array_shift($argv);

$hostRoot = findHostRoot(getcwd());
if ($hostRoot === null) {
    fwrite(STDERR, "mini: could not find host composer.json by walking up from " . getcwd() . "\n");
    exit(1);
}

$commands = discoverCommands($hostRoot);

// `mini help <command>` is sugar for `mini <command> --help`.
if ($cmdName === 'help' && isset($argv[0]) && isset($commands[$argv[0]])) {
    $cmdName = array_shift($argv);
    $argv[] = '--help';
}

if ($cmdName === null || $cmdName === 'help' || $cmdName === '--help' || $cmdName === '-h') {
    showHelp($commands);
    exit(0);
}

if (!isset($commands[$cmdName])) {
    fwrite(STDERR, "mini: unknown command '$cmdName'\n");
    suggestSimilar($cmdName, array_keys($commands));
    fwrite(STDERR, "Run 'mini' to see available commands.\n");
    exit(1);
}

$cmd = $commands[$cmdName];
if (!is_file($cmd['script_path'])) {
    fwrite(STDERR, "mini: script not found for command '$cmdName': {$cmd['script_path']}\n");
    fwrite(STDERR, "Declared by package: {$cmd['package']}\n");
    exit(1);
}

// Dispatch via proc_open with FD pass-through so TTY behavior (interactive
// prompts, paging, color) is preserved exactly as if the script were invoked
// directly. We then poll for child exit (rather than blocking inside
// proc_close) so that signal handlers can dispatch between iterations and
// be forwarded to the child — see the comment near pcntl_signal_dispatch
// in the loop below for why a poll loop is required here.
$childArgv = ['php', $cmd['script_path'], ...$argv];
$process = proc_open($childArgv, [STDIN, STDOUT, STDERR], $pipes);
if (!is_resource($process)) {
    fwrite(STDERR, "mini: failed to execute '$cmdName'\n");
    exit(1);
}

$pcntlAvailable = function_exists('pcntl_signal') && function_exists('pcntl_signal_dispatch');
if ($pcntlAvailable) {
    // Forward common termination signals to the child instead of letting the
    // dispatcher die first and orphan the subcommand. This matters for
    // explicit `kill -TERM <dispatcher>` invocations; terminal Ctrl+C already
    // reaches the child via process-group delivery, but forwarding handles
    // that case too without complication.
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

// Poll for child exit. PHP does not dispatch signal handlers while blocked
// inside proc_close's internal waitpid, so a blocking proc_close would
// swallow signals that arrive after the call starts. The poll loop yields
// PHP-level opcodes between iterations where pcntl_signal_dispatch can run
// any pending handler, which then forwards the signal to the child.
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

// ---------------------------------------------------------------------------

function findHostRoot(string $cwd): ?string
{
    $dir = $cwd;
    while ($dir !== '/' && $dir !== '') {
        if (is_file("$dir/composer.json") && !str_contains($dir, '/vendor/')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return null;
}

function discoverCommands(string $hostRoot): array
{
    $commands = [];

    // 1. Host project's own composer.json (highest precedence)
    $hostPath = "$hostRoot/composer.json";
    if (is_file($hostPath)) {
        $host = json_decode((string) file_get_contents($hostPath), true) ?? [];
        $hostName = $host['name'] ?? '(host)';
        foreach (($host['extra']['mini']['commands'] ?? []) as $name => $defn) {
            $cmd = normalizeCommand($name, $defn, $hostRoot, $hostName);
            if ($cmd !== null) {
                $commands[$name] = $cmd;
            }
        }
    }

    // 2. Installed packages. Use installed.json only to locate each package;
    //    read each package's actual composer.json for up-to-date extra metadata
    //    (the extra snapshot in installed.json only refreshes on composer
    //    install/update, which makes dev-time edits invisible).
    $installedPath = "$hostRoot/vendor/composer/installed.json";
    if (is_file($installedPath)) {
        $installed = json_decode((string) file_get_contents($installedPath), true) ?? [];
        $composerDir = dirname($installedPath); // vendor/composer
        foreach (($installed['packages'] ?? []) as $pkg) {
            $pkgName = $pkg['name'] ?? '?';
            $installPath = realpath($composerDir . '/' . ($pkg['install-path'] ?? ''));
            if (!$installPath) {
                continue;
            }
            $pkgComposerPath = "$installPath/composer.json";
            if (!is_file($pkgComposerPath)) {
                continue;
            }
            $pkgComposer = json_decode((string) file_get_contents($pkgComposerPath), true) ?? [];
            foreach (($pkgComposer['extra']['mini']['commands'] ?? []) as $name => $defn) {
                if (isset($commands[$name])) {
                    continue; // host wins
                }
                $cmd = normalizeCommand($name, $defn, $installPath, $pkgName);
                if ($cmd !== null) {
                    $commands[$name] = $cmd;
                }
            }
        }
    }

    ksort($commands);
    return $commands;
}

function normalizeCommand(string $name, mixed $defn, string $packageDir, string $packageName): ?array
{
    if (!is_array($defn) || !isset($defn['script']) || !is_string($defn['script'])) {
        return null;
    }
    return [
        'name'        => $name,
        'description' => (string) ($defn['description'] ?? ''),
        'script_path' => $packageDir . '/' . ltrim($defn['script'], '/'),
        'package'     => $packageName,
    ];
}

function showHelp(array $commands): void
{
    echo "Mini — a forkable core PHP framework (fubber/mini)\n";
    echo "==================================================\n\n";
    echo "Zero-dependency building blocks meant to be built upon — or forked\n";
    echo "outright and owned for a decade. This CLI is the framework's toolbox.\n\n";
    echo "Usage:\n";
    echo "  mini <command> [arguments]\n";
    echo "  mini help <command>          Same as: mini <command> --help\n\n";

    if (!$commands) {
        echo "No commands registered.\n\n";
        echo "Packages contribute commands via composer.json:\n";
        echo "  extra.mini.commands.<name> = { script: <path>, description: <text> }\n";
        return;
    }

    echo "Commands:\n";
    $maxName = max(array_map('strlen', array_keys($commands)));
    $maxDesc = max(array_map(fn($c) => strlen($c['description']), $commands));
    foreach ($commands as $name => $cmd) {
        printf("  %-{$maxName}s  %-{$maxDesc}s  [%s]\n",
            $name,
            $cmd['description'] !== '' ? $cmd['description'] : '(no description)',
            $cmd['package']
        );
    }
    echo "\nCommands are discovered from composer.json (extra.mini.commands) of the\n";
    echo "host project and every installed package. The host wins on name collision;\n";
    echo "the providing package is shown in brackets above.\n\n";
    echo "Run 'mini <command> --help' for usage, options and examples.\n";
}

function suggestSimilar(string $missing, array $available): void
{
    $similar = [];
    foreach ($available as $name) {
        $dist = levenshtein($missing, $name);
        if ($dist <= 3) {
            $similar[$name] = $dist;
        }
    }
    if (!$similar) {
        return;
    }
    asort($similar);
    $top = array_slice(array_keys($similar), 0, 3);
    fwrite(STDERR, "Did you mean: " . implode(', ', $top) . "?\n");
}
