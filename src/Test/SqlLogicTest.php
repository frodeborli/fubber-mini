<?php

namespace mini\Test;

use mini\Database\DatabaseInterface;

/**
 * SQLLogicTest parser and runner
 *
 * Parses and executes SQLLogicTest format files (.test) against any DatabaseInterface.
 * See: https://www.sqlite.org/sqllogictest/doc/trunk/about.wiki
 *
 * Usage:
 *   $runner = new SqlLogicTest($pdo, $vdb);
 *   $results = $runner->runFile('path/to/test.test');
 */
class SqlLogicTest
{
    /** @var array<string, DatabaseInterface> */
    private array $backends = [];

    private int $hashThreshold = 8;
    private bool $stopOnError = false;
    private bool $verbose = false;
    private bool $printQuery = false;
    private bool $printResults = false;
    private bool $printErrors = false;
    private ?string $includeQueryPattern = null;
    private ?string $excludeQueryPattern = null;

    public function __construct()
    {
    }

    /**
     * Register a backend to test against
     */
    public function addBackend(string $name, DatabaseInterface $db): self
    {
        $this->backends[$name] = $db;
        return $this;
    }

    /**
     * Stop on first error (useful for debugging)
     */
    public function stopOnError(bool $stop = true): self
    {
        $this->stopOnError = $stop;
        return $this;
    }

    /**
     * Enable verbose mode - print each query to STDERR before running
     */
    public function verbose(bool $verbose = true): self
    {
        $this->verbose = $verbose;
        return $this;
    }

    /**
     * Print each query before running it
     */
    public function printQuery(bool $print = true): self
    {
        $this->printQuery = $print;
        return $this;
    }

    /**
     * Print actual result rows from each backend (not normalized/hashed)
     */
    public function printResults(bool $print = true): self
    {
        $this->printResults = $print;
        return $this;
    }

    /**
     * Print VDB exceptions and parse errors
     */
    public function printErrors(bool $print = true): self
    {
        $this->printErrors = $print;
        return $this;
    }

    /**
     * Only run queries matching regex pattern (ECMA style, case-insensitive)
     */
    public function includeQuery(string $pattern): self
    {
        $this->includeQueryPattern = $pattern;
        return $this;
    }

    /**
     * Skip queries matching regex pattern (ECMA style, case-insensitive)
     */
    public function excludeQuery(string $pattern): self
    {
        $this->excludeQueryPattern = $pattern;
        return $this;
    }

    /**
     * Check if SQL matches query filters
     */
    private function matchesQueryFilter(string $sql): bool
    {
        // Use chr(1) as delimiter so user patterns don't need escaping
        $d = chr(1);
        if ($this->includeQueryPattern !== null) {
            if (!preg_match("{$d}{$this->includeQueryPattern}{$d}i", $sql)) {
                return false;
            }
        }
        if ($this->excludeQueryPattern !== null) {
            if (preg_match("{$d}{$this->excludeQueryPattern}{$d}i", $sql)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Run a .test file and return results
     *
     * @return SqlLogicTestResult
     */
    public function runFile(string $path): SqlLogicTestResult
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Test file not found: $path");
        }

        $content = file_get_contents($path);
        return $this->run($content, basename($path));
    }

    /**
     * Run test content
     */
    public function run(string $content, string $name = 'inline'): SqlLogicTestResult
    {
        $records = $this->parse($content);
        $result = new SqlLogicTestResult($name);
        $this->halted = []; // Reset halt state for new run

        foreach ($records as $record) {
            $this->executeRecord($record, $result);

            if ($this->stopOnError && $result->hasFailures()) {
                break;
            }
        }

        return $result;
    }

    /**
     * Parse test file into records
     *
     * @return array<SqlLogicTestRecord>
     */
    public function parse(string $content): array
    {
        $records = [];
        // Normalize line endings (handle CRLF from Windows)
        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "\n", $content);
        $lines = explode("\n", $content);
        $i = 0;
        $lineCount = count($lines);

        while ($i < $lineCount) {
            $line = $lines[$i];

            // Skip empty lines and comments
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                $i++;
                continue;
            }

            // Parse conditional (skipif/onlyif)
            $skipIf = null;
            $onlyIf = null;

            while (preg_match('/^(skipif|onlyif)\s+(\S+)/', $line, $m)) {
                if ($m[1] === 'skipif') {
                    $skipIf = $m[2];
                } else {
                    $onlyIf = $m[2];
                }
                $i++;
                $line = $lines[$i] ?? '';
            }

            // Parse record type
            if (str_starts_with($line, 'statement ')) {
                $record = $this->parseStatement($lines, $i);
                $record->skipIf = $skipIf;
                $record->onlyIf = $onlyIf;
                $records[] = $record;
            } elseif (str_starts_with($line, 'query ')) {
                $record = $this->parseQuery($lines, $i);
                $record->skipIf = $skipIf;
                $record->onlyIf = $onlyIf;
                $records[] = $record;
            } elseif (str_starts_with($line, 'hash-threshold ')) {
                $this->hashThreshold = (int) substr($line, 15);
                $i++;
            } elseif ($line === 'halt') {
                // halt respects onlyif/skipif - create a halt record
                $record = new SqlLogicTestRecord('halt');
                $record->onlyIf = $onlyIf;
                $record->skipIf = $skipIf;
                $records[] = $record;
                $i++;
            } else {
                $i++;
            }
        }

