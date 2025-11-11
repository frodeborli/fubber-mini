<?php

require_once __DIR__ . '/vendor/autoload.php';

use mini\Metadata\Attributes as Meta;
use mini\Validator\Attributes as Valid;
use mini\Metadata\MetadataStore;
use mini\Validator\ValidatorStore;

// Test: Auto-building from stores directly

#[Meta\Title('Test User')]
#[Meta\Description('User for testing')]
class TestUser
{
    #[Valid\Required]
    #[Valid\MinLength(3)]
    #[Meta\Title('Username')]
    #[Meta\IsReadOnly]
    public string $username;

    #[Valid\Required]
    #[Valid\Format('email')]
    #[Meta\Title('Email')]
    public string $email;
}

echo "Test 1: Direct store access auto-builds\n";
$metaStore = mini\Mini::$mini->get(MetadataStore::class);
$validStore = mini\Mini::$mini->get(ValidatorStore::class);

$meta = $metaStore->get(TestUser::class);
$validator = $validStore->get(TestUser::class);

echo "Metadata retrieved: " . ($meta !== null ? 'Yes' : 'No') . "\n";
echo "Validator retrieved: " . ($validator !== null ? 'Yes' : 'No') . "\n";
echo "Metadata title: " . $meta->jsonSerialize()['title'] . "\n";
echo "Validator has username property: " . (isset($validator->jsonSerialize()['properties']['username']) ? 'Yes' : 'No') . "\n\n";

echo "Test 2: Magic getter auto-builds\n";
$meta2 = $metaStore->{TestUser::class};
$validator2 = $validStore->{TestUser::class};

echo "Metadata via magic getter: " . ($meta2 !== null ? 'Yes' : 'No') . "\n";
echo "Validator via magic getter: " . ($validator2 !== null ? 'Yes' : 'No') . "\n\n";

echo "Test 3: Same instance returned (cached)\n";
echo "Metadata same instance: " . ($meta === $meta2 ? 'Yes' : 'No') . "\n";
echo "Validator same instance: " . ($validator === $validator2 ? 'Yes' : 'No') . "\n\n";

echo "Test 4: Non-existent identifier\n";
$unknownMeta = $metaStore->get('nonexistent');
$unknownValidator = $validStore->get('nonexistent');

echo "Unknown metadata returns null: " . ($unknownMeta === null ? 'Yes' : 'No') . "\n";
echo "Unknown validator returns null: " . ($unknownValidator === null ? 'Yes' : 'No') . "\n\n";

echo "Test 5: Magic getter throws on non-existent\n";
try {
    $metaStore->nonexistent;
    echo "ERROR: Should have thrown exception\n";
} catch (\RuntimeException $e) {
    echo "Metadata exception: " . $e->getMessage() . "\n";
}

try {
    $validStore->nonexistent;
    echo "ERROR: Should have thrown exception\n";
} catch (\RuntimeException $e) {
    echo "Validator exception: " . $e->getMessage() . "\n";
}

echo "\nAll store tests completed!\n";
