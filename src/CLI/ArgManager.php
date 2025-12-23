<?php

namespace mini\CLI;

/**
 * Command-line argument parser with subcommand support
 *
 * Provides an immutable, fluent interface for parsing CLI arguments with support
 * for subcommands, option validation, and positional arguments.
 *
 * # Features
 *
 * - **Subcommand support**: Parse nested command structures (git commit -m "message")
 * - **Short options**: `-v`, `-vvv`, `-i value`, `-ivalue`
 * - **Long options**: `--verbose`, `--match=pattern`, `--match pattern`
 * - **Unified short/long**: Query by either name, get same result
 * - **Option validation**: Required/optional values with automatic error handling
 * - **Immutable design**: All operations return new instances
 * - **Command delegation**: Easy pass-through to external tools
 *
 * # Quick Start
 *
 * ```php
 * // Simple command: myapp -v --output=file.txt
 * $args = mini\args(
 *     (new ArgManager())
 *         ->withFlag('v', 'verbose')
 *         ->withRequiredValue('o', 'output')
 * );
 *
 * if ($args->getUnparsedArgs()) {
 *     die("Unexpected: " . implode(', ', $args->getUnparsedArgs()));
 * }
 *
 * $verbosity = $args->getFlag('verbose');
 * $output = $args->getOption('output');
 * ```
 *
 * # Subcommands
 *
 * ```php
 * // myapp -v run --fast target
 * $args = mini\args(
 *     (new ArgManager())
 *         ->withFlag('v', 'verbose')
 *         ->withSubcommand('run', 'build', 'test')
 * );
 *
 * if ($args->getUnparsedArgs()) {
 *     die("Unexpected: " . implode(', ', $args->getUnparsedArgs()));
 * }
 *
 * $sub = $args->nextCommand();
 * if ($sub?->getCommand() === 'run') {
 *     $run = $sub->withFlag(null, 'fast');
 *     $target = $run->getUnparsedArgs()[0] ?? null;
 * }
 * ```
 *
 * # Stopping Option Parsing
 *
 * Use `--` to stop option parsing and treat everything after as unparsed arguments:
 *
 * ```php
 * // myapp -v -- --not-an-option file.txt
 * $args = mini\args((new ArgManager())->withFlag('v', 'verbose'));
 * $args->getUnparsedArgs(); // ['--not-an-option', 'file.txt']
 * ```
 *
 * @see mini\args() Helper function to configure and access ArgManager
 */
class ArgManager
{
    /** @var int Next unparsed argument index */
    private int $next_index;

    /** @var array<string, array{short: string, long: string, type: 'flag'|'required'|'optional'}> Declared options */
    private array $declared = [];

    /** @var array<string, string> Map short names to canonical (long) names */
    private array $shortToCanonical = [];

    /** @var array<string> Declared subcommands */
    private array $subcommands = [];

    /** @var array|null Cached parsed options (canonical names) */
    private ?array $parsedOpts = null;

    /** @var array|null Cached unparsed arguments */
    private ?array $unparsedArgs = null;

    /** @var string|null Matched subcommand (if any) */
    private ?string $matchedSubcommand = null;

    /** @var array|null Custom argv array (null = use $_SERVER['argv']) */
    private ?array $customArgv = null;

    /**
     * Create a new ArgManager instance
     *
     * @param int $start_index Index in argv where this command starts (default: 0)
     */
    public function __construct(
        private readonly int $start_index = 0
    ) {
        $this->next_index = $this->start_index + 1;
    }

    /**
     * Create an ArgManager from a custom argv array
     *
     * Use this for parsing command strings in REPLs or testing.
     *
     * ```php
     * // Parse a REPL command line
     * $args = ArgManager::parse(['schema', '--verbose', 'users'])
     *     ->withFlag('v', 'verbose')
     *     ->withSubcommand('users', 'orders');
     *
     * $args->getCommand();      // 'schema'
     * $args->getFlag('verbose'); // 1
     * $args->nextCommand();      // ArgManager for 'users'
     * ```
     *
     * @param array $argv Array of arguments (like $_SERVER['argv'])
     * @return static New ArgManager instance parsing the given array
     */
    public static function parse(array $argv): static
    {
        $instance = new static(0);
        $instance->customArgv = array_values($argv); // Ensure 0-indexed
        return $instance;
    }

    /**
     * Get the argv array to parse
     */
    private function getArgv(): array
    {
        return $this->customArgv ?? $_SERVER['argv'] ?? [];
    }

