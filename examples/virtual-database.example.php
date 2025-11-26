<?php

/**
 * VirtualDatabase Service Configuration Example
 *
 * Copy to _config/virtual-database.php to configure the VirtualDatabase service.
 *
 * The VirtualDatabase allows querying non-SQL data sources using SQL:
 * - CSV files
 * - JSON files
 * - REST APIs
 * - In-memory arrays
 * - Generators
 * - Any iterable data source
 *
 * Usage:
 * ```php
 * vdb()->query("SELECT * FROM countries WHERE continent = ?", ['Europe']);
 * vdb()->queryOne("SELECT * FROM users WHERE id = ?", [123]);
 * ```
 */

use mini\Database\VirtualDatabase;
use mini\Database\Virtual\{VirtualTable, OrderInfo, CsvTable, Row, Collation};
use mini\Parsing\SQL\AST\SelectStatement;

// Create VirtualDatabase with optional default collator
// Collation::binary() (default): Case-sensitive, byte-by-byte comparison
// Collation::nocase(): Case-insensitive ASCII comparison
// Collation::locale('en_US'): Locale-aware Unicode comparison (requires intl extension)
$vdb = new VirtualDatabase(
    defaultCollator: Collation::binary() // or Collation::nocase(), Collation::locale('sv_SE')
);

// Example 1: CSV file table
$vdb->registerTable('countries', CsvTable::fromFile(
    filePath: __DIR__ . '/../data/countries.csv',
    hasHeader: true
));

// Example 2: In-memory array table
$vdb->registerTable('users', CsvTable::fromArray([
    ['id' => 1, 'name' => 'Alice', 'role' => 'admin'],
    ['id' => 2, 'name' => 'Bob', 'role' => 'user'],
    ['id' => 3, 'name' => 'Charlie', 'role' => 'user'],
]));

// Example 3: Custom generator table
$vdb->registerTable('products', new VirtualTable(
    selectFn: function(SelectStatement $ast, $collator): iterable {
        // Simple example - no optimization
        // IMPORTANT: Must yield Row instances!
        $products = [
            1 => ['id' => 1, 'name' => 'Widget', 'price' => 9.99],
            2 => ['id' => 2, 'name' => 'Gadget', 'price' => 19.99],
            3 => ['id' => 3, 'name' => 'Doohickey', 'price' => 29.99],
        ];

        foreach ($products as $id => $columns) {
            yield new Row($id, $columns);
        }
    }
));

// Example 4: API-backed table with ordering hint
$vdb->registerTable('api_users', new VirtualTable(
    selectFn: function(SelectStatement $ast, $collator) use ($apiClient): iterable {
        // Tell engine: data is pre-sorted by 'id' ascending using BINARY collation
        yield new OrderInfo(
            column: 'id',
            desc: false,
            collation: 'BINARY'  // API uses case-sensitive sorting
        );

        // Fetch from API (already sorted by id)
        $users = $apiClient->getUsers(sortBy: 'id');

        foreach ($users as $user) {
            yield new Row($user['id'], $user);
        }
    }
));

// Example 5: Table with locale-aware sorting
$vdb->registerTable('swedish_names', new VirtualTable(
    defaultCollator: Collation::locale('sv_SE'), // Swedish collation
    selectFn: function(SelectStatement $ast, $collator): iterable {
        // Data pre-sorted using Swedish rules (å, ä, ö at end)
        yield new OrderInfo(
            column: 'name',
            desc: false,
            collation: 'sv_SE'  // Backend uses Swedish locale sorting
        );

        $names = [
            ['name' => 'Anders'],
            ['name' => 'Bengt'],
            ['name' => 'Zebra'],
            ['name' => 'Åke'],
            ['name' => 'Ärlig'],
            ['name' => 'Östen'],
        ];

        foreach ($names as $id => $row) {
            yield new Row($id, $row);
        }
    }
));

// Example 6: Read-write table (INSERT/UPDATE/DELETE)
// Note: VirtualDatabase handles WHERE evaluation - you just operate on row IDs!
$vdb->registerTable('sessions', new VirtualTable(
    selectFn: function(SelectStatement $ast, $collator): iterable {
        // Read from file or cache - MUST yield Row instances!
        $sessions = json_decode(file_get_contents(__DIR__ . '/../data/sessions.json'), true);

        foreach ($sessions as $sessionId => $sessionData) {
            yield new Row($sessionId, $sessionData);
        }
    },

    insertFn: function(array $row): string|int {
        // INSERT a single row - return generated ID
        $sessions = json_decode(file_get_contents(__DIR__ . '/../data/sessions.json'), true);
        $id = uniqid('sess_');
        $sessions[$id] = $row;
        file_put_contents(__DIR__ . '/../data/sessions.json', json_encode($sessions));
        return $id;
    },

    updateFn: function(array $rowIds, array $changes): int {
        // UPDATE specific rows by ID - return affected count
        // VirtualDatabase already evaluated WHERE and found matching row IDs!
        $sessions = json_decode(file_get_contents(__DIR__ . '/../data/sessions.json'), true);
        $affected = 0;

        foreach ($rowIds as $id) {
            if (isset($sessions[$id])) {
                $sessions[$id] = array_merge($sessions[$id], $changes);
                $affected++;
            }
        }

        file_put_contents(__DIR__ . '/../data/sessions.json', json_encode($sessions));
        return $affected;
    },

    deleteFn: function(array $rowIds): int {
        // DELETE specific rows by ID - return affected count
        // VirtualDatabase already evaluated WHERE and found matching row IDs!
        $sessions = json_decode(file_get_contents(__DIR__ . '/../data/sessions.json'), true);
        $affected = 0;

        foreach ($rowIds as $id) {
            if (isset($sessions[$id])) {
                unset($sessions[$id]);
                $affected++;
            }
        }

        file_put_contents(__DIR__ . '/../data/sessions.json', json_encode($sessions));
        return $affected;
    }
));

return $vdb;
