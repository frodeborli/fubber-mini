<?php

namespace mini\CLI;

/**
 * Command-line argument parser with subcommand support
 *
 * Provides an immutable, getopt-style interface for parsing CLI arguments with support
 * for subcommands, option validation, and positional arguments.
 *
 * # Features
 *
 * - **Subcommand support**: Parse nested command structures (git commit -m "message")
 * - **Short options**: `-v`, `-vvv`, `-i value`, `-ivalue`
 * - **Long options**: `--verbose`, `--match=pattern`, `--match pattern`
 * - **Option validation**: Required/optional values with automatic error handling
 * - **Positional arguments**: With minimum/maximum count validation
 * - **Immutable design**: All operations return new instances
 * - **Command delegation**: Easy pass-through to external tools
 * - **Two APIs**: Fluent declarative API or getopt-style `withSupportedArgs()`
 *
 * # Quick Start (Fluent API)
 *
 * The fluent API is more discoverable and self-documenting:
 *
 * ```php
 * // Command: myapp -vvv --input=file.txt --output
 * $args = mini\args()
 *     ->withFlag('v', 'verbose')
 *     ->withRequiredValue('i', 'input')
 *     ->withOptionalValue('o', 'output');
 *
 * $verbosity = $args->getFlag('verbose');   // 3
 * $input = $args->getOption('input');       // 'file.txt'
 * $output = $args->getOption('output');     // false (present, no value)
 * ```
 *
 * # Basic Usage (getopt-style)
 *
 * ## Simple Command with Options
 *
 * ```php
 * // Command: myapp -v --output=file.txt input.txt
 * $args = mini\args();
 * $args = $args->withSupportedArgs('v', ['output:'], 1); // 1 required arg
 *
 * $verbose = isset($args->opts['v']);           // true
 * $output = $args->opts['output'];              // 'file.txt'
 * $input = $args->args[0];                      // 'input.txt'
 * ```
 *
 * ## Subcommands
 *
 * ```php
 * // Command: git commit -m "Initial commit" --amend
 * $root = mini\args();
 * $command = $root->getCommand();               // 'git'
 *
 * $commit = $root->nextCommand();
 * $subcommand = $commit->getCommand();          // 'commit'
 *
 * $commit = $commit->withSupportedArgs('m:', ['amend']);
 * $message = $commit->opts['m'];                // 'Initial commit'
 * $amend = isset($commit->opts['amend']);       // true
 * ```
 *
 * ## Repeated Options
 *
 * ```php
 * // Command: myapp -vvv -e error -e warning
 * $args = mini\args();
 * $args = $args->withSupportedArgs('ve:', []);
 *
 * $verbosity = count($args->opts['v']);         // 3
 * $errors = $args->opts['e'];                   // ['error', 'warning']
 * ```
 *
 * # Option Syntax
 *
 * ## Short Options
 *
 * Format in `$short_opts` string:
 * - `v` - Boolean flag (no value)
 * - `i:` - Required value (`-i value` or `-ivalue`)
 * - `o::` - Optional value (`-o`, `-o value`, `-ovalue`)
 *
 * ```php
 * $args = $args->withSupportedArgs('vhi:o::', []);
 * // Supports: -v -h -i value -o optionalvalue
 * ```
 *
 * ## Long Options
 *
 * Format in `$long_opts` array:
 * - `verbose` - Boolean flag (no value)
 * - `input:` - Required value (`--input=file` or `--input file`)
 * - `output::` - Optional value (`--output`, `--output=file`, `--output file`)
 *
 * ```php
 * $args = $args->withSupportedArgs('', ['verbose', 'input:', 'output::']);
 * // Supports: --verbose --input=file.txt --output
 * ```
 *
 * # Positional Arguments
 *
 * ```php
 * // Require exactly 2 arguments
 * $args = $args->withSupportedArgs('', [], 2, 0);
 *
 * // Require 1, allow up to 3 more (4 total max)
 * $args = $args->withSupportedArgs('', [], 1, 3);
 *
 * // Require 1, allow unlimited additional
 * $args = $args->withSupportedArgs('', [], 1, 0);
 * ```
 *
 * # Command Delegation
 *
 * ```php
 * // Command: myapp -v proxy subcommand --flag arg1 arg2
 * $root = mini\args();
 * $root = $root->withSupportedArgs('v', []);
 *
 * if ($root->getCommand() === 'proxy') {
 *     $proxy = $root->nextCommand();
 *     $remaining = $proxy->getRemainingArgs(); // ['subcommand', '--flag', 'arg1', 'arg2']
 *
 *     // Pass to external tool
 *     passthru('external-tool ' . implode(' ', array_map('escapeshellarg', $remaining)));
 * }
 * ```
 *
 * # Stopping Option Parsing
 *
 * Use `--` to stop option parsing and treat everything after as positional arguments:
 *
 * ```php
 * // Command: myapp -v -- --not-an-option file.txt
 * $args = mini\args();
 * $args = $args->withSupportedArgs('v', []);
 *
 * $args->args; // ['--not-an-option', 'file.txt']
 * ```
 *
 * @see mini\args() Helper function to create root ArgManager instance
 */
