<?php
/**
 * Test GeneratorTable against InMemoryTable oracle
 *
 * Uses exhaustive filter permutations to verify GeneratorTable
 * produces identical results to the SQLite-backed oracle.
 */

require __DIR__ . '/../../ensure-autoloader.php';
require_once __DIR__ . '/_OracleComparisonTest.php';

use mini\testing\OracleComparisonTest;
use mini\Table\InMemoryTable;
use mini\Table\GeneratorTable;
use mini\Table\Contracts\TableInterface;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

$test = new class extends OracleComparisonTest {

    protected int $maxFilterDepth = 3;
    protected int $maxTestCases = 10000;

    protected function createOracle(): InMemoryTable
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text, IndexType::Index),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('dept', ColumnType::Text),
            new ColumnDef('salary', ColumnType::Float),
            new ColumnDef('level', ColumnType::Int),
            new ColumnDef('country', ColumnType::Text),
            new ColumnDef('active', ColumnType::Int),
            new ColumnDef('score', ColumnType::Float),
            new ColumnDef('birth_date', ColumnType::Date),      // YYYY-MM-DD dates
            new ColumnDef('start_time', ColumnType::Time),      // HH:MM:SS times
            new ColumnDef('created_at', ColumnType::DateTime),  // ISO 8601 datetimes
            new ColumnDef('code', ColumnType::Binary),          // Case-sensitive codes
        );

        // Expanded test data - 200 rows with variety including NULLs
        $depts = ['Engineering', 'Sales', 'Marketing', 'HR', 'Finance', 'Legal', 'Support', 'Research'];
        $countries = ['USA', 'UK', 'Germany', 'France', 'Japan', 'Canada', 'Australia', 'Sweden'];
        $firstNames = ['Alice', 'Bob', 'Carol', 'Dave', 'Eve', 'Frank', 'Grace', 'Henry', 'Ivy', 'Jack'];
        $lastNames = ['Smith', 'Jones', 'Brown', 'Wilson', 'Taylor', 'Clark', 'Lewis', 'Walker', 'Hall', 'Young'];
        $codes = ['ABC', 'abc', 'XYZ', 'xyz', 'DEF', 'def', 'GHI', 'ghi'];  // Mixed case for Binary testing

        for ($i = 1; $i <= 200; $i++) {
            // Generate dates across 2024
            $day = 1 + ($i % 28);
            $month = 1 + ($i % 12);
            $hour = $i % 24;
            $minute = ($i * 7) % 60;
            $second = ($i * 13) % 60;

            // Birth dates span 1960-2005
            $birthYear = 1960 + ($i % 46);
            $birthMonth = 1 + ($i % 12);
            $birthDay = 1 + ($i % 28);

            $table->insert([
                'id' => $i,
                'name' => $firstNames[$i % 10] . ' ' . $lastNames[($i / 10) % 10],
                'age' => $i % 20 === 0 ? null : 20 + ($i % 45),  // NULL every 20th row
                'dept' => $depts[$i % 8],
                'salary' => $i % 17 === 0 ? null : 40000 + ($i * 500) + (($i % 7) * 8000),  // NULL every 17th row
                'level' => 1 + ($i % 6),  // levels 1-6
                'country' => $i % 23 === 0 ? null : $countries[$i % 8],  // NULL every 23rd row
                'active' => $i % 2,  // 0 or 1
                'score' => $i % 13 === 0 ? null : 40.0 + ($i * 0.25) + (($i % 11) * 4.0),  // NULL every 13th row
                'birth_date' => $i % 29 === 0 ? null : sprintf('%04d-%02d-%02d', $birthYear, $birthMonth, $birthDay),
                'start_time' => $i % 31 === 0 ? null : sprintf('%02d:%02d:%02d', $hour, $minute, $second),
                'created_at' => $i % 19 === 0 ? null : sprintf('2024-%02d-%02d %02d:%02d:00', $month, $day, $hour, $minute),
                'code' => $i % 21 === 0 ? null : $codes[$i % 8],
            ]);
        }

        return $table;
    }

    protected function createImplementation(InMemoryTable $oracle): TableInterface
    {
        // Buffer rows to avoid repeated SQL queries during iteration
        $rows = iterator_to_array($oracle);
        $columns = array_values($oracle->getColumns());
        return new GeneratorTable(fn() => yield from $rows, ...$columns);
    }
};

exit($test->run());
