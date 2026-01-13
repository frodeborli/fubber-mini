#!/usr/bin/env php
<?php
/**
 * Database Migration Tool
 *
 * Manages database schema changes with tracking, isolation, and rollback support.
 * Migrations are tracked in the _mini_migrations table to ensure each runs only once.
 *
 * Best practice: Use migrations for database schema changes. For filesystem changes,
 * use version control (git) instead - it's better suited for tracking file history.
 *
 * Features:
 * - Tracks executed migrations in _mini_migrations table
 * - Runs each migration in isolated subprocess (clean state)
 * - Supports two migration types:
 *   1. Simple: PHP file that executes directly (no return) - one-way only
 *   2. Object: Returns object with up() and down() methods - supports rollback
 * - Dependency injection for up()/down() methods
 *
 * Usage:
 *   mini migrations              Run pending migrations
 *   mini migrations status       Show migration status
 *   mini migrations rollback     Rollback last batch
 *   mini migrations rollback 3   Rollback last 3 batches
 *   mini migrations fresh        Drop _mini_migrations and run all
 *   mini migrations make <name>  Create new migration file
 *
 * Migration file naming: YYYY_MM_DD_HHMMSS_description.php
 * Example: 2026_01_02_143052_create_users_table.php
 */

require __DIR__ . '/../ensure-autoloader.php';

use mini\CLI\ArgManager;

// Bootstrap Mini framework
mini\bootstrap();

// Parse arguments - register globally via mini\args()
mini\args(ArgManager::parse($argv)
    ->withSubcommand('migrate', 'up', 'rollback', 'down', 'status', 'fresh', 'make', 'help')
);

$sub = mini\args()->nextCommand();
if ($sub) {
    mini\args($sub);
    $command = $sub->getCommand();
} else {
    $command = 'migrate';
}

// Register command-specific flags
mini\args(mini\args()
    ->withFlag(null, 'allow-invalid-prefix')
    ->withFlag(null, 'force')
);
$argument = mini\args()->getUnparsedArgs()[0] ?? null;

$runnerScript = __DIR__ . '/mini-migration-runner.php';

// Get migrations PathsRegistry (set up in bootstrap.php)
$migrationsRegistry = mini\Mini::$mini->paths->migrations;

/**
 * Get database connection
 */
function getDb(): mini\Database\DatabaseInterface {
    return mini\db();
}

/**
 * Check if migration name has valid datetime prefix (YYYY_MM_DD_HHMMSS_)
 */
function hasValidDatetimePrefix(string $name): bool {
    return (bool) preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_/', $name);
}

/**
 * Ensure _mini_migrations table exists
 */
