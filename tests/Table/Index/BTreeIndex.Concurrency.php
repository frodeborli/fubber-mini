<?php
/**
 * btree_concurrency_fuzzer.php
 *
 * Usage:
 *   php btree_concurrency_fuzzer.php /tmp/test.btree
 *
 * Environment knobs:
 *   WORKERS=8
 *   READERS=2
 *   SECONDS=20
 *   KEYSPACE=200
 *   TXN_MAX_OPS=200
 *   DELETE_PCT=30
 *   DUP_PCT=10
 *   CRASH_PCT=2            // percent chance a worker self-SIGKILLs mid-commit
 *   FSYNC_PAUSE_US=0       // optional micro-sleep between ops to alter interleavings
 *
 * Exit code:
 *   0 on clean run + verification OK
 *   1 on verification failure
 */

declare(strict_types=1);

require __DIR__ . '/../../../ensure-autoloader.php';

use mini\Table\Index\BTreeIndex;

if (!extension_loaded('pcntl')) {
    fwrite(STDERR, "pcntl extension required\n");
    exit(1);
}

$path = $argv[1] ?? null;
if (!$path) {
    fwrite(STDERR, "Usage: php {$argv[0]} /path/to/index.btree\n");
    exit(1);
}

$WORKERS      = (int)(getenv('WORKERS') ?: 8);      // writer workers
$READERS      = (int)(getenv('READERS') ?: 2);      // reader workers
$SECONDS      = (int)(getenv('SECONDS') ?: 20);
$KEYSPACE     = (int)(getenv('KEYSPACE') ?: 200);
$TXN_MAX_OPS  = (int)(getenv('TXN_MAX_OPS') ?: 200);
$DELETE_PCT   = (int)(getenv('DELETE_PCT') ?: 30);
$DUP_PCT      = (int)(getenv('DUP_PCT') ?: 10);
$CRASH_PCT    = (int)(getenv('CRASH_PCT') ?: 2);
$FSYNC_PAUSE  = (int)(getenv('FSYNC_PAUSE_US') ?: 0);

$LOG = $path . ".oplog.jsonl";
$PIDFILE = $path . ".pids";

// Clean slate
@unlink($path);
@unlink($LOG);
@unlink($PIDFILE);
@unlink($path . ".lock"); // if you use lock files

$start = microtime(true);
$endAt = $start + $SECONDS;

function now(): float { return microtime(true); }

function keyFor(int $k): string {
    // fixed-width keys preserve lexicographic numeric ordering
    return sprintf("k%06d", $k);
}

/**
 * Append an operation to a shared log.
 * This is your "ground truth" (best-effort) for post-run verification.
 * We log only *committed* transactions (at txn granularity) to avoid
 * modeling rollback/collisions incorrectly.
 */