    /**
     * Declare a boolean flag option
     *
     * Flags don't take values. They can be repeated for counting (e.g., -vvv for verbosity level).
     *
     * @param string|null $short Single-character short option (e.g., 'v' for -v)
     * @param string|null $long Long option name (e.g., 'verbose' for --verbose)
     * @return static New instance with flag declared
     * @throws \InvalidArgumentException If option already declared or both are null
     *
     * @example
     * ```php
     * $args = $args->withFlag('v', 'verbose');
     * // Command: myapp -vvv
     * $verbosity = $args->getFlag('verbose'); // 3
     * ```
     */
    public function withFlag(?string $short = null, ?string $long = null): static
    {
        return $this->declareOption($short, $long, 'flag');
    }

    /**
     * Declare an option that requires a value
     *
     * @param string|null $short Single-character short option
     * @param string|null $long Long option name
     * @return static New instance with option declared
     *
     * @example
     * ```php
     * $args = $args->withRequiredValue('i', 'input');
     * // Command: myapp -i file.txt  OR  myapp --input=file.txt
     * $input = $args->getOption('input'); // 'file.txt'
     * ```
     */
    public function withRequiredValue(?string $short = null, ?string $long = null): static
    {
        return $this->declareOption($short, $long, 'required');
    }

    /**
     * Declare an option with an optional value
     *
     * @param string|null $short Single-character short option
     * @param string|null $long Long option name
     * @param string|null $default Default value when option is present without a value
     * @return static New instance with option declared
     *
     * @example
     * ```php
     * $args = $args->withOptionalValue('l', 'log', '/var/log/app.log');
     * // Command: myapp              → getOption('log') = null
     * // Command: myapp --log        → getOption('log') = '/var/log/app.log'
     * // Command: myapp --log=other  → getOption('log') = 'other'
     * ```
     */
    public function withOptionalValue(?string $short = null, ?string $long = null, ?string $default = null): static
    {
        return $this->declareOption($short, $long, 'optional', $default);
    }

    /**
     * Declare valid subcommands
     *
     * When a declared subcommand is encountered, it's consumed and available via nextCommand().
     * Undeclared subcommands or unknown options end up in getUnparsedArgs().
     *
     * @param string ...$subcommands Valid subcommand names
     * @return static New instance with subcommands declared
     *
     * @example
     * ```php
     * $args = $args->withSubcommand('run', 'build', 'test');
     *
     * if ($args->getUnparsedArgs()) {
     *     die("Unexpected: " . implode(', ', $args->getUnparsedArgs()));
     * }
     *
     * $sub = $args->nextCommand();
     * match ($sub?->getCommand()) {
     *     'run' => handleRun($sub),
     *     'build' => handleBuild($sub),
     *     'test' => handleTest($sub),
     *     null => showHelp(),
     * };
     * ```
     */
    public function withSubcommand(string ...$subcommands): static
    {
        $clone = clone $this;
        $clone->subcommands = array_merge($clone->subcommands, $subcommands);
        $clone->parsedOpts = null;
        $clone->unparsedArgs = null;
        $clone->matchedSubcommand = null;
        return $clone;
    }

    /**
     * Get the count of times a flag was provided
     *
     * @param string $name Short or long option name
     * @return int Count (0 if not present)
     * @throws \RuntimeException If option not declared
     */
    public function getFlag(string $name): int
    {
        $this->ensureParsed();
        $canonical = $this->resolveToCanonical($name);

        $value = $this->parsedOpts[$canonical] ?? null;
        if ($value === null) {
            return 0;
        }
        if (is_array($value)) {
            return count($value);
        }
        return 1;
    }

    /**
     * Get the value of an option
     *
     * @param string $name Short or long option name
     * @return string|false|array|null Value, false (present without value and no default), array (repeated), or null (absent)
     * @throws \RuntimeException If option not declared
     */
    public function getOption(string $name): string|array|false|null
    {
        $this->ensureParsed();
        $canonical = $this->resolveToCanonical($name);

        $value = $this->parsedOpts[$canonical] ?? null;

        // Apply default for optional value options present without a value
        if ($value === false) {
            $default = $this->declared[$canonical]['default'] ?? null;
            if ($default !== null) {
                return $default;
            }
        }

        return $value;
    }

    /**
     * Check if an option was provided
     *
     * @param string $name Short or long option name
     * @return bool True if option was present on command line
     * @throws \RuntimeException If option not declared
     */
    public function hasOption(string $name): bool
    {
        return $this->getOption($name) !== null;
    }

