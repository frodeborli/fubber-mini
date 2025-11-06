<?php

namespace mini\CLI;

/**
 * Manages command-line argument parsing without modifying global state
 *
 * ArgManager provides a clean, immutable approach to parsing CLI arguments
 * that respects Mini's philosophy: offering convenience without hiding PHP.
 * Like Mini's other helpers (db(), fmt(), t()), ArgManager is optional - you
 * can always use $_SERVER['argv'] directly if you prefer.
 *
 * Implements getopt-style parsing that respects starting index position,
 * allowing for clean subcommand handling.
 *
 * Features:
 * - Subcommand support with position tracking
 * - Short options (-v, -vvv, -i value)
 * - Long options (--verbose, --match=pattern, --match pattern)
 * - Option validation (required/optional values)
 * - Positional argument parsing with validation
 * - Immutable design (returns new instances)
 * - Clean delegation to external commands
 *
 * Example:
 *   // $_SERVER['argv'] = ['myapp', '-vvv', 'find', 'query', '--match', 'pattern', '-ix']
 *   $root = mini\args();
 *   $find = $root->nextCommand();
 *   $find = $find->withSupportedArgs('Fivwx', ['match:', 'limit:', 'project:'], 1);
 *   $opts = $find->opts;      // ['v' => [false, false, false], 'i' => false, 'x' => false, 'match' => 'pattern']
 *   $args = $find->args;      // ['query']
 *
 * Mini Philosophy:
 *   ArgManager doesn't replace $_SERVER['argv'] - it's a convenience layer.
 *   Use it when it helps, skip it when raw arrays are clearer. Mini trusts
 *   you to choose the right tool for the job.
 */
class ArgManager
{
    private int $next_index;

    /** Parsed options (available after calling withSupportedArgs) */
    public array $opts;

    /** Parsed positional arguments (available after calling withSupportedArgs) */
    public array $args;

    public function __construct(
        private readonly int $start_index = 0,
        private readonly ?ArgManager $parent = null
    ) {
        $this->next_index = $this->start_index + 1;
        $this->opts = [];
        $this->args = [];
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
}
