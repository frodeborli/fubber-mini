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
use mini\Tables\CsvRepository;
use mini\Util\InstanceStore;
use mini\Tables\RepositoryInterface;
use mini\DB;

// Test model classes
class User {
    public ?int $id = null;
    public ?string $name = null;
    public ?string $email = null;
    public ?bool $is_active = null;
    public ?\DateTimeImmutable $created_at = null;
}

class Country {
    public ?string $country_code = null;
    public ?string $name = null;
    public ?int $population = null;
}

// Create test database with unique table name
$db = new DB(':memory:');
$db->exec("CREATE TABLE test_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100),
    email VARCHAR(100),
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("INSERT INTO test_users (name, email, is_active) VALUES
    ('John Doe', 'john@example.com', 1),
    ('Jane Smith', 'jane@example.com', 0),
    ('Bob Wilson', 'bob@example.com', 1)");

// Create test CSV file
$csvPath = '/tmp/countries.csv';
file_put_contents($csvPath, "country_code,name,population\nNO,Norway,5400000\nSE,Sweden,10400000\nDK,Denmark,5800000");

// Create isolated registry for testing
$testRegistry = new InstanceStore(RepositoryInterface::class);

// Register repositories
// Create simple test repository
$testRepository = new class($db, 'test_users', User::class) extends AbstractDatabaseRepository {
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
            'id' => 'int',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
};

$testRegistry['users'] = $testRepository;

$testRegistry['countries'] = new CsvRepository($csvPath, Country::class, 'country_code', [
    'population' => 'int'
]);

// Helper function to get table from test registry
$testTable = function(string $name) use ($testRegistry) {
    return $testRegistry[$name]->all();
};

// Test basic table functionality
test('Table as IteratorAggregate', function() use ($testTable) {
    $users = $testTable('users');
    $count = 0;
    foreach ($users as $user) {
        $count++;
        assertEqual(User::class, get_class($user));
    }
    assertEqual(3, $count);
});

test('Table query methods work immutably', function() use ($testTable) {
    $users = $testTable('users');
    $activeUsers = $users->eq('is_active', true);

    // Original table unchanged
    assertEqual(3, $users->count());

    // Filtered table has fewer results
    assertEqual(2, $activeUsers->count());
});

test('Database repository load method', function() use ($testTable) {
    $user = $testTable('users')->load(1);
    assertEqual('John Doe', $user->name);
    assertEqual(true, $user->is_active);
});

test('Database repository create method', function() use ($testTable) {
    $user = $testTable('users')->create();
    assertEqual(User::class, get_class($user));
    assertNotNull($user->created_at);
});

test('CSV repository basic functionality', function() use ($testTable) {
    $countries = $testTable('countries');
    assertEqual(3, $countries->count());

    $norway = $countries->eq('country_code', 'NO')->one();
    assertEqual('Norway', $norway->name);
    assertEqual(5400000, $norway->population);
});

test('CSV repository is read-only', function() use ($testRegistry) {
    $repo = $testRegistry->get('countries');
    assertEqual(true, $repo->isReadOnly());

    try {
        $repo->insert(new Country());
        assertEqual(false, true, 'Should have thrown exception');
    } catch (Exception $e) {
        assertEqual(true, str_contains($e->getMessage(), 'read-only'));
    }
});

test('Query string parsing', function() use ($testTable) {
    $users = $testTable('users')->query('is_active=1');
    assertEqual(2, $users->count());

    $users = $testTable('users')->query(['name' => 'John Doe']);
    assertEqual(1, $users->count());
});

test('Repository registry', function() use ($testRegistry) {
    assertEqual(true, $testRegistry->has('users'));
    assertEqual(true, $testRegistry->has('countries'));
    assertEqual(false, $testRegistry->has('nonexistent'));

    $names = $testRegistry->keys();
    assertEqual(true, in_array('users', $names));
    assertEqual(true, in_array('countries', $names));
});

function assertNotNull($value) {
    assertEqual(false, $value === null, 'Value should not be null');
}

echo "All repository tests passed!\n";
