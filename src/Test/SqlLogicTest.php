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

    private function executeRecord(SqlLogicTestRecord $record, SqlLogicTestResult $result): void
    {
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
            $result->fail($name, $record, $e->getMessage());
        } catch (\Throwable $e) {
            $result->fail($name, $record, $e->getMessage());
        }
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
 */
class SqlLogicTestResult
{
    /** @var array<string, array{pass: int, fail: int, skip: int}> */
    private array $stats = [];

    /** @var array<string, float> Total execution time per backend in seconds */
    private array $times = [];

    /** @var array<array{backend: string, record: SqlLogicTestRecord, message: string}> */
    private array $failures = [];

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
        $this->stats[$backend]['skip']++;
    }

    private function ensureBackend(string $backend): void
    {
        if (!isset($this->stats[$backend])) {
            $this->stats[$backend] = ['pass' => 0, 'fail' => 0, 'skip' => 0];
            $this->times[$backend] = 0.0;
        }
    }

    public function addTime(string $backend, float $seconds): void
    {
        $this->ensureBackend($backend);
        $this->times[$backend] += $seconds;
    }

    public function getTimes(): array
    {
        return $this->times;
    }

    public function hasFailures(): bool
    {
        return count($this->failures) > 0;
    }

    public function getStats(): array
    {
        return $this->stats;
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
            $total = $s['pass'] + $s['fail'] + $s['skip'];
            $out .= sprintf(
                "%s: %d passed, %d failed, %d skipped (of %d)\n",
                $backend, $s['pass'], $s['fail'], $s['skip'], $total
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
