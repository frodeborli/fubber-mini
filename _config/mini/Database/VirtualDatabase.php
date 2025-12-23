<?php
/**
 * VirtualDatabase Demo Configuration
 *
 * This config is loaded when using vdb() within the mini framework development environment.
 * It provides sample tables for testing and demonstrating VirtualDatabase capabilities.
 */

use mini\Database\VirtualDatabase;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\GeneratorTable;
use mini\Table\Types\IndexType;

$vdb = new VirtualDatabase();

// Demo: Users table
$users = new InMemoryTable(
    new ColumnDef('id', ColumnType::Int, IndexType::Primary),
    new ColumnDef('name', ColumnType::Text),
    new ColumnDef('email', ColumnType::Text, IndexType::Unique),
    new ColumnDef('role', ColumnType::Text),
    new ColumnDef('active', ColumnType::Int),
);

$users->insert(['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com', 'role' => 'admin', 'active' => 1]);
$users->insert(['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com', 'role' => 'user', 'active' => 1]);
$users->insert(['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@example.com', 'role' => 'user', 'active' => 0]);

$vdb->registerTable('users', $users);

// Demo: Products table
$products = new InMemoryTable(
    new ColumnDef('id', ColumnType::Int, IndexType::Primary),
    new ColumnDef('name', ColumnType::Text),
    new ColumnDef('price', ColumnType::Float),
    new ColumnDef('category', ColumnType::Text, IndexType::Index),
    new ColumnDef('stock', ColumnType::Int),
);

$products->insert(['id' => 1, 'name' => 'Widget', 'price' => 9.99, 'category' => 'gadgets', 'stock' => 100]);
$products->insert(['id' => 2, 'name' => 'Gizmo', 'price' => 24.99, 'category' => 'gadgets', 'stock' => 50]);
$products->insert(['id' => 3, 'name' => 'Thingamajig', 'price' => 14.99, 'category' => 'tools', 'stock' => 75]);
$products->insert(['id' => 4, 'name' => 'Doohickey', 'price' => 4.99, 'category' => 'tools', 'stock' => 200]);

$vdb->registerTable('products', $products);

// Demo: Orders table (for JOIN demos when supported)
$orders = new InMemoryTable(
    new ColumnDef('id', ColumnType::Int, IndexType::Primary),
    new ColumnDef('user_id', ColumnType::Int, IndexType::Index),
    new ColumnDef('product_id', ColumnType::Int, IndexType::Index),
    new ColumnDef('quantity', ColumnType::Int),
    new ColumnDef('total', ColumnType::Float),
);

$orders->insert(['id' => 1, 'user_id' => 1, 'product_id' => 1, 'quantity' => 2, 'total' => 19.98]);
$orders->insert(['id' => 2, 'user_id' => 1, 'product_id' => 3, 'quantity' => 1, 'total' => 14.99]);
$orders->insert(['id' => 3, 'user_id' => 2, 'product_id' => 2, 'quantity' => 3, 'total' => 74.97]);

$vdb->registerTable('orders', $orders);

// Demo: Contacts table (with NULL values for testing NULL handling)
$contacts = new InMemoryTable(
    new ColumnDef('id', ColumnType::Int, IndexType::Primary),
    new ColumnDef('name', ColumnType::Text),
    new ColumnDef('email', ColumnType::Text),
    new ColumnDef('phone', ColumnType::Text),
    new ColumnDef('notes', ColumnType::Text),
);

$contacts->insert(['id' => 1, 'name' => 'Alice', 'email' => 'alice@test.com', 'phone' => '555-0001', 'notes' => 'VIP customer']);
$contacts->insert(['id' => 2, 'name' => 'Bob', 'email' => null, 'phone' => '555-0002', 'notes' => null]);
$contacts->insert(['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@test.com', 'phone' => null, 'notes' => 'Prefers email']);
$contacts->insert(['id' => 4, 'name' => 'Diana', 'email' => null, 'phone' => null, 'notes' => null]);
$contacts->insert(['id' => 5, 'name' => null, 'email' => 'unknown@test.com', 'phone' => '555-0005', 'notes' => 'Name unknown']);

$vdb->registerTable('contacts', $contacts);

// ---------------------------------------------------------------------------
// Generated Tables - for testing with larger datasets
// ---------------------------------------------------------------------------

