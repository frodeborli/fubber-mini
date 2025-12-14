<?php
/**
 * Abstract base test that compares a TableInterface implementation against
 * an InMemoryTable oracle using exhaustive filter permutations.
 *
 * Generates test cases based on the schema and actual data values,
 * comparing results between oracle and implementation for every combination.
 */

namespace mini\testing;

use mini\Test;
use mini\Table\TableInterface;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\ColumnType;
use mini\Table\Predicate;
use mini\Table\Set;

abstract class OracleComparisonTest extends Test
{
    /** Maximum depth of chained filters to test */
    protected int $maxFilterDepth = 3;

    /** Maximum number of test cases to run (prevents explosion) */
    protected int $maxTestCases = 10000;

    /** Track test cases run */
    private int $testCasesRun = 0;

    /** Track failures for summary */
    private array $failures = [];

    /**
     * Create the oracle table with test data
     *
     * Should return an InMemoryTable populated with test data.
     */
    abstract protected function createOracle(): InMemoryTable;

    /**
     * Create the implementation under test
     *
     * Receives the oracle so it can use the same data.
     * Typical implementation:
     *   return new GeneratorTable(fn() => yield from $oracle);
     */
    abstract protected function createImplementation(InMemoryTable $oracle): TableInterface;

    /**
     * Run all filter permutation tests
     */
    public function testFilterPermutations(): void
    {
        $oracle = $this->createOracle();
        $impl = $this->createImplementation($oracle);

        // Extract schema and sample values
        $columns = $oracle->getColumns();
        $sampleValues = $this->extractSampleValues($oracle);

        // Test base case (no filters)
        $this->compareResults($oracle, $impl, 'base');

        // Test single filters first
        $this->testSingleFilters($oracle, $impl, $columns, $sampleValues);

        // Test filter chains up to max depth
        $this->testFilterChains($oracle, $impl, $columns, $sampleValues);

        // Test with ordering
        $this->testWithOrdering($oracle, $impl, $columns, $sampleValues);

        // Test with limit/offset
        $this->testWithPagination($oracle, $impl, $columns, $sampleValues);

        // Report results
        if (!empty($this->failures)) {
            $this->fail(
                "Oracle comparison failed for " . count($this->failures) . " cases:\n" .
                implode("\n", array_slice($this->failures, 0, 10)) .
                (count($this->failures) > 10 ? "\n... and " . (count($this->failures) - 10) . " more" : "")
            );
        }
    }

    /**
     * Test OR predicates
     */
    public function testOrPredicates(): void
    {
        $oracle = $this->createOracle();
        $impl = $this->createImplementation($oracle);

        $columns = $oracle->getColumns();
        $sampleValues = $this->extractSampleValues($oracle);

        // Test simple OR with two predicates
        foreach ($columns as $col) {
            $values = $sampleValues[$col->name] ?? [];
            if (count($values) < 2) continue;

            $p = Predicate::from($oracle);
            $pImpl = Predicate::from($impl);

            // OR: first value OR second value
            $v1 = $values[0];
            $v2 = $values[1];

            $oracleResult = $oracle->or($p->eq($col->name, $v1), $p->eq($col->name, $v2));
            $implResult = $impl->or($pImpl->eq($col->name, $v1), $pImpl->eq($col->name, $v2));

            $this->compareResults(
                $oracleResult,
                $implResult,
                "or({$col->name}={$v1}, {$col->name}={$v2})"
            );

            if ($this->testCasesRun >= $this->maxTestCases) return;
        }
    }

    /**
     * Test union operations
     */
    public function testUnionOperations(): void
    {
        $oracle = $this->createOracle();
        $impl = $this->createImplementation($oracle);

        $columns = $oracle->getColumns();
        $sampleValues = $this->extractSampleValues($oracle);

        // Find a column with multiple distinct values
        foreach ($columns as $col) {
            $values = $sampleValues[$col->name] ?? [];
            if (count($values) < 2) continue;

            $v1 = $values[0];
            $v2 = $values[1];

            // Union of two filters
            $oracleResult = $oracle->eq($col->name, $v1)->union($oracle->eq($col->name, $v2));
            $implResult = $impl->eq($col->name, $v1)->union($impl->eq($col->name, $v2));

            $this->compareResults(
                $oracleResult,
                $implResult,
                "union({$col->name}={$v1}, {$col->name}={$v2})"
            );

            if ($this->testCasesRun >= $this->maxTestCases) return;
        }
    }