class ArgManager
{
    /** @var int Next unparsed argument index */
    private int $next_index;

    /** @var array Parsed options (available after calling withSupportedArgs()) */
    public array $opts;

    /** @var array Parsed positional arguments (available after calling withSupportedArgs()) */
    public array $args;

    /** @var array<string, array{short: string, long: string, type: 'flag'|'required'|'optional'}> Declared options */
    private array $declared = [];

    /** @var array|null Cached parsed options */
    private ?array $parsedOpts = null;

    /**
     * Create a new ArgManager instance
     *
     * Typically you'll use the `mini\args()` helper function instead of constructing directly.
     * The constructor is primarily used internally for subcommand parsing.
     *
     * @param int $start_index Index in $_SERVER['argv'] where this command starts
     * @param ArgManager|null $parent Parent ArgManager for nested commands (internal use)
     *
     * @example
     * ```php
     * // Use helper function (recommended)
     * $args = mini\args();
     *
     * // Direct construction (not typical)
     * $args = new ArgManager(0);
     *
     * // For subcommand at index 2 (internal use)
     * $sub = new ArgManager(2, $parent);
     * ```
     */
    public function __construct(
        private readonly int $start_index = 0,
        private readonly ?ArgManager $parent = null
    ) {
        $this->next_index = $this->start_index + 1;
        $this->opts = [];
        $this->args = [];
    }

    /**
     * Declare a boolean flag option
     *
     * Flags don't take values. They can be repeated for counting (e.g., -vvv for verbosity level).
     * Both short and long forms are optional, but at least one must be provided.
     *
     * @param string|null $short Single-character short option (e.g., 'v' for -v), or null for long-only
     * @param string|null $long Long option name (e.g., 'verbose' for --verbose), or null for short-only
     * @return static New instance with flag declared
     * @throws \InvalidArgumentException If option already declared or both short and long are null
     *
     * @example
     * ```php
     * $args = $args
     *     ->withFlag('v', 'verbose')       // Both short and long
     *     ->withFlag('h', null)            // Short only
     *     ->withFlag(null, 'help')         // Long only
     *     ->withFlag(long: 'quiet');       // Named parameter
     *
     * // Command: myapp -vvv --help
     * $verbosity = $args->getFlag('verbose'); // 3
     * $help = $args->getFlag('help') > 0;     // true
     * ```
     */
    public function withFlag(?string $short = null, ?string $long = null): static
    {
        return $this->declareOption($short, $long, 'flag');
    }

