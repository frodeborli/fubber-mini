<?php
/**
 * VirtualDatabase Known Limitations Tests
 *
 * These tests currently FAIL because the features aren't implemented yet.
 * When a feature is fixed, move the test to VerifiedQueries.php.
 *
 * Run with: bin/mini test tests/Database/VirtualDatabase.KnownLimitations.php
 *
 * As of 2025-12-22: All SQL:2003 core features implemented!
 * CTEs (WITH clause) now work. Moving tests to VerifiedQueries.php.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;

$test = new class extends Test {

    private function query(string $sql): string
    {
        $cmd = sprintf('bin/mini vdb --format=csv %s 2>&1', escapeshellarg($sql));
        return trim(shell_exec($cmd));
    }

    // No known limitations currently - all SQL:2003 core features implemented!
    // Add new limitation tests here as they are discovered.

    public function testPlaceholder(): void
    {
        // Placeholder test to keep file valid
        $this->assertTrue(true);
    }
};

exit($test->run());