function appendLog(string $logPath, array $record): void {
    $line = json_encode($record, JSON_UNESCAPED_SLASHES) . "\n";
    $fp = fopen($logPath, "ab");
    if (!$fp) return;
    // lock log file so records don't interleave
    flock($fp, LOCK_EX);
    fwrite($fp, $line);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function randPct(): int { return random_int(0, 99); }
function randKey(int $keyspace): string { return keyFor(random_int(0, $keyspace - 1)); }

function randRowId(int $workerId, int $seq): int {
    // unique rowIds per worker by construction, unless we choose DUP
    return ($workerId * 1_000_000_000) + $seq;
}

/**
 * Writer worker: runs transactions of mixed insert/delete.
 * Sometimes intentionally crashes mid-commit to simulate power loss.
 */
function writerWorker(
    int $workerId,
    string $path,
    string $logPath,
    float $endAt,
    int $keyspace,
    int $txnMaxOps,
    int $deletePct,
    int $dupPct,
    int $crashPct,
    int $pauseUs
): void {
    // isolate RNG streams per worker
    random_int(1, 1); // warm-up
    $seq = 0;

    while (microtime(true) < $endAt) {
        $idx = new BTreeIndex($path);

        // random: sometimes do single-write mode (no begin/commit)
        $useTxn = (randPct() < 85);

        $ops = [];
        $opCount = random_int(1, $txnMaxOps);

        if ($useTxn) {
            $idx->begin();
        }

        for ($i = 0; $i < $opCount; $i++) {
            $k = randKey($keyspace);

            // choose insert vs delete
            if (randPct() < $deletePct) {
                // delete: choose a rowId that *might* exist (or might not)
                // pick from this worker's recent IDs, plus random noise
                $candidateSeq = ($seq > 0) ? random_int(max(0, $seq - 2000), $seq) : 0;
                $rowId = randRowId($workerId, $candidateSeq);
                // sometimes delete a totally random other worker rowId
                if (randPct() < 20) {
                    $rowId = random_int(0, 9) * 1_000_000_000 + random_int(0, $seq + 1000);
                }
                $idx->delete($k, $rowId);
                $ops[] = ["op" => "del", "k" => $k, "id" => $rowId];
            } else {
                // insert
                $rowId = randRowId($workerId, $seq++);
                if (randPct() < $dupPct && $seq > 2) {
                    // duplicate insert of some prior id (same worker)
                    $rowId = randRowId($workerId, random_int(max(0, $seq - 2000), $seq - 1));
                }
                $idx->insert($k, $rowId);
                $ops[] = ["op" => "ins", "k" => $k, "id" => $rowId];
            }

            if ($pauseUs > 0) usleep($pauseUs);

            // occasionally query during write workload (read-your-writes + internal consistency)
            if (randPct() < 5) {
                $ids = iterator_to_array($idx->eq($k));
                // cheap sanity: if we just inserted that rowId for that key, it *should* appear in txn
                $last = $ops[count($ops) - 1];
                if ($last["op"] === "ins" && $last["k"] === $k) {
                    if (!in_array($last["id"], $ids, true)) {
                        // fail fast: corruption / visibility bug
                        fwrite(STDERR, "Worker $workerId: inserted {$last["id"]} into {$k} but eq() didn't show it\n");
                        // keep going but mark something wrong via exit code
                        exit(2);
                    }
                }
            }
        }

        if ($useTxn) {
            // Try to crash mid-commit occasionally:
            // - call commit, but with a chance to SIGKILL ourselves right before/after.
            if (randPct() < $crashPct) {
                // Heuristic: kill right before commit
                posix_kill(getmypid(), SIGKILL);
            }

            $idx->commit();

            if (randPct() < $crashPct) {
                // kill immediately after commit (simulate crash before close/fsync)
                posix_kill(getmypid(), SIGKILL);
            }

            // Log committed txn as one record
            appendLog($logPath, [
                "t" => microtime(true),
                "worker" => $workerId,
                "txn" => 1,
                "ops" => $ops,
            ]);
        } else {
            // single-write mode: log each op as its own committed unit
            foreach ($ops as $op) {
                appendLog($logPath, [
                    "t" => microtime(true),
                    "worker" => $workerId,
                    "txn" => 0,
                    "ops" => [$op],
                ]);
            }
        }

        $idx->close();
    }

    exit(0);
}

/**
 * Reader worker: repeatedly opens the index and does random eq/range/count/has
 * while writers are running. This catches crashes, infinite loops, and
 * parser errors under concurrent writes.
 */
function readerWorker(
    int $readerId,
    string $path,
    float $endAt,
    int $keyspace,
    int $pauseUs
): void {
    while (microtime(true) < $endAt) {
        try {
            $idx = new BTreeIndex($path);

            // Random query mix
            $k = randKey($keyspace);

            $r = randPct();
            if ($r < 25) {
                iterator_to_array($idx->eq($k));
            } elseif ($r < 50) {
                $idx->has($k);
            } elseif ($r < 75) {
                $idx->count($k);
            } else {
                // bounded range random
                $a = random_int(0, $keyspace - 1);
                $b = random_int(0, $keyspace - 1);
                if ($a > $b) [$a, $b] = [$b, $a];
                $start = keyFor($a);
                $end = keyFor($b);
                // force iteration
                iterator_to_array($idx->range(start: $start, end: $end, reverse: (randPct() < 50)));
            }

            $idx->close();
        } catch (Throwable $e) {
            fwrite(STDERR, "Reader $readerId exception: {$e->getMessage()}\n");
            // If reads can fail under concurrent writes that's a design choice,
            // but in production you'd usually want a clean failure mode.
            // Exit non-zero to flag it.
            exit(3);
        }

        if ($pauseUs > 0) usleep($pauseUs);
    }

    exit(0);
}

/**
 * Verify internal consistency of the index (doesn't rely on oplog ordering).
 *
 * With concurrent writers, oplog order != commit order, so we can't verify
 * against the oplog model. Instead we verify:
 * 1. eq/has/count are mutually consistent
 * 2. range() matches concatenated eq() results in key order
 * 3. Reverse range matches forward reversed
 * 4. Close/reopen produces identical results (persistence stability)
 * 5. Idempotent: multiple range() calls produce same results
 */
function verify(string $path, string $logPath, int $keyspace = 200): int {
    if (!file_exists($path)) {
        fwrite(STDERR, "Index file missing\n");
        return 1;
    }

    $idx = new BTreeIndex($path);

    // Phase 1: Build model from eq() calls
    $rangeData = []; // key => [rowIds]
    $keysFound = [];
    $eqTotal = 0;

    for ($k = 0; $k < $keyspace; $k++) {
        $key = keyFor($k);
        $rows = iterator_to_array($idx->eq($key));

        if (count($rows) > 0) {
            $keysFound[] = $key;
            $rangeData[$key] = $rows;
            $eqTotal += count($rows);

            // Verify count() matches eq()
            $c = $idx->count($key);
            if ($c !== count($rows)) {
                fwrite(STDERR, "count($key) = $c but eq() returned " . count($rows) . " rows\n");
                $idx->close();
                return 1;
            }

            // Verify has() returns true
            if (!$idx->has($key)) {
                fwrite(STDERR, "has($key) returned false but eq() returned " . count($rows) . " rows\n");
                $idx->close();
                return 1;
            }

            // Idempotence: eq() twice should match
            $rows2 = iterator_to_array($idx->eq($key));
            if ($rows !== $rows2) {
                fwrite(STDERR, "eq($key) not idempotent: first call returned " . count($rows) . ", second returned " . count($rows2) . "\n");
                $idx->close();
                return 1;
            }
        } else {
            // Verify has() returns false for empty keys
            if ($idx->has($key)) {
                fwrite(STDERR, "has($key) returned true but eq() returned 0 rows\n");
                $idx->close();
                return 1;
            }
        }
    }

    // Phase 2: Build expected range from eq() data
    sort($keysFound);
    $expectedRange = [];
    foreach ($keysFound as $key) {
        foreach ($rangeData[$key] as $rowId) {
            $expectedRange[] = $rowId;
        }
    }

    // Sanity check: eq() total should match expected range
    if ($eqTotal !== count($expectedRange)) {
        fwrite(STDERR, "Internal error: eqTotal $eqTotal != expectedRange count " . count($expectedRange) . "\n");
        $idx->close();
        return 1;
    }

    // Phase 3: Verify range() matches (before close)
    $actualRange = iterator_to_array($idx->range());
    if ($actualRange !== $expectedRange) {
        fwrite(STDERR, "range() doesn't match concatenated eq() results\n");
        fwrite(STDERR, "Expected count: " . count($expectedRange) . ", Actual count: " . count($actualRange) . "\n");
        $n = min(count($expectedRange), count($actualRange), 100);
        for ($i = 0; $i < $n; $i++) {
            if ($expectedRange[$i] !== $actualRange[$i]) {
                fwrite(STDERR, "First divergence at index $i: expected {$expectedRange[$i]} got {$actualRange[$i]}\n");
                break;
            }
        }
        $idx->close();
        return 1;
    }

    // Idempotence: range() twice should match
    $actualRange2 = iterator_to_array($idx->range());
    if ($actualRange !== $actualRange2) {
        fwrite(STDERR, "range() not idempotent\n");
        $idx->close();
        return 1;
    }

    // Phase 4: Verify reverse range
    $reverseRange = iterator_to_array($idx->range(reverse: true));
    $expectedReverse = array_reverse($actualRange);
    if ($reverseRange !== $expectedReverse) {
        fwrite(STDERR, "range(reverse: true) doesn't match reversed forward range\n");
        $idx->close();
        return 1;
    }

    // Phase 5: Close and reopen - persistence stability
    $idx->close();
    $idx = new BTreeIndex($path);

    $afterReopenRange = iterator_to_array($idx->range());
    if ($afterReopenRange !== $expectedRange) {
        fwrite(STDERR, "range() after reopen doesn't match original\n");
        fwrite(STDERR, "Before: " . count($expectedRange) . " rows, After: " . count($afterReopenRange) . " rows\n");
        $idx->close();
        return 1;
    }

    // Spot check some eq() calls after reopen
    $spotChecks = min(50, count($keysFound));
    for ($i = 0; $i < $spotChecks; $i++) {
        $key = $keysFound[$i];
        $rows = iterator_to_array($idx->eq($key));
        if ($rows !== $rangeData[$key]) {
            fwrite(STDERR, "eq($key) after reopen doesn't match original\n");
            $idx->close();
            return 1;
        }
    }

    fwrite(STDERR, "Verified: " . count($keysFound) . " keys, " . count($actualRange) . " total rows\n");
    $idx->close();
    return 0;
}

// ----------------------------------------------------------------------
// Spawn children
// ----------------------------------------------------------------------

$pids = [];

for ($w = 0; $w < $WORKERS; $w++) {
    $pid = pcntl_fork();
    if ($pid === -1) { fwrite(STDERR, "fork failed\n"); exit(1); }
    if ($pid === 0) {
        writerWorker($w, $path, $LOG, $endAt, $KEYSPACE, $TXN_MAX_OPS, $DELETE_PCT, $DUP_PCT, $CRASH_PCT, $FSYNC_PAUSE);
    }
    $pids[] = $pid;
}

for ($r = 0; $r < $READERS; $r++) {
    $pid = pcntl_fork();
    if ($pid === -1) { fwrite(STDERR, "fork failed\n"); exit(1); }
    if ($pid === 0) {
        readerWorker($r, $path, $endAt, $KEYSPACE, $FSYNC_PAUSE);
    }
    $pids[] = $pid;
}

// record pids for debugging
file_put_contents($PIDFILE, implode("\n", $pids) . "\n");

// Parent: wait
$exitBad = false;
foreach ($pids as $pid) {
    $status = 0;
    pcntl_waitpid($pid, $status);
    if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
        // workers may SIGKILL themselves by design; that's fine.
        // But readers/writers exiting with 2/3 indicates a detected issue.
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 128 + pcntl_wtermsig($status);
        if ($code === 2 || $code === 3) {
            $exitBad = true;
        }
    }
}