    /**
     * Test except operations
     */
    public function testExceptOperations(): void
    {
        $oracle = $this->createOracle();
        $impl = $this->createImplementation($oracle);

        $columns = $oracle->getColumns();
        $sampleValues = $this->extractSampleValues($oracle);

        foreach ($columns as $col) {
            $values = $sampleValues[$col->name] ?? [];
            if (empty($values)) continue;

            $v1 = $values[0];

            // Except: all rows except those matching filter
            $oracleResult = $oracle->except($oracle->eq($col->name, $v1));
            $implResult = $impl->except($impl->eq($col->name, $v1));

            $this->compareResults(
                $oracleResult,
                $implResult,
                "except({$col->name}={$v1})"
            );

            if ($this->testCasesRun >= $this->maxTestCases) return;
        }
    }

    /**
     * Test column projection
     */
    public function testColumnProjection(): void
    {
        $oracle = $this->createOracle();
        $impl = $this->createImplementation($oracle);

        $columns = array_keys($oracle->getColumns());
        $columnDefs = $oracle->getColumns();
        $sampleValues = $this->extractSampleValues($oracle);

        // Test single column projections
        foreach ($columns as $col) {
            $oracleResult = $oracle->columns($col);
            $implResult = $impl->columns($col);

            $this->compareResults($oracleResult, $implResult, "columns($col)");

            if ($this->testCasesRun >= $this->maxTestCases) return;
        }

        // Test two-column projections
        if (count($columns) >= 2) {
            for ($i = 0; $i < min(count($columns), 3); $i++) {
                for ($j = $i + 1; $j < min(count($columns), 4); $j++) {
                    $oracleResult = $oracle->columns($columns[$i], $columns[$j]);
                    $implResult = $impl->columns($columns[$i], $columns[$j]);

                    $this->compareResults(
                        $oracleResult,
                        $implResult,
                        "columns({$columns[$i]}, {$columns[$j]})"
                    );

                    if ($this->testCasesRun >= $this->maxTestCases) return;
                }
            }
        }

        // Test projection + filter on projected column
        foreach ($columns as $col) {
            $values = $sampleValues[$col] ?? [];
            if (empty($values)) continue;

            $value = $values[0];
            $oracleResult = $oracle->columns($col)->eq($col, $value);
            $implResult = $impl->columns($col)->eq($col, $value);

            $this->compareResults(
                $oracleResult,
                $implResult,
                "columns($col)->eq($col, " . $this->formatValue($value) . ")"
            );

            if ($this->testCasesRun >= $this->maxTestCases) return;
        }

        // Test filter then projection (filter on column that gets excluded)
        if (count($columns) >= 2) {
            $filterCol = $columns[0];
            $projectCol = $columns[1];
            $values = $sampleValues[$filterCol] ?? [];

            if (!empty($values)) {
                $value = $values[0];
                $oracleResult = $oracle->eq($filterCol, $value)->columns($projectCol);
                $implResult = $impl->eq($filterCol, $value)->columns($projectCol);

                $this->compareResults(
                    $oracleResult,
                    $implResult,
                    "eq($filterCol, " . $this->formatValue($value) . ")->columns($projectCol)"
                );
            }
        }

        // Test projection + ordering
        foreach ($columns as $col) {
            if ($this->testCasesRun >= $this->maxTestCases) return;

            $oracleResult = $oracle->columns($col)->order("$col ASC");
            $implResult = $impl->columns($col)->order("$col ASC");

            $this->compareResults(
                $oracleResult,
                $implResult,
                "columns($col)->order($col ASC)",
                ordered: true
            );
        }

        // Test projection + limit/offset
        if (count($columns) >= 2) {
            $col1 = $columns[0];
            $col2 = $columns[1];

            $oracleResult = $oracle->columns($col1, $col2)->limit(3);
            $implResult = $impl->columns($col1, $col2)->limit(3);

            $this->compareResults(
                $oracleResult,
                $implResult,
                "columns($col1, $col2)->limit(3)"
            );

            if ($this->testCasesRun >= $this->maxTestCases) return;

            $oracleResult = $oracle->columns($col1, $col2)->offset(2)->limit(3);
            $implResult = $impl->columns($col1, $col2)->offset(2)->limit(3);

            $this->compareResults(
                $oracleResult,
                $implResult,
                "columns($col1, $col2)->offset(2)->limit(3)"
            );
        }

        // Test combined: filter + order + project + paginate
        if (count($columns) >= 2) {
            $filterCol = $columns[0];
            $projectCols = array_slice($columns, 0, 2);
            $values = $sampleValues[$filterCol] ?? [];

            if (!empty($values) && count($values) >= 2) {
                // Use gt to get a subset of rows
                $value = $values[0];
                $def = $columnDefs[$filterCol];

                if ($def->type->isNumeric()) {
                    $oracleResult = $oracle
                        ->gt($filterCol, $value)
                        ->order("$filterCol DESC")
                        ->columns(...$projectCols)
                        ->limit(2);
                    $implResult = $impl
                        ->gt($filterCol, $value)
                        ->order("$filterCol DESC")
                        ->columns(...$projectCols)
                        ->limit(2);

                    $this->compareResults(
                        $oracleResult,
                        $implResult,
                        "gt($filterCol, " . $this->formatValue($value) . ")->order($filterCol DESC)->columns(...)->limit(2)",
                        ordered: true
                    );
                }
            }
        }
    }

