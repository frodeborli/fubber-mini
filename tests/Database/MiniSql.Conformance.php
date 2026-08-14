<?php
/**
 * MiniSQL dialect conformance
 *
 * Runs the shared conformance corpus in tests/minisql/ against this engine.
 * Those .test files are sqllogictest format — language-neutral by design —
 * and they are the executable definition of MiniSQL, the dialect implemented
 * by BOTH this PHP engine and the Python port (minivdb).
 *
 * The point of the shared corpus is that "the two engines behave the same"
 * stops being a promise maintained by hand and becomes a property either
 * engine can verify on its own: both run the same files, and a divergence is
 * a failing test in whichever engine drifted.
 *
 * Adding a dialect decision means adding a case here FIRST, then making both
 * engines pass it. Where MiniSQL deliberately differs from SQLite (PHP-style
 * division is the notable one), the corpus pins the difference so it cannot
 * change by accident in either implementation.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Test\SqlLogicTest;
use mini\Database\VirtualDatabase;

$test = new class extends Test {

    /**
     * @return string[] Absolute paths of every corpus file
     */
    private function corpusFiles(): array
    {
        $dir = dirname(__DIR__) . '/minisql';
        $files = glob($dir . '/*.test') ?: [];
        sort($files);
        return $files;
    }

    public function testCorpusExists(): void
    {
        $files = $this->corpusFiles();
        $this->assertNotEmpty(
            $files,
            'tests/minisql/ must hold at least one .test file — it is the dialect spec'
        );
    }

    public function testEveryCorpusFilePassesOnThisEngine(): void
    {
        foreach ($this->corpusFiles() as $file) {
            $runner = new SqlLogicTest();
            $runner->addBackend('vdb', new VirtualDatabase());
            $result = $runner->run(file_get_contents($file));
            $stats = $result->getStats();

            $name = basename($file);
            $failed = $stats['vdb']['fail'] ?? 0;

            if ($failed > 0) {
                $details = [];
                foreach (array_slice($result->getFailures(), 0, 5) as $f) {
                    $sql = $f['record']['sql'] ?? '?';
                    $msg = trim((string) ($f['message'] ?? ''));
                    $details[] = "    $sql\n      " . str_replace("\n", "\n      ", $msg);
                }
                $this->fail(
                    "MiniSQL conformance failures in $name ($failed):\n" . implode("\n", $details)
                );
            }

            $this->assertGreaterThan(
                0,
                $stats['vdb']['pass'] ?? 0,
                "$name produced no passing records — is it being parsed?"
            );
        }
    }
};

exit($test->run());
