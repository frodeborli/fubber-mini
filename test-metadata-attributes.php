<?php

require_once __DIR__ . '/vendor/autoload.php';

use mini\Metadata\Attributes as Meta;
use mini\Validator\Attributes as Valid;

// Test 1: Simple property attributes
echo "Test 1: Simple property attributes\n";

class SimpleUser
{
    #[Meta\Title('Username')]
    #[Meta\Description('User login identifier')]
    #[Meta\Examples('johndoe', 'frode1977')]
    #[Meta\IsReadOnly]
    public string $username;

    #[Meta\Title('Password')]
    #[Meta\IsWriteOnly]
    public string $password;

    #[Meta\Title('Email')]
    #[Meta\Description('User email address')]
    #[Meta\MetaFormat('email')]
    public string $email;
}

$meta = mini\metadata(SimpleUser::class);
echo json_encode($meta, JSON_PRETTY_PRINT) . "\n\n";

// Test 2: Class-level attributes
echo "Test 2: Class-level attributes\n";

#[Meta\Title('User Account')]
#[Meta\Description('Represents a user in the system')]
class AnnotatedUser
{
    #[Meta\Title('Username')]
    #[Meta\IsReadOnly]
    public string $username;

    #[Meta\Title('Email')]
    public string $email;
}

$meta = mini\metadata(AnnotatedUser::class);
echo json_encode($meta, JSON_PRETTY_PRINT) . "\n\n";

// Test 3: Property-less metadata (using Property attribute)
echo "Test 3: Property-less metadata (interface with Property attributes)\n";

#[Meta\Property('username', 'Username', 'User login', null, true, null, null, null, 'johndoe', 'admin123')]
#[Meta\Property('email', 'Email Address', null, null, null, null, null, 'email')]
#[Meta\Property('age', 'Age', null, 18)]
interface UserInterface {}

$meta = mini\metadata(UserInterface::class);
echo json_encode($meta, JSON_PRETTY_PRINT) . "\n\n";

// Test 4: Deprecated field
echo "Test 4: Deprecated field\n";

class LegacyUser
{
    #[Meta\Title('Username')]
    public string $username;

    #[Meta\Title('Old ID')]
    #[Meta\IsDeprecated]
    #[Meta\Description('Use username instead')]
    public string $legacy_id;
}

$meta = mini\metadata(LegacyUser::class);
echo json_encode($meta, JSON_PRETTY_PRINT) . "\n\n";

// Test 5: Combined with Validator attributes
echo "Test 5: Combined Validator and Metadata attributes\n";

#[Meta\Title('Complete User')]
#[Meta\Description('User with both validation and metadata')]
class CompleteUser
{
    #[Valid\Required]
    #[Valid\MinLength(3)]
    #[Valid\Type('string')]
    #[Meta\Title('Username')]
    #[Meta\Description('User login identifier')]
    #[Meta\Examples('johndoe', 'admin123')]
    #[Meta\IsReadOnly]
    public string $username;

    #[Valid\Required]
    #[Valid\Format('email')]
    #[Meta\Title('Email Address')]
    #[Meta\MetaFormat('email')]
    public string $email;

    #[Valid\Minimum(18)]
    #[Meta\Title('Age')]
    #[Meta\DefaultValue(18)]
    public int $age;
}

echo "Validator schema:\n";
echo json_encode(mini\validator(CompleteUser::class), JSON_PRETTY_PRINT) . "\n\n";

echo "Metadata annotations:\n";
echo json_encode(mini\metadata(CompleteUser::class), JSON_PRETTY_PRINT) . "\n\n";

// Test 6: Property access
echo "Test 6: Property access via magic getter\n";

$userMeta = mini\metadata(CompleteUser::class);
echo "Username title: " . $userMeta->username->jsonSerialize()['title'] . "\n";
echo "Email title: " . $userMeta->email->jsonSerialize()['title'] . "\n";
echo "Age default: " . $userMeta->age->jsonSerialize()['default'] . "\n\n";

// Test 7: Caching behavior
echo "Test 7: Caching behavior\n";

$meta1 = mini\metadata(CompleteUser::class);
$meta2 = mini\metadata(CompleteUser::class);

echo "Same instance returned: " . ($meta1 === $meta2 ? 'Yes' : 'No') . "\n";
echo "Metadata is cached after first call\n\n";

echo "All tests completed!\n";