function ensureMigrationsTable(): void {
    $db = getDb();

    // Check if table exists - use try/catch as tableExists() may not work with all dialects
    try {
        $db->queryField("SELECT 1 FROM _mini_migrations LIMIT 1");
        return; // Table exists
    } catch (Throwable $e) {
        // Table doesn't exist, create it
    }

    // Create migrations tracking table
    // Using portable SQL that works across SQLite, MySQL, PostgreSQL
    $dialect = $db->getDialect();

    if ($dialect === mini\Database\SqlDialect::Sqlite) {
        $db->exec("
            CREATE TABLE _mini_migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration TEXT NOT NULL UNIQUE,
                batch INTEGER NOT NULL,
                reversible INTEGER NOT NULL DEFAULT 0,
                ran_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
    } else {
        // MySQL, PostgreSQL, etc.
        $db->exec("
            CREATE TABLE _mini_migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INT NOT NULL,
                reversible TINYINT NOT NULL DEFAULT 0,
                ran_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    echo "Created _mini_migrations table.\n";
}

/**
 * Get list of executed migrations
 * @return array<string, object{batch: int, reversible: bool}>
 */
function getExecutedMigrations(): array {
    $db = getDb();
    $rows = iterator_to_array($db->query("SELECT migration, batch, reversible FROM _mini_migrations ORDER BY migration"));
    $result = [];
    foreach ($rows as $row) {
        $result[$row->migration] = (object)[
            'batch' => (int)$row->batch,
            'reversible' => (bool)$row->reversible
        ];
    }
    return $result;
}

/**
 * Get current batch number
 */
function getCurrentBatch(): int {
    $db = getDb();
    $batch = $db->queryField("SELECT MAX(batch) FROM _mini_migrations");
    return $batch ?? 0;
}

/**
 * Get all migration files from all registered paths
 *
 * Collects *.php files from all paths in the registry, de-duplicating by
 * filename (first match wins - primary path overrides fallbacks).
 * Returns array of full paths sorted by filename.
 *
 * @param mini\Util\PathsRegistry $registry
 * @return array<string, string> Map of basename => full path, sorted by key
 */
function getAllMigrationFiles(mini\Util\PathsRegistry $registry): array {
    $migrations = [];

    foreach ($registry->getPaths() as $path) {
        if (!is_dir($path)) {
            continue;
        }
        $files = glob($path . '/*.php');
        foreach ($files as $file) {
            $name = basename($file, '.php');
            // First match wins (primary path overrides fallbacks)
            if (!isset($migrations[$name])) {
                $migrations[$name] = $file;
            }
        }
    }

    ksort($migrations);
    return $migrations;
}

/**
 * Find a migration file by name across all paths
 */
function findMigrationFile(mini\Util\PathsRegistry $registry, string $name): ?string {
    return $registry->findFirst($name . '.php');
}

/**
 * Run a single migration in isolated subprocess.
 * The subprocess handles both execution and recording in a single transaction.
 */
function runMigration(string $file, string $direction, string $runnerScript,
                      string $name, int $batch): array {
    $cmd = [PHP_BINARY, $runnerScript, $file, $direction, $name, (string) $batch];

    $descriptors = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];

    $process = proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($process)) {
        return ['success' => false, 'error' => 'Failed to start migration subprocess'];
    }

    fclose($pipes[0]); // Close stdin

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    // Parse JSON output
    $result = json_decode($stdout, true);
    if ($result === null && $stdout !== '') {
        return [
            'success' => false,
            'error' => 'Invalid migration output: ' . $stdout,
            'stderr' => $stderr,
            'exitCode' => $exitCode
        ];
    }

    if ($exitCode !== 0 && $exitCode !== 2) {
        $result['success'] = false;
        $result['exitCode'] = $exitCode;
        if ($stderr) {
            $result['stderr'] = $stderr;
        }
    }

    return $result ?? ['success' => false, 'error' => 'No output from migration', 'exitCode' => $exitCode];
}

/**
 * Record migration as executed
 */
function recordMigration(string $name, int $batch, bool $reversible): void {
    $db = getDb();
    $db->exec(
        "INSERT INTO _mini_migrations (migration, batch, reversible) VALUES (?, ?, ?)",
        [$name, $batch, $reversible ? 1 : 0]
    );
}

/**
 * Remove migration record
 */
function removeMigration(string $name): void {
    $db = getDb();
    $db->exec("DELETE FROM _mini_migrations WHERE migration = ?", [$name]);
}

// Route to command handler
switch ($command) {
    case 'migrate':
    case 'up':
        ensureMigrationsTable();

        $executed = getExecutedMigrations();
        $allMigrations = getAllMigrationFiles($migrationsRegistry);
        $pending = [];

        foreach ($allMigrations as $name => $file) {
            if (!array_key_exists($name, $executed)) {
                $pending[$name] = $file;
            }
        }

        if (empty($pending)) {
            echo "Nothing to migrate.\n";
            exit(0);
        }

        // Check for invalid prefixes (unless --allow-invalid-prefix is set)
        if (!mini\args()->getFlag('allow-invalid-prefix')) {
            $invalidPrefixes = [];
            foreach ($pending as $name => $file) {
                if (!hasValidDatetimePrefix($name)) {
                    $invalidPrefixes[] = $name;
                }
            }
            if (!empty($invalidPrefixes)) {
                fwrite(STDERR, "Error: Found migration(s) with invalid prefix format.\n");
                fwrite(STDERR, "Expected format: YYYY_MM_DD_HHMMSS_description.php\n\n");
                foreach ($invalidPrefixes as $name) {
                    fwrite(STDERR, "  - $name\n");
                }
                fwrite(STDERR, "\nRename the file(s) or run with --allow-invalid-prefix to proceed anyway.\n");
                exit(1);
            }
        }

        $batch = getCurrentBatch() + 1;
        echo "Running " . count($pending) . " migration(s) (batch $batch)...\n\n";

        foreach ($pending as $name => $file) {
            echo "Migrating: $name\n";

            $result = runMigration($file, 'up', $runnerScript, $name, $batch);

            if (!empty($result['success'])) {
                // Recording is now done by the subprocess in the same transaction
                $type = $result['type'] ?? 'unknown';
                $reversibleLabel = !empty($result['reversible']) ? ' (reversible)' : '';
                echo "  ✓ Migrated [$type]$reversibleLabel\n\n";
            } else {
                $error = $result['error'] ?? 'Unknown error';
                echo "  ✗ Failed: $error\n";
                if (!empty($result['stderr'])) {
                    echo "  STDERR: " . trim($result['stderr']) . "\n";
                }
                echo "\nMigration halted.\n";
                exit(1);
            }
        }

        echo "All migrations completed.\n";
        break;

    case 'rollback':
    case 'down':
        ensureMigrationsTable();

        $steps = (int)($argument ?? 1);
        if ($steps < 1) $steps = 1;

        $currentBatch = getCurrentBatch();
        if ($currentBatch === 0) {
            echo "Nothing to rollback.\n";
            exit(0);
        }

        $targetBatch = max(0, $currentBatch - $steps + 1);

        $db = getDb();
        $toRollback = iterator_to_array($db->query(
            "SELECT migration, reversible FROM _mini_migrations WHERE batch >= ? ORDER BY migration DESC",
            [$targetBatch]
        ));

        if (empty($toRollback)) {
            echo "Nothing to rollback.\n";
            exit(0);
        }

        // Check for non-reversible migrations and stop before them
        $canRollback = [];
        foreach ($toRollback as $row) {
            if (!$row->reversible) {
                echo "Cannot rollback past '{$row->migration}' (not reversible).\n";
                break;
            }
            $canRollback[] = $row->migration;
        }

        if (empty($canRollback)) {
            echo "Nothing to rollback (first migration is not reversible).\n";
            exit(0);
        }

        echo "Rolling back " . count($canRollback) . " migration(s)...\n\n";

        foreach ($canRollback as $name) {
            $file = findMigrationFile($migrationsRegistry, $name);

            if ($file === null) {
                echo "Warning: Migration file not found: $name.php\n";
                echo "  Removing from tracking table...\n";
                removeMigration($name);
                continue;
            }

            echo "Rolling back: $name\n";

            $result = runMigration($file, 'down', $runnerScript, $name, 0);

            if (!empty($result['success'])) {
                // Removal is now done by the subprocess in the same transaction
                echo "  ✓ Rolled back\n\n";
            } else {
                $error = $result['error'] ?? 'Unknown error';
                echo "  ✗ Failed: $error\n";
                echo "\nRollback halted.\n";
                exit(1);
            }
        }

        echo "Rollback completed.\n";
        break;

    case 'status':
        ensureMigrationsTable();

        $executed = getExecutedMigrations();
        $allMigrations = getAllMigrationFiles($migrationsRegistry);

        if (empty($allMigrations)) {
            echo "No migration files found.\n";
            exit(0);
        }

        echo "Migration Status\n";
        echo "================\n\n";

        $maxLen = max(array_map('strlen', array_keys($allMigrations)));

        foreach ($allMigrations as $name => $file) {
            if (isset($executed[$name])) {
                $info = $executed[$name];
                $rev = $info->reversible ? '↕' : '↑';
                $status = "✓ Ran (batch {$info->batch}) $rev";
            } else {
                $status = "○ Pending";
            }
            printf("  %-{$maxLen}s  %s\n", $name, $status);
        }

        echo "\n";
        $executedCount = count(array_filter(array_keys($allMigrations), fn($n) => isset($executed[$n])));
        $pendingCount = count($allMigrations) - $executedCount;
        echo "Total: $executedCount executed, $pendingCount pending\n";
        echo "Legend: ↕ = reversible, ↑ = up-only\n";
        break;

    case 'fresh':
        echo "Warning: This will reset all migration history!\n";
        echo "All migrations will run again from scratch.\n\n";

        if (!isset($argv[2]) || $argv[2] !== '--force') {
            echo "Add --force to confirm.\n";
            exit(1);
        }

        $db = getDb();
        try {
            $db->exec("DROP TABLE IF EXISTS _mini_migrations");
            echo "Dropped _mini_migrations table.\n\n";
        } catch (Throwable $e) {
            // Table might not exist
        }

        // Re-run migrations
        $_SERVER['argv'] = ['mini-migrations.php', 'migrate'];
        $argv = $_SERVER['argv'];
        $argc = count($argv);
        $command = 'migrate';

        ensureMigrationsTable();

        $allMigrations = getAllMigrationFiles($migrationsRegistry);

        if (empty($allMigrations)) {
            echo "No migration files found.\n";
            exit(0);
        }

        $batch = 1;
        echo "Running " . count($allMigrations) . " migration(s) (batch $batch)...\n\n";

        foreach ($allMigrations as $name => $file) {
            echo "Migrating: $name\n";

            $result = runMigration($file, 'up', $runnerScript);

            if (!empty($result['success'])) {
                $reversible = !empty($result['reversible']);
                recordMigration($name, $batch, $reversible);
                $type = $result['type'] ?? 'unknown';
                $reversibleLabel = $reversible ? ' (reversible)' : '';
                echo "  ✓ Migrated [$type]$reversibleLabel\n\n";
            } else {
                $error = $result['error'] ?? 'Unknown error';
                echo "  ✗ Failed: $error\n";
                echo "\nFresh migration halted.\n";
                exit(1);
            }
        }

        echo "Fresh migration completed.\n";
        break;

    case 'make':
        if (!$argument) {
            echo "Usage: mini migrations make <name>\n";
            echo "Example: mini migrations make create_users_table\n";
            exit(1);
        }

        // Get primary migrations path (application's _migrations directory)
        $primaryPath = $migrationsRegistry->getPaths()[0];

        // Ensure migrations directory exists
        if (!is_dir($primaryPath)) {
            mkdir($primaryPath, 0755, true);
        }

        // Generate filename with timestamp
        $timestamp = date('Y_m_d_His');
        $name = preg_replace('/[^a-z0-9_]/', '_', strtolower($argument));
        $filename = "{$timestamp}_{$name}.php";
        $filepath = $primaryPath . '/' . $filename;

        // Create migration stub
        $stub = <<<'PHP'
<?php
/**
 * Database Migration: %DESCRIPTION%
 * Created: %DATE%
 *
 * Use migrations for database schema changes.
 * For filesystem changes, use version control (git) instead.
 */

// Option 1: Simple migration (one-way, no rollback)
// $db->exec("CREATE TABLE example (id INTEGER PRIMARY KEY, name TEXT)");

// Option 2: Reversible migration (supports rollback)
return new class {
    public function up(mini\Database\DatabaseInterface $db): void {
        // $db->exec("CREATE TABLE example (id INTEGER PRIMARY KEY, name TEXT)");
    }

    public function down(mini\Database\DatabaseInterface $db): void {
        // $db->exec("DROP TABLE example");
    }
};

PHP;

        $stub = str_replace('%DESCRIPTION%', $argument, $stub);
        $stub = str_replace('%DATE%', date('Y-m-d H:i:s'), $stub);

        file_put_contents($filepath, $stub);
        echo "Created: _migrations/$filename\n";
        break;

    case 'help':
    case '--help':
    case '-h':
        echo <<<HELP
Database Migration Tool

Manages database schema changes with version tracking and rollback support.
Use migrations for database changes; use git for filesystem changes.

Usage:
  mini migrations [command] [options]

Commands:
  migrate, up          Run all pending migrations (default)
  rollback, down [n]   Rollback last batch (or last n batches)
  status               Show migration status
  fresh --force        Reset tracking and run all migrations
  make <name>          Create new migration file

Options:
  --allow-invalid-prefix   Allow migrations without datetime prefix (YYYY_MM_DD_HHMMSS_)

Migration Types:

  Simple (one-way):
    <?php
    \$db->exec("CREATE TABLE users (...)");
    // No return value = runs once, cannot rollback

  Reversible (up/down):
    <?php
    return new class {
        public function up(mini\\Database\\DatabaseInterface \$db): void {
            \$db->exec("CREATE TABLE users (...)");
        }
        public function down(mini\\Database\\DatabaseInterface \$db): void {
            \$db->exec("DROP TABLE users");
        }
    };

Notes:
  - Migrations run in isolated subprocesses
  - up() and down() methods receive dependencies via injection
  - File naming: YYYY_MM_DD_HHMMSS_description.php
  - Rollback stops at first non-reversible migration

HELP;
        break;

    default:
        echo "Unknown command: $command\n";
        echo "Run 'mini migrations help' for usage.\n";
        exit(1);
}
