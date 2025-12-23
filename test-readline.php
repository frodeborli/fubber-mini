<?php

require 'vendor/autoload.php';

class RLManager {

    private string $prompt;
    private ?string $input = null;
    private bool $prompting = false;
    private bool $installed = false;
    private array $completionFunctions = [];
    private array $history = [];

    public function __construct(string $prompt) {
        $this->prompt = $prompt;
    }

    public function addCompletionFunction(Closure $fn): void {
        $this->completionFunctions[] = $fn;
    }

    public function addHistory(string $prompt): void {
        $this->history[] = $prompt;
        if ($this->installed) {
            readline_add_history($prompt);
        }
    }

    public function clearHistory(): void {
        $this->history = [];
        if ($this->installed) {
            readline_clear_history();
        }
    }

    public function listHistory(): array {
        if ($this->installed) {
            return readline_list_history();
        } else {
            return $this->history;
        }
    }

    public function prompt(?string $alternativePrompt = null): ?string {
        if ($this->installed) {
            throw new RuntimeException("No double installing!");
        }
        $this->installed = true;
        
        readline_callback_handler_install($alternativePrompt ?? $this->prompt, $this->callback(...));
        foreach ($this->completionFunctions as $fn) {
            readline_completion_function($fn);
        }
        if ($alternativePrompt !== null) {
            foreach ($this->history as $h) {
                readline_add_history($h);
            }
        }
        
        $this->prompting = true;
        $bufferLength = $this->loop();
        $result = $this->input;
        $this->input = null;
        if ($bufferLength) {
            return $result ?? '';
        }
        return null;
    }

    public function cancel(): void {
        $this->prompting = false;
    }

    private function loop(): int {
        $readCount = 0;
        while ($this->prompting) {
            $r = array(STDIN);
            $w = NULL;
            $e = NULL;
            $n = @stream_select($r, $w, $e, 1, 150000);
            if ($n && in_array(STDIN, $r)) {
                // read a character, will call the callback when a newline is entered
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

    private function callback($ret) {
        $this->input = $ret;
        $this->prompting = false;
        $this->installed = false;
    }

}

$rl = new RLManager("db> ");

pcntl_signal(SIGINT, function () use ($rl) {
    $rl->cancel();
});
pcntl_async_signals(true);

$runs = 10;
while ($runs--) {
    $result = $rl->prompt();
    if ($result === null) {
        break;
    }
    echo "Got: '$result'\n";
    var_dump($result);
}
echo "Bye!\n";