    /**
     * Get unparsed arguments (unknown options, undeclared subcommands, or args after --)
     *
     * For simple commands without subcommands, check this to detect invalid input:
     * ```php
     * if ($args->getUnparsedArgs()) {
     *     die("Unexpected: " . implode(', ', $args->getUnparsedArgs()));
     * }
     * ```
     *
     * @return array<string> Unparsed arguments
     */
    public function getUnparsedArgs(): array
    {
        $this->ensureParsed();
        return $this->unparsedArgs;
    }

    /**
     * Get the command name at this context's starting position
     *
     * @return string|null Command name or null if argv is empty
     */
    public function getCommand(): ?string
    {
        return $this->getArgv()[$this->start_index] ?? null;
    }

    /**
     * Get a new ArgManager for the next subcommand
     *
     * Only returns a subcommand if it was declared via withSubcommand().
     * Unknown positional arguments go to getUnparsedArgs() instead.
     *
     * @return ArgManager|null New ArgManager at subcommand position, or null if none
     */
    public function nextCommand(): ?ArgManager
    {
        $this->ensureParsed();

        if ($this->matchedSubcommand === null) {
            return null;
        }

        $child = new self($this->next_index);
        $child->customArgv = $this->customArgv; // Inherit custom argv
        return $child;
    }

    /**
     * Get all remaining arguments from current position (unparsed)
     *
     * Useful for delegating to external commands. If the remaining args
     * start with '--', it is stripped since it was meant to stop our
     * parser, not the external command's.
     *
     * @return array<string> Remaining argv elements
     */
    public function getRemainingArgs(): array
    {
        $remaining = array_slice($this->getArgv(), $this->next_index);

        // Strip leading '--' - it was meant for our parser, not the delegate
        if ($remaining !== [] && $remaining[0] === '--') {
            array_shift($remaining);
        }

        return $remaining;
    }

    /**
     * Declare an option
     */
    private function declareOption(?string $short, ?string $long, string $type, ?string $default = null): static
    {
        $short = $short ?? '';
        $long = $long ?? '';

        if ($short === '' && $long === '') {
            throw new \InvalidArgumentException('At least one of short or long option name must be provided');
        }

        // Check for duplicates
        if ($short !== '' && isset($this->shortToCanonical[$short])) {
            throw new \InvalidArgumentException("Short option '-{$short}' already declared");
        }
        if ($long !== '' && isset($this->declared[$long])) {
            throw new \InvalidArgumentException("Long option '--{$long}' already declared");
        }
        if ($short !== '' && isset($this->declared[$short])) {
            throw new \InvalidArgumentException("Option '{$short}' already declared as long option");
        }

        $clone = clone $this;

        // Canonical name is long if available, else short
        $canonical = $long !== '' ? $long : $short;
        $clone->declared[$canonical] = ['short' => $short, 'long' => $long, 'type' => $type, 'default' => $default];

        if ($short !== '') {
            $clone->shortToCanonical[$short] = $canonical;
        }

        $clone->parsedOpts = null;
        $clone->unparsedArgs = null;
        $clone->matchedSubcommand = null;
        return $clone;
    }

    /**
     * Resolve option name to canonical form
     */
    private function resolveToCanonical(string $name): string
    {
        // Direct match on canonical name
        if (isset($this->declared[$name])) {
            return $name;
        }

        // Short name lookup
        if (isset($this->shortToCanonical[$name])) {
            return $this->shortToCanonical[$name];
        }

        throw new \RuntimeException(
            "Option '{$name}' not declared. Use withFlag(), withRequiredValue(), or withOptionalValue() first."
        );
    }