    /**
     * Declare an option that requires a value
     *
     * Required value options must have a value provided. Supports both
     * short (-i value, -ivalue) and long (--input=value, --input value) forms.
     *
     * @param string|null $short Single-character short option (e.g., 'i' for -i), or null for long-only
     * @param string|null $long Long option name (e.g., 'input' for --input), or null for short-only
     * @return static New instance with option declared
     * @throws \InvalidArgumentException If option already declared or both short and long are null
     *
     * @example
     * ```php
     * $args = $args
     *     ->withRequiredValue('i', 'input')
     *     ->withRequiredValue(null, 'output')  // Long only
     *     ->withRequiredValue(short: 'f');      // Short only
     *
     * // Command: myapp -i file.txt --output=result.txt
     * $input = $args->getOption('input');   // 'file.txt'
     * $output = $args->getOption('output'); // 'result.txt'
     * ```
     */
    public function withRequiredValue(?string $short = null, ?string $long = null): static
    {
        return $this->declareOption($short, $long, 'required');
    }

    /**
     * Declare an option with an optional value
     *
     * Optional value options can be used with or without a value.
     * When used without a value, the option is present but has no associated value.
     *
     * @param string|null $short Single-character short option (e.g., 'o' for -o), or null for long-only
     * @param string|null $long Long option name (e.g., 'output' for --output), or null for short-only
     * @return static New instance with option declared
     * @throws \InvalidArgumentException If option already declared or both short and long are null
     *
     * @example
     * ```php
     * $args = $args
     *     ->withOptionalValue('o', 'output')
     *     ->withOptionalValue(long: 'verbose'); // Named parameter
     *
     * // Command: myapp -o file.txt
     * $output = $args->getOption('output'); // 'file.txt'
     *
     * // Command: myapp -o
     * $output = $args->getOption('output'); // false (option present, no value)
     *
     * // Command: myapp
     * $output = $args->getOption('output'); // null (option not present)
     * ```
     */
    public function withOptionalValue(?string $short = null, ?string $long = null): static
    {
        return $this->declareOption($short, $long, 'optional');
    }

    /**
     * Get the count of times a flag/option was provided
     *
     * Returns the number of times the option appears in the command line.
     * Works with both short and long forms of the option.
     *
     * @param string $name Short or long option name
     * @return int Count (0 if not present, 1+ if present)
     * @throws \RuntimeException If option not declared
     *
     * @example
     * ```php
     * $args = $args->withFlag('v', 'verbose');
     *
     * // Command: myapp -vvv
     * $verbosity = $args->getFlag('verbose'); // 3
     * $verbosity = $args->getFlag('v');       // 3 (same result)
     *
     * // Command: myapp
     * $verbosity = $args->getFlag('verbose'); // 0
     * ```
     */
    public function getFlag(string $name): int
    {
        $this->ensureParsed();
        $canonicalName = $this->resolveOptionName($name);

        if (!isset($this->parsedOpts[$canonicalName])) {
            return 0;
        }

        $value = $this->parsedOpts[$canonicalName];

        if (is_array($value)) {
            return count($value);
        }

        return 1;
    }

    /**
     * Get the value(s) of an option
     *
     * Returns the value or array of values for the option.
     * Works with both short and long forms of the option.
     *
     * @param string $name Short or long option name
     * @return string|false|(string|false)[]|null Single value, array of values, false (present without value), or null (not present)
     * @throws \RuntimeException If option not declared
     *
     * @example
     * ```php
     * $args = $args
     *     ->withRequiredValue('i', 'input')
     *     ->withFlag('v', 'verbose');
     *
     * // Command: myapp -i file.txt -vvv
     * $input = $args->getOption('input');     // 'file.txt'
     * $verbose = $args->getOption('v');       // [false, false, false]
     *
     * // Command: myapp -i a.txt -i b.txt
     * $inputs = $args->getOption('input');    // ['a.txt', 'b.txt']
     * ```
     */
    public function getOption(string $name): string|array|false|null
    {
        $this->ensureParsed();
        $canonicalName = $this->resolveOptionName($name);

        return $this->parsedOpts[$canonicalName] ?? null;
    }

