<?php

require __DIR__ . '/../../../ensure-autoloader.php';
require_once __DIR__ . '/_BigInt.php';

use mini\Util\Math\Int\IntValue;
use mini\Util\Math\Int\GmpInt;

(new class extends tests\Util\Math\_BigInt {
    protected function canRun(): bool
    {
        return extension_loaded('gmp');
    }

    protected function skipReason(): string
    {
        return 'gmp extension not available';
    }

    protected function createValue(string|int $value): IntValue
    {
        return GmpInt::of($value);
    }
})->run();
