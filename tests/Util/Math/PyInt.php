<?php

require __DIR__ . '/../../../ensure-autoloader.php';
require_once __DIR__ . '/_BigInt.php';

use mini\Util\Math\Int\IntValue;
use mini\Util\Math\Int\PyInt;

(new class extends tests\Util\Math\_BigInt {
    protected function canRun(): bool
    {
        // Check if python3 is available
        $result = shell_exec('python3 --version 2>&1');
        return $result !== null && str_starts_with($result, 'Python 3');
    }

    protected function skipReason(): string
    {
        return 'python3 not available';
    }

    protected function createValue(string|int $value): IntValue
    {
        return PyInt::of($value);
    }
})->run();