    /**
     * Returns the command name at this context's starting position
     */
    public function getCommand(): ?string
    {
        return $_SERVER['argv'][$this->start_index] ?? null;
    }

    /**
     * Parse options and positional arguments for this command.
     * Returns a new instance with parsed results.
     *
     * @param string $short_opts Short options (e.g., "Fivwx", "v::")
     * @param array $long_opts Long options (e.g., ["match:", "limit::"])
     * @param int $required_args Minimum number of positional arguments required
     * @param int $optional_args Maximum number of additional optional arguments (0 = unlimited)
     * @return static New instance with parsed options and arguments
     * @throws \RuntimeException If required arguments are missing or option values are missing
     */
    public function withSupportedArgs(
        string $short_opts = '',
        array $long_opts = [],
        int $required_args = 0,
        int $optional_args = 0
    ): static {
        $argv = $_SERVER['argv'];
        $argc = count($argv);
        $opts = [];
        $i = $this->start_index + 1;
        $positional = [];

        // --- Parse options ---
        while ($i < $argc) {
            $arg = $argv[$i];

            // Explicit end of options
            if ($arg === '--') {
                $i++;
                break;
            }

            // --- Long options ---
            if (str_starts_with($arg, '--')) {
                $eq = strpos($arg, '=');
                $name = $eq === false ? substr($arg, 2) : substr($arg, 2, $eq - 2);
                $val = $eq === false ? null : substr($arg, $eq + 1);
                $matched = false;

                foreach ($long_opts as $opt) {
                    $base = rtrim($opt, ':');
                    $need = substr_count($opt, ':');
                    if ($name !== $base) continue;
                    $matched = true;

                    if ($need === 0) {
                        // No value
                        $this->addOpt($opts, $base, false);
                    } elseif ($need === 1) {
                        // Required value
                        if ($val === null) {
                            if ($i + 1 < $argc && !str_starts_with($argv[$i + 1], '-')) {
                                $val = $argv[++$i];
                            } else {
                                throw new \RuntimeException("--$base requires a value");
                            }
                        }
                        $this->addOpt($opts, $base, $val);
                    } else {
                        // Optional value
                        if ($val !== null) {
                            $this->addOpt($opts, $base, $val);
                        } elseif ($i + 1 < $argc && !str_starts_with($argv[$i + 1], '-')) {
                            $this->addOpt($opts, $base, $argv[++$i]);
                        } else {
                            $this->addOpt($opts, $base, false);
                        }
                    }
                    break;
                }
                if (!$matched) break; // Unknown option, stop parsing
                $i++;
                continue;
            }

            // --- Short options ---
            if (str_starts_with($arg, '-')) {
                $chars = substr($arg, 1);
                $len = strlen($chars);
                $parsed_all = true;

                for ($j = 0; $j < $len; $j++) {
                    $ch = $chars[$j];
                    $pos = strpos($short_opts, $ch);
                    if ($pos === false) {
                        $parsed_all = false;
                        break;
                    }

                    $takes = isset($short_opts[$pos + 1]) && $short_opts[$pos + 1] === ':';
                    $optional = isset($short_opts[$pos + 2]) && $short_opts[$pos + 2] === ':';

                    if (!$takes) {
                        $this->addOpt($opts, $ch, false);
                        continue;
                    }

                    // Option with value
                    $rest = substr($chars, $j + 1);
                    if ($rest !== '') {
                        // Rest of arg is the value
                        $this->addOpt($opts, $ch, $rest);
                        break;
                    }

                    if (!$optional) {
                        // Required value
                        if ($i + 1 < $argc && !str_starts_with($argv[$i + 1], '-')) {
                            $this->addOpt($opts, $ch, $argv[++$i]);
                        } else {
                            throw new \RuntimeException("-$ch requires a value");
                        }
                    } elseif ($i + 1 < $argc && !str_starts_with($argv[$i + 1], '-')) {
                        // Optional value available
                        $this->addOpt($opts, $ch, $argv[++$i]);
                    } else {
                        // Optional value not provided
                        $this->addOpt($opts, $ch, false);
                    }
                    break;
                }

                if (!$parsed_all) {
                    break; // Unknown option, stop parsing
                }

                $i++;
                continue;
            }

            // --- Positional argument ---
            $positional[] = $arg;
            $i++;
        }

        // --- Validate required arguments ---
        if (count($positional) < $required_args) {
            throw new \RuntimeException(
                "Expected at least $required_args argument(s), got " . count($positional)
            );
        }

        // --- Validate optional arguments limit ---
        if ($optional_args > 0) {
            $max_args = $required_args + $optional_args;
            if (count($positional) > $max_args) {
                throw new \RuntimeException(
                    "Expected at most $max_args argument(s), got " . count($positional)
                );
            }
        }

        $clone = clone $this;
        $clone->opts = $opts;
        $clone->args = $positional;
        $clone->next_index = $i;
        return $clone;
    }

