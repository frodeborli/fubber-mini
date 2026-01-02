<?php

require __DIR__ . '/../../../ensure-autoloader.php';
require_once __DIR__ . '/_BigInt.php';

use mini\Util\Math\Int\IntValue;
use mini\Util\Math\Int\NativeInt;

(new class extends tests\Util\Math\_BigInt {
    protected function createValue(string|int $value): IntValue
    {
        return NativeInt::of($value);
    }
})->run();