    /**
     * Parse argv if not already done
     */
    private function ensureParsed(): void
    {
        if ($this->parsedOpts !== null) {
            return;
        }

        $argv = $this->getArgv();
        $argc = count($argv);
        $opts = [];
        $unparsed = [];
        $i = $this->start_index + 1;
        $subcommandIndex = null;

        while ($i < $argc) {
            $arg = $argv[$i];

            // End of options marker
            if ($arg === '--') {
                $i++;
                break;
            }

            // Long option
            if (str_starts_with($arg, '--')) {
                $result = $this->parseLongOption($argv, $i, $opts);
                if ($result === false) {
                    // Unknown option - goes to unparsed
                    $unparsed[] = $arg;
                    $i++;
                    continue;
                }
                $i = $result;
                continue;
            }

            // Short option(s)
            if (str_starts_with($arg, '-') && strlen($arg) > 1) {
                $result = $this->parseShortOptions($argv, $i, $opts);
                if ($result === false) {
                    // Unknown option - goes to unparsed
                    $unparsed[] = $arg;
                    $i++;
                    continue;
                }
                $i = $result;
                continue;
            }

            // Positional argument - check if it's a declared subcommand
            if (in_array($arg, $this->subcommands, true)) {
                $this->matchedSubcommand = $arg;
                $subcommandIndex = $i;
                $i++;
                break; // Stop parsing, rest belongs to subcommand
            }

            // Not a declared subcommand - goes to unparsed
            $unparsed[] = $arg;
            $i++;
        }

        // Collect remaining args after --
        while ($i < $argc) {
            // If we matched a subcommand, don't collect - they belong to it
            if ($this->matchedSubcommand !== null) {
                break;
            }
            $unparsed[] = $argv[$i++];
        }

        // Normalize to canonical names
        $normalized = [];
        foreach ($opts as $key => $value) {
            $canonical = $this->shortToCanonical[$key] ?? $key;
            if (isset($normalized[$canonical])) {
                // Merge repeated options
                if (!is_array($normalized[$canonical])) {
                    $normalized[$canonical] = [$normalized[$canonical]];
                }
                if (is_array($value)) {
                    $normalized[$canonical] = array_merge($normalized[$canonical], $value);
                } else {
                    $normalized[$canonical][] = $value;
                }
            } else {
                $normalized[$canonical] = $value;
            }
        }

        $this->parsedOpts = $normalized;
        $this->unparsedArgs = $unparsed;
        $this->next_index = $subcommandIndex ?? $argc;
    }

    /**
     * Parse a long option, return next index or false if unknown
     */
    private function parseLongOption(array $argv, int $i, array &$opts): int|false
    {
        $arg = $argv[$i];
        $eq = strpos($arg, '=');
        $name = $eq === false ? substr($arg, 2) : substr($arg, 2, $eq - 2);
        $inlineValue = $eq === false ? null : substr($arg, $eq + 1);

        // Find declaration
        if (!isset($this->declared[$name])) {
            return false;
        }

        $decl = $this->declared[$name];
        $type = $decl['type'];

        if ($type === 'flag') {
            $this->addOpt($opts, $name, false);
            return $i + 1;
        }

        if ($type === 'required') {
            if ($inlineValue !== null) {
                $this->addOpt($opts, $name, $inlineValue);
            } elseif (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                $this->addOpt($opts, $name, $argv[++$i]);
            } else {
                throw new \RuntimeException("Option --{$name} requires a value");
            }
            return $i + 1;
        }

        // Optional value
        if ($inlineValue !== null) {
            $this->addOpt($opts, $name, $inlineValue);
        } elseif (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
            $this->addOpt($opts, $name, $argv[++$i]);
        } else {
            $this->addOpt($opts, $name, false);
        }
        return $i + 1;
    }

    /**
     * Parse short option(s), return next index or false if unknown
     */
    private function parseShortOptions(array $argv, int $i, array &$opts): int|false
    {
        $arg = $argv[$i];
        $chars = substr($arg, 1);
        $len = strlen($chars);

        for ($j = 0; $j < $len; $j++) {
            $ch = $chars[$j];

            // Find declaration
            if (!isset($this->shortToCanonical[$ch])) {
                return false;
            }

            $canonical = $this->shortToCanonical[$ch];
            $decl = $this->declared[$canonical];
            $type = $decl['type'];

            if ($type === 'flag') {
                $this->addOpt($opts, $ch, false);
                continue;
            }

            // Option with value - rest of chars or next arg is value
            $rest = substr($chars, $j + 1);

            if ($rest !== '') {
                $this->addOpt($opts, $ch, $rest);
                return $i + 1;
            }

            if ($type === 'required') {
                if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                    $this->addOpt($opts, $ch, $argv[++$i]);
                } else {
                    throw new \RuntimeException("Option -{$ch} requires a value");
                }
            } else {
                // Optional
                if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                    $this->addOpt($opts, $ch, $argv[++$i]);
                } else {
                    $this->addOpt($opts, $ch, false);
                }
            }
            return $i + 1;
        }

        return $i + 1;
    }

    /**
     * Add option value, handling repeated options as arrays
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
}