if ($exitBad) {
    fwrite(STDERR, "One or more workers detected an internal inconsistency / read failure.\n");
    // continue to verify anyway
}

// Final verification pass
$rc = verify($path, $LOG, $KEYSPACE);

if ($rc !== 0) {
    fwrite(STDERR, "FAIL: verification failed.\n");
    exit(1);
}

if ($exitBad) {
    fwrite(STDERR, "FAIL: worker signaled issues.\n");
    exit(1);
}

// Phase 2: Hammer reopen test - repeatedly open/query/close to catch state leaks
fwrite(STDERR, "Running hammer reopen test...\n");
$hammerEnd = microtime(true) + 3; // 3 seconds of hammering
$hammerOps = 0;
$hammerErrors = 0;

while (microtime(true) < $hammerEnd) {
    try {
        $idx = new BTreeIndex($path);

        // Random queries
        for ($q = 0; $q < 50; $q++) {
            $k = random_int(0, $KEYSPACE - 1);
            $key = keyFor($k);

            $op = random_int(0, 3);
            switch ($op) {
                case 0: iterator_to_array($idx->eq($key)); break;
                case 1: $idx->has($key); break;
                case 2: $idx->count($key); break;
                case 3:
                    // Bounded range
                    $k2 = random_int(0, $KEYSPACE - 1);
                    if ($k > $k2) [$k, $k2] = [$k2, $k];
                    iterator_to_array($idx->range(keyFor($k), keyFor($k2)));
                    break;
            }
            $hammerOps++;
        }

        $idx->close();
    } catch (Throwable $e) {
        fwrite(STDERR, "Hammer error: {$e->getMessage()}\n");
        $hammerErrors++;
    }
}

fwrite(STDERR, "Hammer test: $hammerOps ops, $hammerErrors errors\n");

if ($hammerErrors > 0) {
    fwrite(STDERR, "FAIL: hammer test encountered errors.\n");
    exit(1);
}

fwrite(STDOUT, "OK: concurrency fuzz run completed and verified.\n");
exit(0);
