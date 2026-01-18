<?php
/**
 * VDB vs SQLite benchmark tool
 *
 * Measures parsing, planning, and execution time separately for VDB.
 * SQLite prepares statements fresh each time (no query cache benefit).
 */
require_once "vendor/autoload.php";

use mini\Database\VirtualDatabase;
use mini\Parsing\SQL\SqlParser;

class Benchmark
{
    private VirtualDatabase $vdb;
    private \SQLite3 $sqlite;
    private int $tableRows = 0;

    public function run(string $name, callable $setup, callable $queryGenerator, int $iterations = 10000): void
    {
        // Setup both databases
        $this->vdb = new VirtualDatabase();
        $this->sqlite = new \SQLite3(':memory:');
        $this->sqlite->exec('PRAGMA cache_size = 0');
        $this->tableRows = 0;

        $setup($this->vdb, $this->sqlite);

        // Collect queries
        $queries = [];
        $sampleSql = null;
        foreach ($queryGenerator() as $item) {
            if (is_string($item)) {
                $queries[] = ['sql' => $item, 'params' => []];
                $sampleSql ??= $item;
            } else {
                $queries[] = $item;
                $sampleSql ??= $item['sql'];
            }
            if (count($queries) >= $iterations) break;
        }

        $n = count($queries);

        // Phase 1: Parse only
        $parseTime = 0;
        foreach ($queries as $q) {
            $start = hrtime(true);
            try { SqlParser::parse($q['sql']); } catch (\Throwable $e) {}
            $parseTime += hrtime(true) - $start;
        }
        $parseTime /= 1e6;

        // Phase 2: Parse + Plan
        $parsePlanTime = 0;
        foreach ($queries as $q) {
            $start = hrtime(true);
            try { $this->vdb->query($q['sql'], $q['params']); } catch (\Throwable $e) {}
            $parsePlanTime += hrtime(true) - $start;
        }
        $parsePlanTime /= 1e6;
        $planTime = $parsePlanTime - $parseTime;

        // Phase 3: Full execution
        $vdbTotal = 0;
        $vdbRows = 0;
        foreach ($queries as $q) {
            $start = hrtime(true);
            try {
                foreach ($this->vdb->query($q['sql'], $q['params']) as $row) {
                    $vdbRows++;
                }
            } catch (\Throwable $e) {}
            $vdbTotal += hrtime(true) - $start;
        }
        $vdbTotal /= 1e6;
        $execTime = $vdbTotal - $parsePlanTime;

        // SQLite
        $sqliteTotal = 0;
        $sqliteRows = 0;
        foreach ($queries as $q) {
            $start = hrtime(true);
            try {
                $stmt = $this->sqlite->prepare($q['sql']);
                if ($stmt) {
                    foreach ($q['params'] as $key => $value) {
                        $stmt->bindValue(is_int($key) ? $key + 1 : ':' . $key, $value);
                    }
                    $result = $stmt->execute();
                    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                        $sqliteRows++;
                    }
                    $stmt->close();
                }
            } catch (\Throwable $e) {}
            $sqliteTotal += hrtime(true) - $start;
        }
        $sqliteTotal /= 1e6;

        // Output
        $ratio = $sqliteTotal > 0 ? $vdbTotal / $sqliteTotal : 0;
        $parsePct = $parseTime / $vdbTotal * 100;
        $planPct = $planTime / $vdbTotal * 100;
        $execPct = $execTime / $vdbTotal * 100;

