<?php

require_once __DIR__ . '/vendor/autoload.php';

use mini\Metadata\Metadata;
use mini\Metadata\MetadataStore;

// Test 1: Create simple metadata
echo "Test 1: Simple metadata\n";
$usernameMeta = mini\metadata()
    ->title('Username')
    ->description('User login identifier')
    ->examples('johndoe', 'frode1977')
    ->readOnly(true);

echo json_encode($usernameMeta, JSON_PRETTY_PRINT) . "\n\n";

// Test 2: Class metadata with properties
echo "Test 2: Class metadata with properties\n";
$userMeta = mini\metadata()
    ->title('User')
    ->description('Object representing user accounts')
    ->deprecated(false)
    ->properties([
        'username' => mini\metadata()
            ->title('Username')
            ->description('The users login name')
            ->examples('johndoe', 'frode1977')
            ->readOnly(true),
        'password' => mini\metadata()
            ->title('Password')
            ->writeOnly(true)
    ]);

echo json_encode($userMeta, JSON_PRETTY_PRINT) . "\n\n";

// Test 3: Store and retrieve metadata
echo "Test 3: Store and retrieve metadata\n";
$store = mini\Mini::$mini->get(MetadataStore::class);
$store['User'] = $userMeta;

$retrieved = mini\metadata('User');
echo "Retrieved title: " . $retrieved->jsonSerialize()['title'] . "\n";
echo "Username property title: " . $retrieved->username->jsonSerialize()['title'] . "\n\n";

// Test 4: Array metadata
echo "Test 4: Array metadata with items\n";
$arrayMeta = mini\metadata()
    ->title('Array of integers')
    ->items(mini\metadata()->title('Integer'));

echo json_encode($arrayMeta, JSON_PRETTY_PRINT) . "\n\n";

// Test 5: Validator and Metadata are independent
echo "Test 5: Validator and Metadata are independent\n";

class User {}

// Store metadata
$store[User::class] = mini\metadata()
    ->title('User Account')
    ->description('System user')
    ->properties([
        'username' => mini\metadata()->title('Username')->readOnly(true),
        'email' => mini\metadata()->title('Email')->format('email'),
        'age' => mini\metadata()->title('Age')
    ]);

// Create validator
$validator = mini\validator()
    ->type('object')
    ->properties([
        'username' => mini\validator()->type('string')->required()->minLength(3),
        'email' => mini\validator()->type('string')->required()->format('email'),
        'age' => mini\validator()->type('integer')->minimum(18)
    ]);

// Both can be serialized independently
echo "Validator schema:\n";
echo json_encode($validator, JSON_PRETTY_PRINT) . "\n\n";

echo "Metadata annotations:\n";
echo json_encode(mini\metadata(User::class), JSON_PRETTY_PRINT) . "\n\n";

echo "Note: Application code can merge these as needed for specific use cases (e.g., /schema.json endpoint)\n\n";

echo "All tests completed!\n";