        return $records;
    }

    private function parseStatement(array $lines, int &$i): SqlLogicTestRecord
    {
        $header = $lines[$i];
        $expectError = str_contains($header, 'error');
        $i++;

        // Collect SQL until blank line
        $sql = '';
        while ($i < count($lines) && trim($lines[$i]) !== '') {
            $sql .= $lines[$i] . "\n";
            $i++;
        }

        $record = new SqlLogicTestRecord('statement');
        $record->sql = trim($sql);
        $record->expectError = $expectError;
        $record->lineNumber = $i;

        return $record;
    }

    private function parseQuery(array $lines, int &$i): SqlLogicTestRecord
    {
        $header = $lines[$i];
        $i++;

        // Parse header: query <types> [sort] [label]
        $parts = preg_split('/\s+/', $header);
        $types = $parts[1] ?? '';
        $sortMode = 'nosort';
        $label = null;

        for ($j = 2; $j < count($parts); $j++) {
            $part = $parts[$j];
            if (in_array($part, ['nosort', 'rowsort', 'valuesort'])) {
                $sortMode = $part;
            } elseif (str_starts_with($part, 'label-')) {
                $label = $part;
            }
        }

        // Collect SQL until ----
        $sql = '';
        while ($i < count($lines) && trim($lines[$i]) !== '----') {
            $sql .= $lines[$i] . "\n";
            $i++;
        }

        // Skip ----
        if ($i < count($lines) && trim($lines[$i]) === '----') {
            $i++;
        }

        // Collect expected results until blank line
        $expected = [];
        while ($i < count($lines) && trim($lines[$i]) !== '') {
            $expected[] = $lines[$i];
            $i++;
        }

        $record = new SqlLogicTestRecord('query');
        $record->sql = trim($sql);
        $record->types = $types;
        $record->sortMode = $sortMode;
        $record->label = $label;
        $record->expected = $expected;
        $record->lineNumber = $i;

        return $record;
    }

    /** @var array<string, bool> Backends that have halted */
    private array $halted = [];

    /** @var array<string, array> Last query's raw results per backend (for stop-on-error output) */
    private array $lastQueryResults = [];

    private function executeRecord(SqlLogicTestRecord $record, SqlLogicTestResult $result): void
    {
        // Skip queries not matching filter (statements always run for setup)
        if ($record->type === 'query' && !$this->matchesQueryFilter($record->sql)) {
            return;
        }

        // Print query once (before iterating backends)
        if ($this->printQuery && $record->type === 'query') {
            fprintf(STDERR, "\n=== Query (line %d) ===\n%s\n", $record->lineNumber, $record->sql);
        }

        // Clear last query results for stop-on-error tracking
        $this->lastQueryResults = [];
        $failureCountBefore = count($result->getFailures());

        foreach ($this->backends as $name => $db) {
            // Skip if this backend has halted
            if ($this->halted[$name] ?? false) {
                continue;
            }

            // Handle skipif/onlyif
            if ($record->skipIf === $name) {
                $result->skip($name, $record, 'skipif');
                continue;
            }
            if ($record->onlyIf !== null && $record->onlyIf !== $name) {
                $result->skip($name, $record, 'onlyif');
                continue;
            }

            // Handle halt record
            if ($record->type === 'halt') {
                $this->halted[$name] = true;
                continue;
            }

            $start = hrtime(true);
            if ($record->type === 'statement') {
                $this->executeStatement($name, $db, $record, $result);
            } else {
                $this->executeQuery($name, $db, $record, $result);
            }
            $result->addTime($name, (hrtime(true) - $start) / 1e9);
        }

        // Track shared test times for fair comparison
        $result->finalizeRecord();

        // Stop-on-error: print detailed comparison when VDB fails
        if ($this->stopOnError && $record->type === 'query') {
            $failures = $result->getFailures();
            $newFailures = array_slice($failures, $failureCountBefore);
            $vdbFailed = false;
            foreach ($newFailures as $f) {
                if ($f['backend'] !== 'sqlite') {
                    $vdbFailed = true;
                    break;
                }
            }
            if ($vdbFailed) {
                $this->printStopOnErrorComparison($record, $newFailures);
            }
        }
    }

    /**
     * Print detailed comparison for stop-on-error mode
     */
    private function printStopOnErrorComparison(SqlLogicTestRecord $record, array $failures): void
    {
        fprintf(STDERR, "\n" . str_repeat("=", 80) . "\n");
        fprintf(STDERR, "STOP ON ERROR - Query at line %d\n", $record->lineNumber);
        fprintf(STDERR, str_repeat("=", 80) . "\n");
        fprintf(STDERR, "\nSQL:\n%s\n", $record->sql);

        // Print expected result
        fprintf(STDERR, "\n--- Expected (from test file) ---\n");
        if ($this->isHashResult($record->expected)) {
            fprintf(STDERR, "%s\n", $record->expected[0]);
        } else {
            foreach (array_slice($record->expected, 0, 30) as $line) {
                fprintf(STDERR, "%s\n", $line);
            }
            if (count($record->expected) > 30) {
                fprintf(STDERR, "... (%d more values)\n", count($record->expected) - 30);
            }
        }

        // Print actual results from each backend
        foreach ($this->lastQueryResults as $backend => $rows) {
            fprintf(STDERR, "\n--- %s actual results ---\n", strtoupper($backend));
            if ($rows === null) {
                fprintf(STDERR, "(error - see above)\n");
            } else {
                $this->printResultRows($backend, $rows);
            }
        }

        // Print failure messages
        fprintf(STDERR, "\n--- Failure details ---\n");
        foreach ($failures as $f) {
            fprintf(STDERR, "[%s] %s\n", $f['backend'], $f['message']);
        }
        fprintf(STDERR, str_repeat("=", 80) . "\n\n");
    }

    private function executeStatement(string $name, DatabaseInterface $db, SqlLogicTestRecord $record, SqlLogicTestResult $result): void
    {
        try {
            $db->exec($record->sql);

            if ($record->expectError) {
                $result->fail($name, $record, 'Expected error but succeeded');
            } else {
                $result->pass($name, $record);
            }
        } catch (\Throwable $e) {
            if ($record->expectError) {
                $result->pass($name, $record);
            } elseif (str_contains($e->getMessage(), 'VDB limitation:')) {
                $result->skip($name, $record, $e->getMessage());
            } else {
                $result->fail($name, $record, $e->getMessage());
            }
        }
    }

    private function executeQuery(string $name, DatabaseInterface $db, SqlLogicTestRecord $record, SqlLogicTestResult $result): void
    {
        if ($this->verbose) {
            fprintf(STDERR, "[%s] Line %d: %s\n", $name, $record->lineNumber, substr(str_replace("\n", " ", $record->sql), 0, 80));
        }

        try {
            $rows = iterator_to_array($db->query($record->sql));

            // Store raw results for stop-on-error comparison
            $this->lastQueryResults[$name] = $rows;

            if ($this->printResults) {
                $this->printResultRows($name, $rows);
            }

            $actual = $this->formatResults($rows, $record->types);

            // Apply sort mode
            $nColumns = strlen($record->types);
            $actual = $this->sortResults($actual, $record->sortMode, $nColumns);

            // Check expected - could be hash or inline values
            if ($this->isHashResult($record->expected)) {
                $expectedHash = $this->extractHash($record->expected);
                $actualHash = $this->hashResults($actual);

                if ($actualHash === $expectedHash) {
                    $result->pass($name, $record);
                } else {
                    // Include actual values in failure message for debugging
                    $preview = array_slice($actual, 0, 20);
                    $valuesStr = implode('|', $preview);
                    if (count($actual) > 20) $valuesStr .= '|...(' . count($actual) . ' total)';
                    $result->fail($name, $record, "Hash mismatch: expected $expectedHash, got $actualHash\nValues: $valuesStr");
                }
            } else {
                // Inline comparison
                $expected = $this->sortResults($record->expected, $record->sortMode, $nColumns);

                if ($actual === $expected) {
                    $result->pass($name, $record);
                } else {
                    $result->fail($name, $record, $this->diffResults($expected, $actual));
                }
            }
        } catch (\mini\Database\QueryTimeoutException $e) {
            // Timeout - halt this backend for remaining tests
            $this->halted[$name] = true;
            $this->lastQueryResults[$name] = null; // Mark as error
            if ($this->printErrors) {
                fprintf(STDERR, "[%s] TIMEOUT: %s\n", $name, $e->getMessage());
            }
            $result->fail($name, $record, $e->getMessage());
        } catch (\Throwable $e) {
            $this->lastQueryResults[$name] = null; // Mark as error

            // Known limitations are skipped, not failed
            if (str_contains($e->getMessage(), 'VDB limitation:')) {
                $result->skip($name, $record, $e->getMessage());
                return;
            }

            if ($this->printErrors) {
                fprintf(STDERR, "[%s] ERROR: %s\n", $name, $e->getMessage());
            }
            $result->fail($name, $record, $e->getMessage());
        }
    }

    /**
     * Print result rows as a table
     */
    private function printResultRows(string $backend, array $rows): void
    {
        fprintf(STDERR, "--- %s results (%d rows) ---\n", $backend, count($rows));
        if (empty($rows)) {
            fprintf(STDERR, "(empty)\n");
            return;
        }

        // Get column headers from first row
        $first = (array) $rows[0];
        $headers = array_keys($first);

        // Calculate column widths
        $widths = [];
        foreach ($headers as $h) {
            $widths[$h] = strlen($h);
        }
        foreach ($rows as $row) {
            foreach ((array) $row as $col => $val) {
                $len = strlen($this->formatCellForPrint($val));
                if ($len > $widths[$col]) {
                    $widths[$col] = min($len, 30); // Cap at 30 chars
                }
            }
        }

        // Print header
        $line = '';
        foreach ($headers as $h) {
            $line .= str_pad($h, $widths[$h] + 2);
        }
        fprintf(STDERR, "%s\n", $line);
        fprintf(STDERR, "%s\n", str_repeat('-', strlen($line)));

        // Print rows (limit to 20)
        $count = 0;
        foreach ($rows as $row) {
            $line = '';
            foreach ((array) $row as $col => $val) {
                $formatted = $this->formatCellForPrint($val);
                if (strlen($formatted) > 30) {
                    $formatted = substr($formatted, 0, 27) . '...';
                }
                $line .= str_pad($formatted, $widths[$col] + 2);
            }
            fprintf(STDERR, "%s\n", $line);
            if (++$count >= 20) {
                fprintf(STDERR, "... (%d more rows)\n", count($rows) - 20);
                break;
            }
        }
    }

    private function formatCellForPrint(mixed $val): string
    {
        if ($val === null) return 'NULL';
        if ($val === '') return '(empty)';
        return (string) $val;
    }

    /**
     * Sort results according to SQLLogicTest sort mode
     *
     * - nosort: no sorting
     * - rowsort: sort rows (groups of nColumns values) lexicographically
     * - valuesort: sort all individual values
     */
    private function sortResults(array $values, string $sortMode, int $nColumns): array
    {
        if ($sortMode === 'nosort' || $nColumns === 0) {
            return $values;
        }

        if ($sortMode === 'valuesort') {
            sort($values, SORT_STRING);
            return $values;
        }

        if ($sortMode === 'rowsort') {
            // Group into rows, sort rows, flatten back
            $rows = array_chunk($values, $nColumns);
            usort($rows, function ($a, $b) {
                // Compare rows by concatenating values with newlines (SQLLogicTest behavior)
                return strcmp(implode("\n", $a), implode("\n", $b));
            });
            return array_merge(...$rows);
        }

        return $values;
    }

    /**
     * Format query results according to SQLLogicTest conventions
     * Each value becomes one line (not tab-separated rows)
     */
    private function formatResults(array $rows, string $types): array
    {
        $formatted = [];

        foreach ($rows as $row) {
            $i = 0;
            foreach ((array) $row as $value) {
                $type = $types[$i] ?? 'T';
                $formatted[] = $this->formatValue($value, $type);
                $i++;
            }
        }

        return $formatted;
    }

    private function formatValue(mixed $value, string $type): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($value === '') {
            return '(empty)';
        }

        return match ($type) {
            'I' => sprintf('%d', $value),
            'R' => sprintf('%.3f', $value),
            'T' => (string) $value,
            default => (string) $value,
        };
    }

    private function isHashResult(array $expected): bool
    {
        return count($expected) === 1 && str_contains($expected[0], 'hashing to');
    }

    private function extractHash(array $expected): string
    {
        if (preg_match('/hashing to ([a-f0-9]+)/', $expected[0], $m)) {
            return $m[1];
        }
        return '';
    }

    private function hashResults(array $results): string
    {
        // SQLLogicTest uses newline-separated values with trailing newline
        return md5(implode("\n", $results) . "\n");
    }

    private function diffResults(array $expected, array $actual): string
    {
        $diff = "Results differ:\n";
        $diff .= "Expected " . count($expected) . " rows, got " . count($actual) . "\n";

        $max = max(count($expected), count($actual));
        for ($i = 0; $i < min($max, 5); $i++) {
            $e = $expected[$i] ?? '(missing)';
            $a = $actual[$i] ?? '(missing)';
            if ($e !== $a) {
                $diff .= "  Row $i: expected [$e], got [$a]\n";
            }
        }

        if ($max > 5) {
            $diff .= "  ... and more\n";
        }

        return $diff;
    }
}