    /**
     * Test has() membership checks
     */
    public function testHasMembership(): void
    {
        $oracle = $this->createOracle();
        $impl = $this->createImplementation($oracle);

        $columns = array_keys($oracle->getColumns());

        // Test has() on single-column projection
        foreach ($columns as $col) {
            $oracleProjected = $oracle->columns($col);
            $implProjected = $impl->columns($col);

            // Test with actual values from the table
            foreach ($oracleProjected as $row) {
                $member = (object)[$col => $row->$col];

                $oracleHas = $oracleProjected->has($member);
                $implHas = $implProjected->has($member);

                if ($oracleHas !== $implHas) {
                    $this->failures[] = "has() mismatch for columns($col): " .
                        "oracle=" . ($oracleHas ? 'true' : 'false') .
                        ", impl=" . ($implHas ? 'true' : 'false');
                }
                $this->testCasesRun++;

                if ($this->testCasesRun >= $this->maxTestCases) return;
            }

            // Test with a value that doesn't exist
            $nonExistent = (object)[$col => '__nonexistent__'];
            $oracleHas = $oracleProjected->has($nonExistent);
            $implHas = $implProjected->has($nonExistent);

            if ($oracleHas !== $implHas) {
                $this->failures[] = "has() mismatch for non-existent value in columns($col)";
            }
            $this->testCasesRun++;
        }

        // Test has() on multi-column projection
        if (count($columns) >= 2) {
            $col1 = $columns[0];
            $col2 = $columns[1];

            $oracleProjected = $oracle->columns($col1, $col2);
            $implProjected = $impl->columns($col1, $col2);

            // Test first few rows
            $count = 0;
            foreach ($oracleProjected as $row) {
                $member = (object)[$col1 => $row->$col1, $col2 => $row->$col2];

                $oracleHas = $oracleProjected->has($member);
                $implHas = $implProjected->has($member);

                if ($oracleHas !== $implHas) {
                    $this->failures[] = "has() mismatch for columns($col1, $col2)";
                }
                $this->testCasesRun++;

                if (++$count >= 5 || $this->testCasesRun >= $this->maxTestCases) break;
            }
        }

        // Test has() on filtered table
        if (count($columns) >= 1) {
            $col = $columns[0];
            $sampleValues = $this->extractSampleValues($oracle);
            $values = $sampleValues[$col] ?? [];

            if (!empty($values)) {
                $filterValue = $values[0];
                $oracleFiltered = $oracle->eq($col, $filterValue)->columns($col);
                $implFiltered = $impl->eq($col, $filterValue)->columns($col);

                $member = (object)[$col => $filterValue];
                $oracleHas = $oracleFiltered->has($member);
                $implHas = $implFiltered->has($member);

                if ($oracleHas !== $implHas) {
                    $this->failures[] = "has() mismatch after filter eq($col, ...)";
                }
                $this->testCasesRun++;
            }
        }
    }

