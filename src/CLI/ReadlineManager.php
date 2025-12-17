<?php

namespace mini\CLI;

use Closure;
use RuntimeException;

/**
 * Wrapper around PHP's readline callback interface with proper signal handling
 *
 * Provides a clean way to handle Ctrl+C:
 * - Returns '' if cancelled with content in buffer (user wants to clear line)
 * - Returns null if cancelled with empty buffer (user wants to exit)
 *
 * ```php
 * $rl = new ReadlineManager('sql> ');
 * pcntl_signal(SIGINT, fn() => $rl->cancel());
 * pcntl_async_signals(true);
 *
 * while (($line = $rl->prompt()) !== null) {
 *     if ($line === '') continue; // Ctrl+C with content, or empty enter
 *     $rl->addHistory($line);
 *     processLine($line);
 * }
 * ```
 */
class ReadlineManager
{
    private string $prompt;
    private ?string $input = null;
    private bool $prompting = false;
    private bool $installed = false;
    private array $history = [];
    private ?Closure $completionFunction = null;

    public function __construct(string $prompt)
    {
        $this->prompt = $prompt;
    }

    public function __destruct()
    {
        // Ensure readline handler is removed on exit
        if ($this->installed && function_exists('readline_callback_handler_remove')) {
            @readline_callback_handler_remove();
        }
    }

    /**
     * Set the default prompt
     */
    public function setPrompt(string $prompt): void
    {
        $this->prompt = $prompt;
    }

    /**
     * Add entry to history
     */
    public function addHistory(string $entry): void
    {
        $this->history[] = $entry;
        if ($this->installed) {
            readline_add_history($entry);
        }
    }

    /**
     * Clear all history
     */
    public function clearHistory(): void
    {
        $this->history = [];
        if ($this->installed) {
            readline_clear_history();
        }
    }

    /**
     * Get current history entries
     */
    public function getHistory(): array
    {
        if ($this->installed) {
            return readline_list_history();
        }
        return $this->history;
    }

    /**
     * Load history from array
     */
    public function loadHistory(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->history[] = $entry;
        }
    }

    /**
     * Set completion function
     */
    public function setCompletionFunction(Closure $fn): void
    {
        $this->completionFunction = $fn;
        if ($this->installed) {
            if (function_exists('readline_completion_function')) {
                readline_completion_function($fn);
            }
        }
    }

    /**
     * Prompt for input
     *
     * @param string|null $alternativePrompt One-time prompt override
     * @return string|null The input line, '' if cancelled with content, null if cancelled empty
     */
    public function prompt(?string $alternativePrompt = null): ?string
    {
        if ($this->installed) {
            throw new RuntimeException("ReadlineManager: prompt() called while already prompting");
        }

        if (!function_exists('readline_callback_handler_install')) {
            // Fallback for systems without readline callback support
            echo $alternativePrompt ?? $this->prompt;
            $line = fgets(STDIN);
            return $line === false ? null : rtrim($line, "\n\r");
        }

        $this->installed = true;

        // Register completion function BEFORE handler install
        if ($this->completionFunction !== null && function_exists('readline_completion_function')) {
            readline_completion_function($this->completionFunction);
        }

        readline_callback_handler_install($alternativePrompt ?? $this->prompt, $this->callback(...));

        // Load history into readline
        foreach ($this->history as $h) {
            readline_add_history($h);
        }

        $this->prompting = true;
        $readCount = $this->loop();
        $result = $this->input;
        $this->input = null;

        // Distinguish: cancelled with content vs cancelled empty
        if ($readCount > 0) {
            return $result ?? '';
        }
        return $result;
    }

    /**
     * Cancel current prompt (call from signal handler)
     */
    public function cancel(): void
    {
        $this->prompting = false;
    }

    /**
     * Check if currently prompting
     */
    public function isPrompting(): bool
    {
        return $this->prompting;
    }

    /**
     * Get current line buffer content
     */
    public function getLineBuffer(): string
    {
        return (string) readline_info('line_buffer');
    }

    private function loop(): int
    {
        $readCount = 0;
        while ($this->prompting) {
            $r = array(STDIN);
            $w = NULL;
            $e = NULL;
            $n = @stream_select($r, $w, $e, 1, 150000);
            if ($n && in_array(STDIN, $r)) {
                ++$readCount;
                readline_callback_read_char();
            }
        }
        if ($this->installed) {
            readline_callback_handler_remove();
            $this->installed = false;
        }
        return $readCount;
    }

    private function callback(?string $line): void
    {
        $this->input = $line;
        $this->prompting = false;
        $this->installed = false;
    }
}