/**
 * A single test record (statement or query)
 */
class SqlLogicTestRecord
{
    public function __construct(
        public string $type,
        public string $sql = '',
        public bool $expectError = false,
        public string $types = '',
        public string $sortMode = 'nosort',
        public ?string $label = null,
        public array $expected = [],
        public ?string $skipIf = null,
        public ?string $onlyIf = null,
        public int $lineNumber = 0,
    ) {}
}

/**
 * Test run results
 *
 * Tracks three types of skips:
 * - skip_na: Not applicable (onlyif/skipif conditions)
 * - skip_limit: VDB limitation (e.g., >4 table joins)
 * - skip_other: Other skips
 */
class SqlLogicTestResult
{
    /** @var array<string, array{pass: int, fail: int, skip_na: int, skip_limit: int, skip_other: int}> */
    private array $stats = [];

    /** @var array<string, float> Total execution time per backend in seconds */
    private array $times = [];

    /** @var float Time spent on tests both backends ran (for fair comparison) */
    private float $sharedSqliteTime = 0.0;
    private float $sharedVdbTime = 0.0;
    private int $sharedTestCount = 0;

    /** @var array<array{backend: string, record: SqlLogicTestRecord, message: string}> */
    private array $failures = [];

    /** @var array Temporary storage for current record's execution times */
    private array $currentRecordTimes = [];