    /**
     * Test exists() checks
     */
    public function testExists(): void
    {
        $oracle = $this->createOracle();
        $impl = $this->createImplementation($oracle);

        // Base table exists
        $this->assertEquals($oracle->exists(), $impl->exists(), "exists() mismatch on base table");

        $columns = array_keys($oracle->getColumns());
        $sampleValues = $this->extractSampleValues($oracle);

        // Test exists after filter that matches
        foreach ($columns as $col) {
            $values = $sampleValues[$col] ?? [];
            if (empty($values)) continue;

            $value = $values[0];
            $oracleFiltered = $oracle->eq($col, $value);
            $implFiltered = $impl->eq($col, $value);

            $oracleExists = $oracleFiltered->exists();
            $implExists = $implFiltered->exists();

            if ($oracleExists !== $implExists) {
                $this->failures[] = "exists() mismatch for eq($col, " . $this->formatValue($value) . ")";
            }
            $this->testCasesRun++;

            if ($this->testCasesRun >= $this->maxTestCases) return;
        }

        // Test exists after filter that doesn't match
        $col = $columns[0];
        $oracleFiltered = $oracle->eq($col, '__nonexistent_value__');
        $implFiltered = $impl->eq($col, '__nonexistent_value__');

        $oracleExists = $oracleFiltered->exists();
        $implExists = $implFiltered->exists();

        if ($oracleExists !== $implExists) {
            $this->failures[] = "exists() mismatch for non-matching filter";
        }
        $this->testCasesRun++;
    }

    /**
     * Test IN operator with Set
     */
    public function testInOperator(): void
    {
        $oracle = $this->createOracle();
        $impl = $this->createImplementation($oracle);

        $columns = $oracle->getColumns();
        $sampleValues = $this->extractSampleValues($oracle);

        // Test IN on each column type
        foreach ($columns as $col) {
            $values = $sampleValues[$col->name] ?? [];
            $nonNullValues = array_filter($values, fn($v) => $v !== null);

            if (count($nonNullValues) < 2) continue;

            // Create a set with first two values
            $setValues = array_slice($nonNullValues, 0, 2);
            $set = new Set($col->name, $setValues);

            $oracleResult = $oracle->in($col->name, $set);
            $implResult = $impl->in($col->name, $set);

            $this->compareResults(
                $oracleResult,
                $implResult,
                "in({$col->name}, [" . implode(', ', array_map(fn($v) => $this->formatValue($v), $setValues)) . "])"
            );

            if ($this->testCasesRun >= $this->maxTestCases) return;

            // Test IN with more values
            if (count($nonNullValues) >= 4) {
                $setValues = array_slice($nonNullValues, 0, 4);
                $set = new Set($col->name, $setValues);

                $oracleResult = $oracle->in($col->name, $set);
                $implResult = $impl->in($col->name, $set);

                $this->compareResults(
                    $oracleResult,
                    $implResult,
                    "in({$col->name}, [4 values])"
                );
            }

            // Test IN combined with another filter
            if (count($columns) >= 2) {
                $otherCols = array_filter(
                    array_keys($columns),
                    fn($c) => $c !== $col->name
                );
                $otherCol = reset($otherCols);
                $otherValues = $sampleValues[$otherCol] ?? [];

                if (!empty($otherValues)) {
                    $otherValue = $otherValues[0];
                    $setValues = array_slice($nonNullValues, 0, 2);
                    $set = new Set($col->name, $setValues);

                    $oracleResult = $oracle->in($col->name, $set)->eq($otherCol, $otherValue);
                    $implResult = $impl->in($col->name, $set)->eq($otherCol, $otherValue);

                    $this->compareResults(
                        $oracleResult,
                        $implResult,
                        "in({$col->name}, ...)->eq($otherCol, " . $this->formatValue($otherValue) . ")"
                    );
                }
            }

            // Test IN + order + limit
            $setValues = array_slice($nonNullValues, 0, 3);
            $set = new Set($col->name, $setValues);

            $oracleResult = $oracle->in($col->name, $set)->order("{$col->name} ASC")->limit(5);
            $implResult = $impl->in($col->name, $set)->order("{$col->name} ASC")->limit(5);

            $this->compareResults(
                $oracleResult,
                $implResult,
                "in({$col->name}, ...)->order()->limit(5)",
                ordered: true
            );

            if ($this->testCasesRun >= $this->maxTestCases) return;
        }
    }

