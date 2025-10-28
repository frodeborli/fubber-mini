<?php

// Find composer autoloader using the standard pattern
$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');

if (!require $autoloader) {
    fwrite(STDERR, "Error: Could not find composer autoloader\n");
    exit(1);
}

// Simple test helpers
function test(string $description, callable $test): void
{
    try {
        $test();
        echo "✓ $description\n";
    } catch (Exception $e) {
        fwrite(STDERR, "✗ $description\n");
        fwrite(STDERR, "  " . $e->getMessage() . "\n");
        exit(1);
    }
}

function assertEqual($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $expectedStr = var_export($expected, true);
        $actualStr = var_export($actual, true);
        throw new Exception("$message\nExpected: $expectedStr\nActual: $actualStr");
    }
}

use mini\Tables\AbstractDatabaseRepository;
use mini\Util\InstanceStore;
use mini\Tables\RepositoryInterface;
use mini\DB;

// Test model class with different data types
class TypedUser {
    public ?int $id = null;
    public ?string $name = null;
    public ?string $email = null;
    public ?int $age = null;
    public ?bool $is_active = null;
    public ?\DateTimeImmutable $created_at = null;
}

// Create test database with typed fields
$db = new DB(':memory:');
$db->exec("CREATE TABLE typed_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100),
    email VARCHAR(100),
    age INTEGER,
    is_active INTEGER,
    created_at DATETIME
)");

// Insert test data with mixed types
$db->exec("INSERT INTO typed_users (name, email, age, is_active, created_at) VALUES
    ('Alice', 'alice@example.com', 25, 1, '2024-01-15 10:30:00'),
    ('Bob', 'bob@example.com', 35, 0, '2023-06-20 14:45:00'),
    ('Carol', 'carol@example.com', 45, 1, '2024-03-10 09:15:00')");

// Create test registry
$testRegistry = new InstanceStore(RepositoryInterface::class);
// Create test repository with type mappings
$typedRepository = new class($db, 'typed_users', TypedUser::class, 'id') extends AbstractDatabaseRepository {
    public function validate(object $user): array
    {
        return [];
    }

    public function create(): object
    {
        $user = new ($this->modelClass)();
        $user->is_active = true;
        $user->created_at = new \DateTimeImmutable();
        return $user;
    }

    protected function getFieldMappings(): array
    {
        return [];
    }

    protected function getTypeMappings(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'age' => 'int'
        ];
    }
};

$testRegistry['typed_users'] = $typedRepository;

$testTable = function(string $name) use ($testRegistry) {
    return $testRegistry->get($name)->all();
};

// Test fluent API with typed values
test('Fluent API with boolean true', function() use ($testTable) {
    $activeUsers = $testTable('typed_users')->eq('is_active', true);
    assertEqual(2, $activeUsers->count()); // Alice and Carol
});

test('Fluent API with boolean false', function() use ($testTable) {
    $inactiveUsers = $testTable('typed_users')->eq('is_active', false);
    assertEqual(1, $inactiveUsers->count()); // Bob
});

test('Fluent API with DateTimeImmutable', function() use ($testTable) {
    $afterDate = new DateTimeImmutable('2024-01-01');
    $recentUsers = $testTable('typed_users')->gte('created_at', $afterDate);
    assertEqual(2, $recentUsers->count()); // Alice and Carol
});

test('Fluent API with integer comparison', function() use ($testTable) {
    $adults = $testTable('typed_users')->gte('age', 30);
    assertEqual(2, $adults->count()); // Bob and Carol
});

// Test query string parsing with type conversion
test('Query string with boolean "1"', function() use ($testTable) {
    $activeUsers = $testTable('typed_users')->query('is_active=1');
    assertEqual(2, $activeUsers->count()); // Alice and Carol
});

test('Query string with boolean "0"', function() use ($testTable) {
    $inactiveUsers = $testTable('typed_users')->query('is_active=0');
    assertEqual(1, $inactiveUsers->count()); // Bob
});

test('Query string with boolean "true"', function() use ($testTable) {
    $activeUsers = $testTable('typed_users')->query('is_active=true');
    assertEqual(2, $activeUsers->count()); // Alice and Carol
});

test('Query string with boolean "false"', function() use ($testTable) {
    $inactiveUsers = $testTable('typed_users')->query('is_active=false');
    assertEqual(1, $inactiveUsers->count()); // Bob
});

test('Query string with date string', function() use ($testTable) {
    $recentUsers = $testTable('typed_users')->query('created_at:gte=2024-01-01');
    assertEqual(2, $recentUsers->count()); // Alice and Carol
});

test('Query string with integer comparison', function() use ($testTable) {
    $middleAged = $testTable('typed_users')->query('age:gte=30&age:lte=40');
    assertEqual(1, $middleAged->count()); // Bob (35)
});

// Test field whitelisting
test('Field whitelisting allows valid fields', function() use ($testRegistry) {
    $repo = $testRegistry->get('typed_users');
    $fieldNames = $repo->getFieldNames();

    $expectedFields = ['id', 'name', 'email', 'age', 'is_active', 'created_at'];
    sort($expectedFields);
    sort($fieldNames);

    assertEqual($expectedFields, $fieldNames);
});

test('QueryParser with field whitelist (valid field)', function() use ($testTable) {
    // This should work - name is a valid field
    $users = $testTable('typed_users')->query('name=Alice');
    assertEqual(1, $users->count());
});

// Test mixed type scenarios
test('Complex query with multiple types', function() use ($testTable) {
    // Active users over 30 created in 2024
    $complexQuery = $testTable('typed_users')
        ->eq('is_active', true)
        ->gte('age', 30)
        ->gte('created_at', new DateTimeImmutable('2024-01-01'));

    assertEqual(1, $complexQuery->count()); // Carol

    $user = $complexQuery->one();
    assertEqual('Carol', $user->name);
});

test('Query string equivalent of complex query', function() use ($testTable) {
    // Same as above but using query string
    $users = $testTable('typed_users')->query('is_active=1&age:gte=30&created_at:gte=2024-01-01');
    assertEqual(1, $users->count()); // Carol

    $user = $users->one();
    assertEqual('Carol', $user->name);
});

echo "All type conversion tests passed!\n";
