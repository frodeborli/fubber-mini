<?php

require __DIR__ . '/../../../ensure-autoloader.php';
require_once __DIR__ . '/_BigInt.php';

use mini\Util\Math\Int\IntValue;
use mini\Util\Math\Int\BcMathInt;

(new class extends tests\Util\Math\_BigInt {
    protected function canRun(): bool
    {
        return extension_loaded('bcmath');
    }

    protected function skipReason(): string
    {
        return 'bcmath extension not available';
    }

    protected function createValue(string|int $value): IntValue
    {
        return BcMathInt::of($value);
    }
})->run();