    /**
     * Test NULL handling (IS NULL via eq(col, null))
     */
    public function testNullHandling(): void
    {
        $oracle = $this->createOracle();
        $impl = $this->createImplementation($oracle);

        $columns = array_keys($oracle->getColumns());
        $sampleValues = $this->extractSampleValues($oracle);

        foreach ($columns as $col) {
            $values = $sampleValues[$col] ?? [];

            // Test eq(col, null) - IS NULL
            if (in_array(null, $values, true)) {
                $oracleResult = $oracle->eq($col, null);
                $implResult = $impl->eq($col, null);

                $this->compareResults(
                    $oracleResult,
                    $implResult,
                    "eq($col, null) [IS NULL]"
                );

                // Test IS NULL combined with another filter
                if (count($columns) >= 2) {
                    $otherCol = $columns[0] === $col ? $columns[1] : $columns[0];
                    $otherValues = array_filter($sampleValues[$otherCol] ?? [], fn($v) => $v !== null);

                    if (!empty($otherValues)) {
                        $otherValue = reset($otherValues);
                        $oracleResult = $oracle->eq($col, null)->eq($otherCol, $otherValue);
                        $implResult = $impl->eq($col, null)->eq($otherCol, $otherValue);

                        $this->compareResults(
                            $oracleResult,
                            $implResult,
                            "eq($col, null)->eq($otherCol, " . $this->formatValue($otherValue) . ")"
                        );
                    }
                }

                if ($this->testCasesRun >= $this->maxTestCases) return;
            }

            // Verify comparison operators exclude NULL values
            $nonNullValues = array_filter($values, fn($v) => $v !== null);
            if (!empty($nonNullValues)) {
                $value = reset($nonNullValues);

                // lt/lte/gt/gte should not include NULL rows
                foreach (['lt', 'lte', 'gt', 'gte'] as $op) {
                    $oracleResult = $this->applyFilter($oracle, $op, $col, $value);
                    $implResult = $this->applyFilter($impl, $op, $col, $value);

                    // Check that NULL values are excluded
                    $implHasNull = false;
                    foreach ($implResult as $row) {
                        if ($row->$col === null) {
                            $implHasNull = true;
                            break;
                        }
                    }

                    if ($implHasNull) {
                        $this->failures[] = "$op($col, " . $this->formatValue($value) . ") includes NULL rows";
                    }
                    $this->testCasesRun++;

                    if ($this->testCasesRun >= $this->maxTestCases) return;
                }
            }
        }
    }

