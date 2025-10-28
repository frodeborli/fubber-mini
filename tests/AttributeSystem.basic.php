<?php

/**
 * Test attribute-driven repository system
 */

// Find composer autoloader
$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');

if (!$autoloader) {
    echo "Error: Could not find Composer autoloader\n";
    exit(1);
}

require_once $autoloader;

use mini\Attributes\Entity;
use mini\Attributes\Key;
use mini\Attributes\VarcharColumn;
use mini\Attributes\IntegerColumn;
use mini\Attributes\BooleanColumn;
use mini\Attributes\DateTimeColumn;
use mini\Attributes\JsonColumn;
use mini\Tables\AttributeDatabaseRepository;
use mini\Tables\CodecStrategies\SQLiteCodecStrategy;
use mini\Database\PdoDatabase;

function test(string $description, callable $test): void
{
    try {
        $test();
        echo "✓ $description\n";
    } catch (\Exception $e) {
        echo "✗ $description\n";
        echo "  Error: " . $e->getMessage() . "\n";
    }
}

function assertEqual($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $expectedStr = var_export($expected, true);
        $actualStr = var_export($actual, true);
        throw new \Exception("$message\nExpected: $expectedStr\nActual: $actualStr");
    }
}

// Test model with attributes
#[Entity(table: 'test_users')]
class AttributeUser
{
    #[Key]
    #[IntegerColumn('id', nullable: true)]
    public ?int $id = null;

    #[VarcharColumn('first_name', length: 50, minLength: 1)]
    public string $firstName = '';

    #[VarcharColumn('email', length: 255, format: 'email')]
    public string $email = '';

    #[IntegerColumn('age', nullable: true, minimum: 0, maximum: 150)]
    public ?int $age = null;

    #[BooleanColumn('is_active')]
    public bool $isActive = true;

    #[DateTimeColumn('created_at', nullable: true)]
    public ?\DateTime $createdAt = null;

    #[JsonColumn('preferences')]
    public array $preferences = [];
}

echo "Testing attribute-driven repository system...\n\n";

// Bootstrap framework (required for Scoped services)
mini\bootstrap();

// Create in-memory SQLite database for testing
$pdo = new PDO('sqlite::memory:');
$db = new PdoDatabase($pdo);

// Create test table
$db->exec("
    CREATE TABLE test_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        first_name VARCHAR(50) NOT NULL,
        email VARCHAR(255) NOT NULL,
        age INTEGER,
        is_active INTEGER DEFAULT 1,
        created_at TEXT,
        preferences TEXT
    )
");

test('Repository can be created with attribute analysis', function() use ($db) {
    $codecStrategy = new SQLiteCodecStrategy();
    $repo = new AttributeDatabaseRepository($db, AttributeUser::class, $codecStrategy);

    assertEqual('test_users', $repo->name(), 'Should get table name from Entity attribute');
    assertEqual('id', $repo->pk(), 'Should get primary key from Key attribute');
    assertEqual(AttributeUser::class, $repo->getModelClass(), 'Should return correct model class');
});

test('Dehydrate converts model to database format', function() use ($db) {
    $codecStrategy = new SQLiteCodecStrategy();
    $repo = new AttributeDatabaseRepository($db, AttributeUser::class, $codecStrategy);

    $user = new AttributeUser();
    $user->firstName = 'John';
    $user->email = 'john@example.com';
    $user->age = 30;
    $user->isActive = true;
    $user->createdAt = new DateTime('2024-01-01 12:00:00');
    $user->preferences = ['theme' => 'dark'];

    $data = $repo->dehydrate($user);

    assertEqual('John', $data['first_name'], 'Should map firstName to first_name');
    assertEqual('john@example.com', $data['email'], 'Should preserve email');
    assertEqual(30, $data['age'], 'Should preserve age');
    assertEqual(1, $data['is_active'], 'Should convert boolean true to 1');
    assertEqual('2024-01-01 12:00:00', $data['created_at'], 'Should format DateTime');
    assertEqual('{"theme":"dark"}', $data['preferences'], 'Should convert array to JSON');
});