    /**
     * Parse and return options without modifying state
     *
     * Use this to query options. Does not validate or advance position.
     *
     * @param string $short_opts Short options (e.g., "v", "Fi")
     * @param array $long_opts Long options (e.g., ["verbose", "match:"])
     * @return array Parsed options
     */
    public function getopt(string $short_opts = '', array $long_opts = []): array
    {
        $argv = $_SERVER['argv'];
        $argc = count($argv);
        $opts = [];
        $i = $this->start_index + 1;

        while ($i < $argc) {
            $arg = $argv[$i];

            // Stop at explicit end of options
            if ($arg === '--') {
                break;
            }

            // Stop at positional arguments
            if (!str_starts_with($arg, '-')) {
                break;
            }

            // --- Long options ---
            if (str_starts_with($arg, '--')) {
                $eq = strpos($arg, '=');
                $name = $eq === false ? substr($arg, 2) : substr($arg, 2, $eq - 2);
                $val = $eq === false ? null : substr($arg, $eq + 1);

                foreach ($long_opts as $opt) {
                    $base = rtrim($opt, ':');
                    $need = substr_count($opt, ':');
                    if ($name !== $base) continue;

                    if ($need === 0) {
                        $this->addOpt($opts, $base, false);
                    } elseif ($need === 1) {
                        if ($val === null && isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                            $val = $argv[++$i];
                        }
                        $this->addOpt($opts, $base, $val ?? '');
                    } else {
                        if ($val !== null) {
                            $this->addOpt($opts, $base, $val);
                        } elseif (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                            $this->addOpt($opts, $base, $argv[++$i]);
                        } else {
                            $this->addOpt($opts, $base, false);
                        }
                    }
                    break;
                }
                $i++;
                continue;
            }

            // --- Short options ---
            if (str_starts_with($arg, '-')) {
                $chars = substr($arg, 1);
                $len = strlen($chars);

                for ($j = 0; $j < $len; $j++) {
                    $ch = $chars[$j];
                    $pos = strpos($short_opts, $ch);
                    if ($pos === false) {
                        break 2; // Unknown option, stop parsing
                    }

                    $takes = isset($short_opts[$pos + 1]) && $short_opts[$pos + 1] === ':';
                    $optional = isset($short_opts[$pos + 2]) && $short_opts[$pos + 2] === ':';

                    if (!$takes) {
                        $this->addOpt($opts, $ch, false);
                        continue;
                    }

                    // Option with value
                    $rest = substr($chars, $j + 1);
                    if ($rest !== '') {
                        $this->addOpt($opts, $ch, $rest);
                        break 2;
                    }

                    if (!$optional && isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                        $this->addOpt($opts, $ch, $argv[++$i]);
                    } elseif ($optional && isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                        $this->addOpt($opts, $ch, $argv[++$i]);
                    } else {
                        $this->addOpt($opts, $ch, $optional ? false : '');
                    }
                    break;
                }
                $i++;
                continue;
            }
        }

        return $opts;
    }

