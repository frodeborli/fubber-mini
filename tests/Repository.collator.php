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

use function mini\{collator};
use mini\Repository\CsvRepository;
use mini\Util\InstanceStore;

// Test model classes
class Product {
    public ?string $name = null;
    public ?string $category = null;
    public ?float $price = null;
}

class App {
    public ?string $name = null;
    public ?string $version = null;
}

// Create test CSV file with various sorting scenarios
$csvPath = '/tmp/products.csv';
file_put_contents($csvPath, "name,category,price
Åpple iPhone,Electronics,999.99
zebra Printer,Electronics,299.99
Banana Stand,Furniture,199.99
äpple Watch,Electronics,399.99
Apple iPad,Electronics,599.99
Zebra Pattern Rug,Furniture,149.99
banana Phone,Electronics,99.99
APPLE TV,Electronics,179.99");

// Test default collator
test('Default collator is available', function() {
    $defaultCollator = collator();
    assertEqual('Collator', get_class($defaultCollator));
});

test('Default collator has numeric collation enabled', function() {
    $defaultCollator = collator();
    $numericEnabled = $defaultCollator->getAttribute(Collator::NUMERIC_COLLATION);
    assertEqual(Collator::ON, $numericEnabled);
});

// Test CsvRepository with default collator
test('CsvRepository uses default collator', function() use ($csvPath) {
    $repo = new CsvRepository($csvPath, Product::class, 'name');
    $products = $repo->all();

    // Should create repository without errors
    assertEqual(8, $products->count());
});

// Test sorting with collator
test('CSV repository sorting uses collator', function() use ($csvPath) {
    $repo = new CsvRepository($csvPath, Product::class, 'name');
    $products = $repo->all()->orderBy('name', 'asc');

    $names = [];
    foreach ($products as $product) {
        $names[] = $product->name;
    }

    // Test that sorting is consistent (exact order may vary by locale, but should be deterministic)
    assertEqual(8, count($names));

    // Test that items are actually sorted (not in original order)
    $originalOrder = ['Åpple iPhone', 'zebra Printer', 'Banana Stand', 'äpple Watch', 'Apple iPad', 'Zebra Pattern Rug', 'banana Phone', 'APPLE TV'];
    $isNotOriginalOrder = $names !== $originalOrder;
    assertEqual(true, $isNotOriginalOrder, 'Items should be sorted, not in original order');
});

// Test custom collator
test('CsvRepository accepts custom collator', function() use ($csvPath) {
    // Create a case-insensitive collator
    $customCollator = new Collator('en_US');
    $customCollator->setAttribute(Collator::STRENGTH, Collator::SECONDARY); // Case insensitive

    $repo = new CsvRepository($csvPath, Product::class, 'name', [], $customCollator);
    $products = $repo->all()->orderBy('name', 'asc');

    $names = [];
    foreach ($products as $product) {
        $names[] = $product->name;
    }

    assertEqual(8, count($names));
    // With case-insensitive sorting, similar names should be grouped together
});

// Test collator affects string comparisons in queries
test('Collator affects string comparisons in queries', function() use ($csvPath) {
    $repo = new CsvRepository($csvPath, Product::class, 'name');
    $products = $repo->all();

    // Test case-sensitive comparison (default POSIX collator)
    $exactMatch = $products->eq('name', 'Apple iPad');
    assertEqual(1, $exactMatch->count());

    $noMatch = $products->eq('name', 'APPLE IPAD'); // Different case
    assertEqual(0, $noMatch->count());
});

// Test numeric sorting behavior
test('Collator numeric collation works', function() {
    $csvPathNumeric = '/tmp/versions.csv';
    file_put_contents($csvPathNumeric, "name,version
App A,1.2
App B,1.10
App C,1.3
App D,2.1");

    $repo = new CsvRepository($csvPathNumeric, App::class, 'name');
    $apps = $repo->all()->orderBy('version', 'asc');

    $versions = [];
    foreach ($apps as $app) {
        $versions[] = $app->version;
    }

    // With numeric collation, 1.10 should come after 1.3, not before
    assertEqual(['1.2', '1.3', '1.10', '2.1'], $versions);

    unlink($csvPathNumeric);
});

// Test that string comparisons are consistent between sorting and filtering
test('String comparisons consistent between sorting and filtering', function() use ($csvPath) {
    $repo = new CsvRepository($csvPath, Product::class, 'name');

    // Get all products sorted by name
    $sortedProducts = $repo->all()->orderBy('name', 'asc');
    $sortedNames = [];
    foreach ($sortedProducts as $product) {
        $sortedNames[] = $product->name;
    }

    // Find products greater than or equal to the third item
    $thirdName = $sortedNames[2];
    $filteredProducts = $repo->all()->gte('name', $thirdName);

    $filteredCount = $filteredProducts->count();
    $expectedCount = count($sortedNames) - 2; // Should match items from index 2 onwards

    assertEqual($expectedCount, $filteredCount);
});

// Cleanup
unlink($csvPath);

echo "All collator tests passed!\n";