test('Hydrate converts database data to model', function() use ($db) {
    $codecStrategy = new SQLiteCodecStrategy();
    $repo = new AttributeDatabaseRepository($db, AttributeUser::class, $codecStrategy);

    $row = [
        'id' => 1,
        'first_name' => 'Jane',
        'email' => 'jane@example.com',
        'age' => 25,
        'is_active' => 0,
        'created_at' => '2024-01-01 12:00:00',
        'preferences' => '{"theme":"light"}'
    ];

    $user = $repo->hydrate($row);

    assertEqual(1, $user->id, 'Should set id');
    assertEqual('Jane', $user->firstName, 'Should map first_name to firstName');
    assertEqual('jane@example.com', $user->email, 'Should set email');
    assertEqual(25, $user->age, 'Should set age');
    assertEqual(false, $user->isActive, 'Should convert 0 to boolean false');
    assertEqual('2024-01-01 12:00:00', $user->createdAt->format('Y-m-d H:i:s'), 'Should parse DateTime');
    assertEqual(['theme' => 'light'], $user->preferences, 'Should parse JSON to array');
});

test('Validation works with attribute schemas', function() use ($db) {
    $codecStrategy = new SQLiteCodecStrategy();
    $repo = new AttributeDatabaseRepository($db, AttributeUser::class, $codecStrategy);

    // Valid user
    $validUser = new AttributeUser();
    $validUser->firstName = 'Valid';
    $validUser->email = 'valid@example.com';
    $validUser->age = 30;

    $errors = $repo->validate($validUser);
    assertEqual([], $errors, 'Valid user should have no errors');

    // Invalid user
    $invalidUser = new AttributeUser();
    $invalidUser->firstName = ''; // Too short (minLength: 1)
    $invalidUser->age = 200; // Too large (maximum: 150)

    $errors = $repo->validate($invalidUser);
    assertEqual(true, !empty($errors['firstName']), 'Should have firstName error');
    assertEqual(true, !empty($errors['age']), 'Should have age error');
});

test('ConvertConditionValue applies codecs for queries', function() use ($db) {
    $codecStrategy = new SQLiteCodecStrategy();
    $repo = new AttributeDatabaseRepository($db, AttributeUser::class, $codecStrategy);

    // Boolean should be converted to integer for SQLite
    $converted = $repo->convertConditionValue('isActive', true);
    assertEqual(1, $converted, 'Should convert boolean true to 1 for query');

    $converted = $repo->convertConditionValue('isActive', false);
    assertEqual(0, $converted, 'Should convert boolean false to 0 for query');

    // DateTime should be converted to string
    $date = new DateTime('2024-01-01 12:00:00');
    $converted = $repo->convertConditionValue('createdAt', $date);
    assertEqual('2024-01-01 12:00:00', $converted, 'Should convert DateTime to string for query');
});

test('Insert and load work end-to-end', function() use ($db) {
    $codecStrategy = new SQLiteCodecStrategy();
    $repo = new AttributeDatabaseRepository($db, AttributeUser::class, $codecStrategy);

    // Create and insert user
    $user = new AttributeUser();
    $user->firstName = 'TestUser';
    $user->email = 'test@example.com';
    $user->age = 25;
    $user->isActive = true;
    $user->createdAt = new DateTime('2024-01-01 12:00:00');
    $user->preferences = ['notifications' => true];

    $id = $repo->insert($user);
    assertEqual(true, is_numeric($id) && $id > 0, 'Should return valid ID');

    // Load the user back
    $loadedUser = $repo->load($id);
    assertEqual(true, $loadedUser !== null, 'Should load the user');
    assertEqual((int)$id, $loadedUser->id, 'Should have correct ID');
    assertEqual('TestUser', $loadedUser->firstName, 'Should have correct firstName');
    assertEqual('test@example.com', $loadedUser->email, 'Should have correct email');
    assertEqual(25, $loadedUser->age, 'Should have correct age');
    assertEqual(true, $loadedUser->isActive, 'Should have correct isActive');
    assertEqual(['notifications' => true], $loadedUser->preferences, 'Should have correct preferences');
});

echo "\nAttribute-driven repository system tests completed!\n";