    /**
     * Returns all remaining arguments from current position (unparsed)
     *
     * Useful for delegating to external commands that will parse their own arguments.
     * Example: fubber php <remaining args passed to fubber-php>
     */
    public function getRemainingArgs(): array
    {
        return array_slice($_SERVER['argv'], $this->next_index);
    }

    /**
     * Returns a new ArgManager for the next subcommand after parsing current options
     */
    public function nextCommand(): ?ArgManager
    {
        return isset($_SERVER['argv'][$this->next_index])
            ? new self($this->next_index, $this)
            : null;
    }

    /**
     * Helper to add option values, handling repeated options as arrays
     */
    private function addOpt(array &$opts, string $key, mixed $val): void
    {
        if (array_key_exists($key, $opts)) {
            if (!is_array($opts[$key])) {
                $opts[$key] = [$opts[$key]];
            }
            $opts[$key][] = $val;
        } else {
            $opts[$key] = $val;
        }
    }

    /**
     * Declare an option and return new instance
     *
     * @param string|null $short Short option name
     * @param string|null $long Long option name
     * @param 'flag'|'required'|'optional' $type Option type
     * @return static New instance with option declared
     * @throws \InvalidArgumentException If option already declared or both names are null/empty
     */
    private function declareOption(?string $short, ?string $long, string $type): static
    {
        // Normalize null to empty string
        $short = $short ?? '';
        $long = $long ?? '';

        if ($short === '' && $long === '') {
            throw new \InvalidArgumentException('At least one of short or long option name must be provided');
        }

        // Check if either short or long name already declared
        foreach ($this->declared as $key => $decl) {
            if ($short !== '' && $decl['short'] === $short) {
                throw new \InvalidArgumentException("Short option '-{$short}' already declared");
            }
            if ($long !== '' && $decl['long'] === $long) {
                throw new \InvalidArgumentException("Long option '--{$long}' already declared");
            }
        }

        $clone = clone $this;
        // Use long name as key if available, otherwise short
        $key = $long !== '' ? $long : $short;
        $clone->declared[$key] = ['short' => $short, 'long' => $long, 'type' => $type];
        $clone->parsedOpts = null; // Invalidate cache
        return $clone;
    }

    /**
     * Resolve option name to canonical form (prefer long name)
     *
     * @param string $name Short or long option name
     * @return string Canonical option name
     * @throws \RuntimeException If option not declared
     */
    private function resolveOptionName(string $name): string
    {
        // Direct lookup (long name)
        if (isset($this->declared[$name])) {
            return $name;
        }

        // Search by short or long name
        foreach ($this->declared as $key => $decl) {
            if ($decl['short'] === $name || $decl['long'] === $name) {
                return $key;
            }
        }

        throw new \RuntimeException("Option '{$name}' not declared. Use withFlag(), withRequiredValue(), or withOptionalValue() first.");
    }

    /**
     * Ensure options are parsed, parsing if necessary
     *
     * Also updates next_index to track where parsing stopped
     */
    private function ensureParsed(): void
    {
        if ($this->parsedOpts !== null) {
            return;
        }

        // If no options declared, just parse nothing and set index
        if (empty($this->declared)) {
            $this->parsedOpts = [];
            // next_index stays at start_index + 1 (no options to skip)
            return;
        }

        // Build getopt-style arguments from declared options
        $shortOpts = '';
        $longOpts = [];

        foreach ($this->declared as $decl) {
            if ($decl['short'] !== '') {
                $shortOpts .= $decl['short'];
                if ($decl['type'] === 'required') {
                    $shortOpts .= ':';
                } elseif ($decl['type'] === 'optional') {
                    $shortOpts .= '::';
                }
            }

            if ($decl['long'] !== '') {
                $longOpt = $decl['long'];
                if ($decl['type'] === 'required') {
                    $longOpt .= ':';
                } elseif ($decl['type'] === 'optional') {
                    $longOpt .= '::';
                }
                $longOpts[] = $longOpt;
            }
        }

        // Parse using existing getopt method and track position
        $result = $this->parseWithTracking($shortOpts, $longOpts);
        $this->parsedOpts = $result['opts'];
        $this->next_index = $result['next_index'];

        // Normalize to canonical names (prefer long names)
        $normalized = [];
        foreach ($this->parsedOpts as $key => $value) {
            $canonicalName = $this->resolveOptionName($key);
            $normalized[$canonicalName] = $value;
        }
        $this->parsedOpts = $normalized;
    }