    /**
     * Test LIKE operator patterns
     */
    public function testLikeOperator(): void
    {
        $oracle = $this->createOracle();
        $impl = $this->createImplementation($oracle);

        $columns = $oracle->getColumns();
        $sampleValues = $this->extractSampleValues($oracle);

        foreach ($columns as $col) {
            if ($col->type !== ColumnType::Text) continue;

            $values = array_filter($sampleValues[$col->name] ?? [], fn($v) => $v !== null && is_string($v));
            if (empty($values)) continue;

            $sampleValue = reset($values);

            // Test prefix match: 'Foo%'
            if (strlen($sampleValue) >= 2) {
                $prefix = substr($sampleValue, 0, 2);
                $oracleResult = $oracle->like($col->name, "$prefix%");
                $implResult = $impl->like($col->name, "$prefix%");

                $this->compareResults(
                    $oracleResult,
                    $implResult,
                    "like({$col->name}, '$prefix%')"
                );
            }

            // Test suffix match: '%bar'
            if (strlen($sampleValue) >= 2) {
                $suffix = substr($sampleValue, -2);
                $oracleResult = $oracle->like($col->name, "%$suffix");
                $implResult = $impl->like($col->name, "%$suffix");

                $this->compareResults(
                    $oracleResult,
                    $implResult,
                    "like({$col->name}, '%$suffix')"
                );
            }

            // Test contains: '%foo%'
            if (strlen($sampleValue) >= 3) {
                $middle = substr($sampleValue, 1, 2);
                $oracleResult = $oracle->like($col->name, "%$middle%");
                $implResult = $impl->like($col->name, "%$middle%");

                $this->compareResults(
                    $oracleResult,
                    $implResult,
                    "like({$col->name}, '%$middle%')"
                );
            }

            // Test single char wildcard: 'F_o'
            if (strlen($sampleValue) >= 3) {
                $pattern = substr($sampleValue, 0, 1) . '_' . substr($sampleValue, 2, 1);
                $oracleResult = $oracle->like($col->name, $pattern);
                $implResult = $impl->like($col->name, $pattern);

                $this->compareResults(
                    $oracleResult,
                    $implResult,
                    "like({$col->name}, '$pattern')"
                );
            }

            // Test LIKE + other filter
            $otherCols = array_filter(array_keys($columns), fn($c) => $c !== $col->name);
            if (!empty($otherCols)) {
                $otherCol = reset($otherCols);
                $otherValues = array_filter($sampleValues[$otherCol] ?? [], fn($v) => $v !== null);

                if (!empty($otherValues) && strlen($sampleValue) >= 2) {
                    $prefix = substr($sampleValue, 0, 2);
                    $otherValue = reset($otherValues);

                    $oracleResult = $oracle->like($col->name, "$prefix%")->eq($otherCol, $otherValue);
                    $implResult = $impl->like($col->name, "$prefix%")->eq($otherCol, $otherValue);

                    $this->compareResults(
                        $oracleResult,
                        $implResult,
                        "like({$col->name}, '$prefix%')->eq($otherCol, ...)"
                    );
                }
            }

            if ($this->testCasesRun >= $this->maxTestCases) return;
        }
    }

    /**
     * Extract sample values from each column for testing
     */
    protected function extractSampleValues(InMemoryTable $oracle): array
    {
        $values = [];
        $columns = $oracle->getColumns();

        foreach ($columns as $col) {
            $values[$col->name] = [];
        }

        foreach ($oracle as $row) {
            foreach ($columns as $col) {
                $val = $row->{$col->name} ?? null;
                if (!in_array($val, $values[$col->name], true)) {
                    $values[$col->name][] = $val;
                }
            }
        }

        // Add boundary values for numeric columns
        foreach ($columns as $col) {
            if ($col->type === ColumnType::Int || $col->type === ColumnType::Float) {
                $colValues = array_filter($values[$col->name], fn($v) => $v !== null);
                if (!empty($colValues)) {
                    $min = min($colValues);
                    $max = max($colValues);
                    // Add values just outside the range
                    if (!in_array($min - 1, $values[$col->name], true)) {
                        $values[$col->name][] = $min - 1;
                    }
                    if (!in_array($max + 1, $values[$col->name], true)) {
                        $values[$col->name][] = $max + 1;
                    }
                }
            }
        }

        return $values;
    }

    /**
     * Get applicable operators for a column type
     */
    protected function getOperators(ColumnDef $col): array
    {
        // All types support eq and comparison operators
        $ops = ['eq', 'lt', 'lte', 'gt', 'gte'];

        if ($col->type === ColumnType::Text) {
            $ops[] = 'like';
        }

        return $ops;
    }

    /**
     * Test single filters on each column
     */
    private function testSingleFilters(
        InMemoryTable $oracle,
        TableInterface $impl,
        array $columns,
        array $sampleValues
    ): void {
        foreach ($columns as $col) {
            $ops = $this->getOperators($col);
            $values = $sampleValues[$col->name] ?? [];

            foreach ($ops as $op) {
                foreach ($values as $value) {
                    if ($this->testCasesRun >= $this->maxTestCases) return;

                    // Skip null for comparison operators
                    if ($value === null && in_array($op, ['lt', 'lte', 'gt', 'gte'])) {
                        continue;
                    }

                    $oracleFiltered = $this->applyFilter($oracle, $op, $col->name, $value);
                    $implFiltered = $this->applyFilter($impl, $op, $col->name, $value);

                    $this->compareResults(
                        $oracleFiltered,
                        $implFiltered,
                        "{$op}({$col->name}, " . $this->formatValue($value) . ")"
                    );
                }
            }
        }
    }

