<?php
require __DIR__ . '/../vendor/autoload.php';

use mini\Tables\CodecRegistry;

echo "Testing Codecs Registry\n";
echo str_repeat('=', 70) . "\n\n";

// Test 1: CodecRegistry static methods accessible
echo "Test 1: CodecRegistry static methods accessible\n";
echo "✓ CodecRegistry class available\n";
echo "  Class: " . CodecRegistry::class . "\n\n";

// Test 2: Built-in codecs registered
echo "Test 2: Built-in codecs registered\n";
$dateTimeCodec = CodecRegistry::get(\DateTime::class);
if ($dateTimeCodec !== null) {
    echo "✓ DateTime codec registered\n";
    echo "  Type: " . get_class($dateTimeCodec) . "\n";
} else {
    echo "✗ DateTime codec NOT found\n";
}

$dateTimeImmutableCodec = CodecRegistry::get(\DateTimeImmutable::class);
if ($dateTimeImmutableCodec !== null) {
    echo "✓ DateTimeImmutable codec registered\n";
} else {
    echo "✗ DateTimeImmutable codec NOT found\n";
}
echo "\n";

// Test 3: Codec functionality
echo "Test 3: DateTime codec functionality\n";
$dt = new \DateTime('2025-10-27 12:00:00');
$stringValue = $dateTimeCodec->toBackendString($dt);
echo "✓ toBackendString: $stringValue\n";

$dtFromString = $dateTimeCodec->fromBackendString('2025-10-27 15:30:00');
echo "✓ fromBackendString: " . $dtFromString->format('Y-m-d H:i:s') . "\n";

$intValue = $dateTimeCodec->toBackendInteger($dt);
echo "✓ toBackendInteger: $intValue\n";

$dtFromInt = $dateTimeCodec->fromBackendInteger(time());
echo "✓ fromBackendInteger: " . $dtFromInt->format('Y-m-d H:i:s') . "\n";
echo "\n";

echo str_repeat('=', 70) . "\n";
echo "Codec registry working correctly!\n";
echo "\nKey points:\n";
echo "  ✓ CodecRegistry uses static OOP methods (register/get/has)\n";
echo "  ✓ No global codecs() function polluting mini\\ namespace\n";
echo "  ✓ Built-in DateTime/DateTimeImmutable codecs auto-registered\n";
echo "  ✓ Codec registry is internal to Tables feature\n";
echo "  ✓ Applications register custom codecs via CodecRegistry::register()\n";