    /**
     * Parse options and return both options and the index where parsing stopped
     *
     * Similar to getopt() but tracks the ending position
     *
     * @param string $short_opts Short options
     * @param array $long_opts Long options
     * @return array{opts: array, next_index: int}
     */
    private function parseWithTracking(string $short_opts, array $long_opts): array
    {
        $argv = $_SERVER['argv'];
        $argc = count($argv);
        $opts = [];
        $i = $this->start_index + 1;

        while ($i < $argc) {
            $arg = $argv[$i];

            // Stop at explicit end of options
            if ($arg === '--') {
                $i++;
                break;
            }

            // Stop at positional arguments
            if (!str_starts_with($arg, '-')) {
                break;
            }

            // --- Long options ---
            if (str_starts_with($arg, '--')) {
                $eq = strpos($arg, '=');
                $name = $eq === false ? substr($arg, 2) : substr($arg, 2, $eq - 2);
                $val = $eq === false ? null : substr($arg, $eq + 1);
                $matched = false;

                foreach ($long_opts as $opt) {
                    $base = rtrim($opt, ':');
                    $need = substr_count($opt, ':');
                    if ($name !== $base) continue;
                    $matched = true;

                    if ($need === 0) {
                        $this->addOpt($opts, $base, false);
                    } elseif ($need === 1) {
                        if ($val === null && isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                            $val = $argv[++$i];
                        }
                        $this->addOpt($opts, $base, $val ?? '');
                    } else {
                        if ($val !== null) {
                            $this->addOpt($opts, $base, $val);
                        } elseif (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                            $this->addOpt($opts, $base, $argv[++$i]);
                        } else {
                            $this->addOpt($opts, $base, false);
                        }
                    }
                    break;
                }

                if (!$matched) break; // Unknown option, stop parsing
                $i++;
                continue;
            }

            // --- Short options ---
            if (str_starts_with($arg, '-')) {
                $chars = substr($arg, 1);
                $len = strlen($chars);
                $parsed_all = true;

                for ($j = 0; $j < $len; $j++) {
                    $ch = $chars[$j];
                    $pos = strpos($short_opts, $ch);
                    if ($pos === false) {
                        $parsed_all = false;
                        break;
                    }

                    $takes = isset($short_opts[$pos + 1]) && $short_opts[$pos + 1] === ':';
                    $optional = isset($short_opts[$pos + 2]) && $short_opts[$pos + 2] === ':';

                    if (!$takes) {
                        $this->addOpt($opts, $ch, false);
                        continue;
                    }

                    // Option with value
                    $rest = substr($chars, $j + 1);
                    if ($rest !== '') {
                        $this->addOpt($opts, $ch, $rest);
                        break;
                    }

                    if (!$optional && isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                        $this->addOpt($opts, $ch, $argv[++$i]);
                    } elseif ($optional && isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                        $this->addOpt($opts, $ch, $argv[++$i]);
                    } else {
                        $this->addOpt($opts, $ch, $optional ? false : '');
                    }
                    break;
                }

                if (!$parsed_all) break; // Unknown option, stop parsing
                $i++;
                continue;
            }
        }

        return ['opts' => $opts, 'next_index' => $i];
    }
}