    /**
     * Test chained filters up to max depth
     */
    private function testFilterChains(
        InMemoryTable $oracle,
        TableInterface $impl,
        array $columns,
        array $sampleValues
    ): void {
        // Generate filter chains recursively
        $this->generateFilterChains(
            $oracle,
            $impl,
            $columns,
            $sampleValues,
            [],
            1
        );
    }

    /**
     * Recursively generate and test filter chains
     */
    private function generateFilterChains(
        TableInterface $oracleCurrent,
        TableInterface $implCurrent,
        array $columns,
        array $sampleValues,
        array $appliedFilters,
        int $depth
    ): void {
        if ($depth > $this->maxFilterDepth) return;
        if ($this->testCasesRun >= $this->maxTestCases) return;

        foreach ($columns as $col) {
            $ops = $this->getOperators($col);
            // Limit values per column at deeper levels
            $values = array_slice($sampleValues[$col->name] ?? [], 0, $depth === 1 ? 5 : 2);

            foreach ($ops as $op) {
                foreach ($values as $value) {
                    if ($this->testCasesRun >= $this->maxTestCases) return;

                    // Skip null for comparison operators
                    if ($value === null && in_array($op, ['lt', 'lte', 'gt', 'gte'])) {
                        continue;
                    }

                    $newOracle = $this->applyFilter($oracleCurrent, $op, $col->name, $value);
                    $newImpl = $this->applyFilter($implCurrent, $op, $col->name, $value);

                    $filterDesc = "{$op}({$col->name}, " . $this->formatValue($value) . ")";
                    $chainDesc = empty($appliedFilters)
                        ? $filterDesc
                        : implode('->', $appliedFilters) . "->" . $filterDesc;

                    $this->compareResults($newOracle, $newImpl, $chainDesc);

                    // Recurse if not at max depth and oracle has results
                    if ($depth < $this->maxFilterDepth && $newOracle->count() > 0) {
                        $this->generateFilterChains(
                            $newOracle,
                            $newImpl,
                            $columns,
                            $sampleValues,
                            [...$appliedFilters, $filterDesc],
                            $depth + 1
                        );
                    }
                }
            }
        }
    }

    /**
     * Test filters combined with ordering
     */
    private function testWithOrdering(
        InMemoryTable $oracle,
        TableInterface $impl,
        array $columns,
        array $sampleValues
    ): void {
        $columnNames = array_keys($columns);

        // Test ordering on each column
        foreach ($columnNames as $col) {
            foreach (['ASC', 'DESC'] as $dir) {
                if ($this->testCasesRun >= $this->maxTestCases) return;

                $oracleOrdered = $oracle->order("$col $dir");
                $implOrdered = $impl->order("$col $dir");

                $this->compareResults($oracleOrdered, $implOrdered, "order($col $dir)", ordered: true);
            }
        }

        // Test ordering combined with a filter
        foreach ($columns as $col) {
            $values = $sampleValues[$col->name] ?? [];
            if (empty($values)) continue;

            $value = $values[0];

            foreach ($columnNames as $orderCol) {
                if ($this->testCasesRun >= $this->maxTestCases) return;

                $oracleResult = $oracle->eq($col->name, $value)->order("$orderCol ASC");
                $implResult = $impl->eq($col->name, $value)->order("$orderCol ASC");

                $this->compareResults(
                    $oracleResult,
                    $implResult,
                    "eq({$col->name}, " . $this->formatValue($value) . ")->order($orderCol ASC)",
                    ordered: true
                );
            }

            break; // Just test one filter column to avoid explosion
        }
    }