        printf("%-45s | %6dK rows | %5d queries | %6d rows/q\n", $name, $this->tableRows/1000, $n, $vdbRows/$n);
        printf("  SQL: %s\n", $sampleSql);
        printf("  VDB:    %7.1fms (%5.2fms/q)  Parse:%4.1f%% Plan:%4.1f%% Exec:%4.1f%%\n",
            $vdbTotal, $vdbTotal/$n, $parsePct, $planPct, $execPct);
        printf("  SQLite: %7.1fms (%5.2fms/q)  Ratio: %.1fx\n\n",
            $sqliteTotal, $sqliteTotal/$n, $ratio);
    }

    public function setTableRows(int $rows): void
    {
        $this->tableRows = $rows;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// Benchmark definitions
// ═══════════════════════════════════════════════════════════════════════════

$bench = new Benchmark();

echo "VDB vs SQLite Benchmark\n";
echo str_repeat("=", 90) . "\n\n";

// Point lookup scaling
$bench->run(
    "Point lookup",
    function ($vdb, $sqlite) use ($bench) {
        $bench->setTableRows(1000);
        $vdb->exec("CREATE TABLE t1 (id INTEGER PRIMARY KEY, val INTEGER, name TEXT)");
        $sqlite->exec("CREATE TABLE t1 (id INTEGER PRIMARY KEY, val INTEGER, name TEXT)");
        for ($i = 0; $i < 1000; $i++) {
            $sql = "INSERT INTO t1 VALUES ($i, " . ($i % 100) . ", 'row$i')";
            $vdb->exec($sql); $sqlite->exec($sql);
        }
    },
    fn() => (function() {
        for ($i = 0; $i < 10000; $i++) yield ['sql' => 'SELECT * FROM t1 WHERE id = :id', 'params' => ['id' => $i % 1000]];
    })()
);

$bench->run(
    "Point lookup",
    function ($vdb, $sqlite) use ($bench) {
        $bench->setTableRows(100000);
        $vdb->exec("CREATE TABLE t1 (id INTEGER PRIMARY KEY, val INTEGER, name TEXT)");
        $sqlite->exec("CREATE TABLE t1 (id INTEGER PRIMARY KEY, val INTEGER, name TEXT)");
        for ($i = 0; $i < 100000; $i++) {
            $sql = "INSERT INTO t1 VALUES ($i, " . ($i % 100) . ", 'row$i')";
            $vdb->exec($sql); $sqlite->exec($sql);
        }
    },
    fn() => (function() {
        for ($i = 0; $i < 10000; $i++) yield ['sql' => 'SELECT * FROM t1 WHERE id = :id', 'params' => ['id' => $i % 100000]];
    })()
);

// Full table scan
$bench->run(
    "Full scan",
    function ($vdb, $sqlite) use ($bench) {
        $bench->setTableRows(1000);
        $vdb->exec("CREATE TABLE t1 (id INTEGER, val INTEGER, name TEXT)");
        $sqlite->exec("CREATE TABLE t1 (id INTEGER, val INTEGER, name TEXT)");
        for ($i = 0; $i < 1000; $i++) {
            $sql = "INSERT INTO t1 VALUES ($i, " . ($i % 100) . ", 'row$i')";
            $vdb->exec($sql); $sqlite->exec($sql);
        }
    },
    fn() => (function() { for ($i = 0; $i < 1000; $i++) yield 'SELECT * FROM t1'; })(),
    1000
);

$bench->run(
    "Full scan",
    function ($vdb, $sqlite) use ($bench) {
        $bench->setTableRows(10000);
        $vdb->exec("CREATE TABLE t1 (id INTEGER, val INTEGER, name TEXT)");
        $sqlite->exec("CREATE TABLE t1 (id INTEGER, val INTEGER, name TEXT)");
        for ($i = 0; $i < 10000; $i++) {
            $sql = "INSERT INTO t1 VALUES ($i, " . ($i % 100) . ", 'row$i')";
            $vdb->exec($sql); $sqlite->exec($sql);
        }
    },
    fn() => (function() { for ($i = 0; $i < 1000; $i++) yield 'SELECT * FROM t1'; })(),
    1000
);

// Range query
$bench->run(
    "Range query",
    function ($vdb, $sqlite) use ($bench) {
        $bench->setTableRows(10000);
        $vdb->exec("CREATE TABLE t1 (id INTEGER PRIMARY KEY, val INTEGER)");
        $sqlite->exec("CREATE TABLE t1 (id INTEGER PRIMARY KEY, val INTEGER)");
        for ($i = 0; $i < 10000; $i++) {
            $sql = "INSERT INTO t1 VALUES ($i, " . ($i % 100) . ")";
            $vdb->exec($sql); $sqlite->exec($sql);
        }
    },
    fn() => (function() {
        for ($i = 0; $i < 10000; $i++) yield ['sql' => 'SELECT * FROM t1 WHERE id > :min AND id < :max', 'params' => ['min' => $i % 9000, 'max' => ($i % 9000) + 100]];
    })()
);

// COUNT with filter
$bench->run(
    "COUNT(*) with WHERE",
    function ($vdb, $sqlite) use ($bench) {
        $bench->setTableRows(1000);
        $vdb->exec("CREATE TABLE t1 (id INTEGER, val INTEGER)");
        $sqlite->exec("CREATE TABLE t1 (id INTEGER, val INTEGER)");
        for ($i = 0; $i < 1000; $i++) {
            $sql = "INSERT INTO t1 VALUES ($i, " . ($i % 50) . ")";
            $vdb->exec($sql); $sqlite->exec($sql);
        }
    },
    fn() => (function() {
        for ($i = 0; $i < 10000; $i++) yield ['sql' => 'SELECT COUNT(*) FROM t1 WHERE val = :val', 'params' => ['val' => $i % 50]];
    })()
);

$bench->run(
    "COUNT(*) with WHERE",
    function ($vdb, $sqlite) use ($bench) {
        $bench->setTableRows(100000);
        $vdb->exec("CREATE TABLE t1 (id INTEGER, val INTEGER)");
        $sqlite->exec("CREATE TABLE t1 (id INTEGER, val INTEGER)");
        for ($i = 0; $i < 100000; $i++) {
            $sql = "INSERT INTO t1 VALUES ($i, " . ($i % 50) . ")";
            $vdb->exec($sql); $sqlite->exec($sql);
        }
    },
    fn() => (function() {
        for ($i = 0; $i < 1000; $i++) yield ['sql' => 'SELECT COUNT(*) FROM t1 WHERE val = :val', 'params' => ['val' => $i % 50]];
    })(),
    1000
);

// ORDER BY (non-indexed column - requires sort)
$bench->run(
    "ORDER BY (non-indexed)",
    function ($vdb, $sqlite) use ($bench) {
        $bench->setTableRows(1000);
        $vdb->exec("CREATE TABLE t1 (id INTEGER, val INTEGER, name TEXT)");
        $sqlite->exec("CREATE TABLE t1 (id INTEGER, val INTEGER, name TEXT)");
        for ($i = 0; $i < 1000; $i++) {
            $v = ($i * 17) % 1000;
            $sql = "INSERT INTO t1 VALUES ($i, $v, 'row$i')";
            $vdb->exec($sql); $sqlite->exec($sql);
        }
    },
    fn() => (function() { for ($i = 0; $i < 1000; $i++) yield 'SELECT * FROM t1 ORDER BY val'; })(),
    1000
);

// ORDER BY (indexed column - streams from index)
$bench->run(
    "ORDER BY (indexed)",
    function ($vdb, $sqlite) use ($bench) {
        $bench->setTableRows(1000);
        $vdb->exec("CREATE TABLE t1 (id INTEGER PRIMARY KEY, val INTEGER, name TEXT)");
        $sqlite->exec("CREATE TABLE t1 (id INTEGER PRIMARY KEY, val INTEGER, name TEXT)");
        for ($i = 0; $i < 1000; $i++) {
            $v = ($i * 17) % 1000;
            $sql = "INSERT INTO t1 VALUES ($i, $v, 'row$i')";
            $vdb->exec($sql); $sqlite->exec($sql);
        }
    },
    fn() => (function() { for ($i = 0; $i < 1000; $i++) yield 'SELECT * FROM t1 ORDER BY id'; })(),
    1000
);

// LIMIT
$bench->run(
    "LIMIT",
    function ($vdb, $sqlite) use ($bench) {
        $bench->setTableRows(10000);
        $vdb->exec("CREATE TABLE t1 (id INTEGER, val INTEGER)");
        $sqlite->exec("CREATE TABLE t1 (id INTEGER, val INTEGER)");
        for ($i = 0; $i < 10000; $i++) {
            $sql = "INSERT INTO t1 VALUES ($i, " . ($i % 100) . ")";
            $vdb->exec($sql); $sqlite->exec($sql);
        }
    },
    fn() => (function() { for ($i = 0; $i < 10000; $i++) yield 'SELECT * FROM t1 LIMIT 10'; })()
);