    public function __construct(
        public readonly string $name
    ) {}

    public function pass(string $backend, SqlLogicTestRecord $record): void
    {
        $this->ensureBackend($backend);
        $this->stats[$backend]['pass']++;
    }

    public function fail(string $backend, SqlLogicTestRecord $record, string $message): void
    {
        $this->ensureBackend($backend);
        $this->stats[$backend]['fail']++;
        $this->failures[] = [
            'backend' => $backend,
            'record' => $record,
            'message' => $message,
        ];
    }

    public function skip(string $backend, SqlLogicTestRecord $record, string $reason): void
    {
        $this->ensureBackend($backend);

        // Categorize skip reason
        if (str_contains($reason, 'onlyif') || str_contains($reason, 'skipif')) {
            $this->stats[$backend]['skip_na']++;
        } elseif (str_contains($reason, 'VDB limitation:')) {
            $this->stats[$backend]['skip_limit']++;
        } else {
            $this->stats[$backend]['skip_other']++;
        }
    }

    private function ensureBackend(string $backend): void
    {
        if (!isset($this->stats[$backend])) {
            $this->stats[$backend] = [
                'pass' => 0,
                'fail' => 0,
                'skip_na' => 0,
                'skip_limit' => 0,
                'skip_other' => 0,
            ];
            $this->times[$backend] = 0.0;
        }
    }