// Fibonacci sequence: id, value, label
$vdb->registerTable('fibonacci', new GeneratorTable(function () {
    $a = 0;
    $b = 1;
    for ($i = 1; $i <= 1000; $i++) {
        yield $i => (object) [
            'id' => $i,
            'value' => $a,
            'label' => "fib($i)",
        ];
        [$a, $b] = [$b, $a + $b];
    }
},
    new ColumnDef('id', ColumnType::Int, IndexType::Primary),
    new ColumnDef('value', ColumnType::Int),
    new ColumnDef('label', ColumnType::Text),
));

// Sequence: simple 1..1000 with squares
$vdb->registerTable('sequence', new GeneratorTable(function () {
    for ($i = 1; $i <= 1000; $i++) {
        yield $i => (object) [
            'id' => $i,
            'value' => $i * $i,
            'label' => "seq_" . str_pad($i, 4, '0', STR_PAD_LEFT),
        ];
    }
},
    new ColumnDef('id', ColumnType::Int, IndexType::Primary),
    new ColumnDef('value', ColumnType::Int),
    new ColumnDef('label', ColumnType::Text),
));

// Primes: prime numbers up to ~8000 (first 1000 primes)
$vdb->registerTable('primes', new GeneratorTable(function () {
    $sieve = [];
    $id = 0;
    for ($n = 2; $id < 1000; $n++) {
        if (!isset($sieve[$n])) {
            $id++;
            yield $id => (object) [
                'id' => $id,
                'value' => $n,
                'label' => "prime_$id",
            ];
            // Mark multiples
            for ($m = $n * $n; $m < 10000; $m += $n) {
                $sieve[$m] = true;
            }
        }
    }
},
    new ColumnDef('id', ColumnType::Int, IndexType::Primary),
    new ColumnDef('value', ColumnType::Int),
    new ColumnDef('label', ColumnType::Text),
));

// Timestamps: deterministic dates starting from 2020-01-01
$vdb->registerTable('timestamps', new GeneratorTable(function () {
    $base = new DateTimeImmutable('2020-01-01 00:00:00');
    for ($i = 1; $i <= 1000; $i++) {
        // Each row is 1 day + i hours apart (deterministic but varied)
        $dt = $base->modify("+$i days +$i hours");
        yield $i => (object) [
            'id' => $i,
            'value' => $i,
            'label' => $dt->format('Y-m-d H:i:s'),
            'created_at' => $dt->format('Y-m-d H:i:s'),
        ];
    }
},
    new ColumnDef('id', ColumnType::Int, IndexType::Primary),
    new ColumnDef('value', ColumnType::Int),
    new ColumnDef('label', ColumnType::Text),
    new ColumnDef('created_at', ColumnType::Text),  // datetime as text for now
));

// Words: deterministic pseudo-words from id (for text search testing)
$vdb->registerTable('words', new GeneratorTable(function () {
    // Simple deterministic word generator using id as seed
    $syllables = ['ba', 'be', 'bi', 'bo', 'bu', 'da', 'de', 'di', 'do', 'du',
                  'fa', 'fe', 'fi', 'fo', 'fu', 'ga', 'ge', 'gi', 'go', 'gu',
                  'ka', 'ke', 'ki', 'ko', 'ku', 'la', 'le', 'li', 'lo', 'lu',
                  'ma', 'me', 'mi', 'mo', 'mu', 'na', 'ne', 'ni', 'no', 'nu',
                  'pa', 'pe', 'pi', 'po', 'pu', 'ra', 're', 'ri', 'ro', 'ru',
                  'sa', 'se', 'si', 'so', 'su', 'ta', 'te', 'ti', 'to', 'tu'];
    $count = count($syllables);

    for ($i = 1; $i <= 1000; $i++) {
        // Generate 2-4 syllable word deterministically
        $numSyllables = 2 + ($i % 3);
        $word = '';
        $n = $i;
        for ($s = 0; $s < $numSyllables; $s++) {
            $word .= $syllables[$n % $count];
            $n = (int)($n / $count) + $i + $s;
        }
        yield $i => (object) [
            'id' => $i,
            'value' => strlen($word),
            'label' => $word,
        ];
    }
},
    new ColumnDef('id', ColumnType::Int, IndexType::Primary),
    new ColumnDef('value', ColumnType::Int),
    new ColumnDef('label', ColumnType::Text),
));

return $vdb;
