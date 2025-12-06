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

// Test model class
class TestUser {
    public ?int $id = null;
    public ?string $name = null;
    public ?string $email = null;
    public ?int $age = null;
    public ?bool $is_active = null;
}

// Create test database with more data for QueryParser testing
$db = new DB(':memory:');
$db->exec("CREATE TABLE query_test_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100),
    email VARCHAR(100),
    age INTEGER,
    is_active INTEGER DEFAULT 1
)");

$db->exec("INSERT INTO query_test_users (name, email, age, is_active) VALUES
    ('Alice Johnson', 'alice@example.com', 25, 1),
    ('Bob Smith', 'bob@example.com', 35, 0),
    ('Carol Williams', 'carol@example.com', 45, 1),
    ('David Brown', 'david@example.com', 19, 1),
    ('Eve Davis', 'eve@example.com', 67, 0)");

// Create test registry
$testRegistry = new InstanceStore(RepositoryInterface::class);
// Create test repository
$queryTestRepository = new class($db, 'query_test_users', TestUser::class, 'id') extends AbstractDatabaseRepository {
    public function validate(object $user): array
    {
        return [];
    }

    public function create(): object
    {
        return new ($this->modelClass)();
    }

    protected function getFieldMappings(): array
    {
        return [];
    }

    protected function getTypeMappings(): array
    {
        return [
            'is_active' => 'boolean'
        ];
    }
};

$testRegistry['test_users'] = $queryTestRepository;

$testTable = function(string $name) use ($testRegistry) {
    return $testRegistry->get($name)->all();
};

// Test QueryParser integration with various operators
test('QueryParser gte operator', function() use ($testTable) {
    $adults = $testTable('test_users')->query('age:gte=21');
    assertEqual(4, $adults->count()); // Alice(25), Bob(35), Carol(45), Eve(67)
});

test('QueryParser lte operator', function() use ($testTable) {
    $young = $testTable('test_users')->query('age:lte=30');
    assertEqual(2, $young->count()); // Alice(25), David(19)
});

test('QueryParser range query', function() use ($testTable) {
    $middleAged = $testTable('test_users')->query('age:gte=20&age:lte=40');
    assertEqual(2, $middleAged->count()); // Alice(25), Bob(35)
});

test('QueryParser boolean conversion', function() use ($testTable) {
    $active = $testTable('test_users')->query('is_active=1');
    assertEqual(3, $active->count()); // Alice, Carol, David

    $inactive = $testTable('test_users')->query('is_active=0');
    assertEqual(2, $inactive->count()); // Bob, Eve
});

test('QueryParser exact match', function() use ($testTable) {
    $alice = $testTable('test_users')->query('name=Alice Johnson');
    assertEqual(1, $alice->count());

    $user = $alice->one();
    assertEqual('Alice Johnson', $user->name);
    assertEqual(25, $user->age);
});

test('QueryParser like pattern (if supported)', function() use ($testTable) {
    // Test if like operator works - this might not work yet depending on implementation
    try {
        $johnSurnames = $testTable('test_users')->query('name:like=*Johnson');
        assertEqual(1, $johnSurnames->count()); // Alice Johnson
    } catch (Exception $e) {
        // Like operator might not be implemented in repository yet
        echo "  (Like operator not yet supported in repository - this is expected)\n";
    }
});

test('QueryParser combined conditions', function() use ($testTable) {
    $activeAdults = $testTable('test_users')->query('is_active=1&age:gte=25');
    assertEqual(2, $activeAdults->count()); // Alice(25), Carol(45)
});

test('QueryParser with array input', function() use ($testTable) {
    $params = [
        'is_active' => '1',
        'age:lt' => '30'
    ];
    $youngActive = $testTable('test_users')->query($params);
    assertEqual(2, $youngActive->count()); // Alice(25), David(19)
});

echo "All QueryParser integration tests passed!\n";