    public function addTime(string $backend, float $seconds): void
    {
        $this->ensureBackend($backend);
        $this->times[$backend] += $seconds;
        $this->currentRecordTimes[$backend] = $seconds;
    }

    /**
     * Call after processing each record to track shared test times
     */
    public function finalizeRecord(): void
    {
        // If both sqlite and vdb ran this test, track for fair comparison
        if (isset($this->currentRecordTimes['sqlite']) && isset($this->currentRecordTimes['vdb'])) {
            $this->sharedSqliteTime += $this->currentRecordTimes['sqlite'];
            $this->sharedVdbTime += $this->currentRecordTimes['vdb'];
            $this->sharedTestCount++;
        }
        $this->currentRecordTimes = [];
    }

    public function getTimes(): array
    {
        return $this->times;
    }

    /**
     * Get timing for only tests both backends ran (fair comparison)
     */
    public function getSharedTimes(): array
    {
        return [
            'sqlite' => $this->sharedSqliteTime,
            'vdb' => $this->sharedVdbTime,
            'count' => $this->sharedTestCount,
        ];
    }

    public function hasFailures(): bool
    {
        return count($this->failures) > 0;
    }

    public function getStats(): array
    {
        // Convert to legacy format for backward compatibility
        $legacy = [];
        foreach ($this->stats as $backend => $s) {
            $legacy[$backend] = [
                'pass' => $s['pass'],
                'fail' => $s['fail'],
                'skip' => $s['skip_na'] + $s['skip_limit'] + $s['skip_other'],
                'skip_na' => $s['skip_na'],
                'skip_limit' => $s['skip_limit'],
                'skip_other' => $s['skip_other'],
            ];
        }
        return $legacy;
    }

    public function getFailures(): array
    {
        return $this->failures;
    }

    public function getSummary(): string
    {
        $out = "SQLLogicTest: {$this->name}\n";
        $out .= str_repeat('-', 50) . "\n";

        foreach ($this->stats as $backend => $s) {
            $total = $s['pass'] + $s['fail'] + $s['skip_na'] + $s['skip_limit'] + $s['skip_other'];
            $out .= sprintf(
                "%s: %d passed, %d failed, %d skipped (of %d)\n",
                $backend, $s['pass'], $s['fail'],
                $s['skip_na'] + $s['skip_limit'] + $s['skip_other'], $total
            );
        }

        if ($this->failures) {
            $out .= "\nFailures:\n";
            foreach (array_slice($this->failures, 0, 10) as $f) {
                $out .= sprintf(
                    "  [%s] Line %d: %s\n    SQL: %s\n    %s\n",
                    $f['backend'],
                    $f['record']->lineNumber,
                    $f['record']->type,
                    substr($f['record']->sql, 0, 60),
                    $f['message']
                );
            }
            if (count($this->failures) > 10) {
                $out .= sprintf("  ... and %d more failures\n", count($this->failures) - 10);
            }
        }

        return $out;
    }
}