    /**
     * Test filters combined with limit/offset
     */
    private function testWithPagination(
        InMemoryTable $oracle,
        TableInterface $impl,
        array $columns,
        array $sampleValues
    ): void {
        $totalRows = $oracle->count();

        // Test limit alone
        foreach ([1, 2, $totalRows, $totalRows + 1] as $limit) {
            if ($this->testCasesRun >= $this->maxTestCases) return;

            $oracleResult = $oracle->limit($limit);
            $implResult = $impl->limit($limit);

            $this->compareResults($oracleResult, $implResult, "limit($limit)");
        }

        // Test offset alone
        foreach ([1, 2, $totalRows - 1] as $offset) {
            if ($this->testCasesRun >= $this->maxTestCases) return;
            if ($offset < 0) continue;

            $oracleResult = $oracle->offset($offset);
            $implResult = $impl->offset($offset);

            $this->compareResults($oracleResult, $implResult, "offset($offset)");
        }

        // Test limit + offset
        if ($this->testCasesRun < $this->maxTestCases) {
            $oracleResult = $oracle->offset(1)->limit(2);
            $implResult = $impl->offset(1)->limit(2);

            $this->compareResults($oracleResult, $implResult, "offset(1)->limit(2)");
        }

        // Test filter + order + limit
        if ($this->testCasesRun < $this->maxTestCases && count($columns) > 0) {
            $col = array_key_first($columns);

            $oracleResult = $oracle->order("$col ASC")->limit(3);
            $implResult = $impl->order("$col ASC")->limit(3);

            $this->compareResults($oracleResult, $implResult, "order($col ASC)->limit(3)", ordered: true);
        }
    }

    /**
     * Apply a filter operation to a table
     */
    private function applyFilter(TableInterface $table, string $op, string $column, mixed $value): TableInterface
    {
        return match ($op) {
            'eq' => $table->eq($column, $value),
            'lt' => $table->lt($column, $value),
            'lte' => $table->lte($column, $value),
            'gt' => $table->gt($column, $value),
            'gte' => $table->gte($column, $value),
            'like' => $table->like($column, "%$value%"),
            'in' => $table->in($column, new Set($column, [$value])),
            default => throw new \InvalidArgumentException("Unknown operator: $op"),
        };
    }

    /**
     * Compare results between oracle and implementation
     *
     * Compares row contents, but NOT row IDs (which are implementation-specific).
     * A CSV implementation might use byte offsets, SQLite uses rowid, etc.
     *
     * Count comparison happens AFTER iteration to allow implementations to
     * memoize count during materialize(), avoiding double iteration.
     *
     * @param bool $ordered If true, compare in order; if false, compare as sets
     */
    private function compareResults(
        TableInterface $oracle,
        TableInterface $impl,
        string $description,
        bool $ordered = false
    ): void {
        $this->testCasesRun++;

        // Collect rows first (ignore row IDs - they're implementation-specific)
        // This allows implementations to memoize count during iteration
        $oracleRows = [];
        foreach ($oracle as $row) {
            $oracleRows[] = (array) $row;
        }

        $implRows = [];
        foreach ($impl as $row) {
            $implRows[] = (array) $row;
        }

        // Compare counts after iteration (may be O(1) if memoized during iteration)
        $oracleCount = $oracle->count();
        $implCount = $impl->count();

        if ($oracleCount !== $implCount) {
            $this->failures[] = "$description: count mismatch (oracle=$oracleCount, impl=$implCount)";
            return;
        }

        // Verify collected rows match counts
        if (count($oracleRows) !== $oracleCount || count($implRows) !== $implCount) {
            $this->failures[] = "$description: iteration count doesn't match count() result";
            return;
        }

        if ($ordered) {
            // Compare row contents in order
            if ($oracleRows !== $implRows) {
                for ($i = 0; $i < count($oracleRows); $i++) {
                    if (!isset($implRows[$i])) {
                        $this->failures[] = "$description: impl missing row at position $i";
                        return;
                    }
                    if ($oracleRows[$i] !== $implRows[$i]) {
                        $this->failures[] = "$description: row mismatch at position $i";
                        return;
                    }
                }
                $this->failures[] = "$description: row content mismatch";
            }
        } else {
            // Compare as sets (order doesn't matter for unordered queries)
            $oracleSorted = $oracleRows;
            $implSorted = $implRows;
            usort($oracleSorted, fn($a, $b) => json_encode($a) <=> json_encode($b));
            usort($implSorted, fn($a, $b) => json_encode($a) <=> json_encode($b));

            if ($oracleSorted !== $implSorted) {
                $this->failures[] = "$description: row set mismatch";
            }
        }
    }

    /**
     * Format a value for display in test descriptions
     */
    private function formatValue(mixed $value): string
    {
        if ($value === null) return 'null';
        if (is_string($value)) return "'$value'";
        return (string) $value;
    }
}
