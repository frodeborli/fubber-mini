#!/usr/bin/env php
<?php
/**
 * Migration Runner - Isolated execution wrapper
 *
 * This script runs a single migration file in isolation. It's invoked by
 * mini-migrations.php as a subprocess to ensure each migration has a clean state.
 *
 * The migration and its recording are executed in the same transaction for atomicity.
 * If the migration fails, no record is created. If recording fails, the migration is rolled back.
 *
 * Arguments:
 *   1. Migration file path (absolute)
 *   2. Direction: 'up' or 'down'
 *   3. Migration name (for recording)
 *   4. Batch number (for recording)
 *
 * Exit codes:
 *   0 - Success
 *   1 - Migration file error
 *   2 - Down not supported (simple migration)
 *   3 - Exception during migration
 *
 * Output:
 *   JSON object with result details on success, error message on failure
 */

if ($argc < 5) {
    echo json_encode(['error' => 'Usage: mini-migration-runner.php <file> <up|down> <name> <batch>']);
    exit(1);
}

$migrationFile = $argv[1];
$direction = $argv[2];
$migrationName = $argv[3];
$batch = (int) $argv[4];

if (!in_array($direction, ['up', 'down'])) {
    echo json_encode(['error' => "Direction must be 'up' or 'down', got: $direction"]);
    exit(1);
}

if (!file_exists($migrationFile)) {
    echo json_encode(['error' => "Migration file not found: $migrationFile"]);
    exit(1);
}

// Load autoloader and bootstrap Mini framework
try {
    require __DIR__ . '/../ensure-autoloader.php';
    mini\bootstrap();
} catch (Throwable $e) {
    echo json_encode(['error' => 'Framework bootstrap failed: ' . $e->getMessage()]);
    exit(1);
}

// Run the migration within a transaction
$db = mini\db();

try {
    $result = $db->transaction(function($db) use ($migrationFile, $direction, $migrationName, $batch) {
        // Include the migration file and capture return value
        $result = require $migrationFile;

        // Determine migration type based on return value
        if ($result === 1 || $result === true || $result === null) {
            // Simple migration - file executed directly
            if ($direction === 'down') {
                throw new \RuntimeException('SIMPLE_NO_DOWN');
            }
            $reversible = false;
            $type = 'simple';
        } elseif (is_object($result)) {
            $method = $direction;

            if (!method_exists($result, $method)) {
                throw new \RuntimeException($direction === 'down' ? 'OBJECT_NO_DOWN' : 'OBJECT_NO_UP');
            }

            // Use Mini::inject() for dependency injection
            $invoker = mini\Mini::$mini->inject([$result, $method]);
            $invoker();

            $reversible = method_exists($result, 'down');
            $type = 'object';
        } else {
            throw new \RuntimeException('INVALID_RETURN:' . gettype($result));
        }

        // Record or remove migration in the same transaction
        if ($direction === 'up') {
            $db->exec(
                "INSERT INTO _mini_migrations (migration, batch, reversible) VALUES (?, ?, ?)",
                [$migrationName, $batch, $reversible ? 1 : 0]
            );
        } else {
            $db->exec(
                "DELETE FROM _mini_migrations WHERE migration = ?",
                [$migrationName]
            );
        }

        return ['type' => $type, 'reversible' => $reversible];
    });

    echo json_encode([
        'success' => true,
        'type' => $result['type'],
        'reversible' => $result['reversible'],
        'message' => ucfirst($direction) . ' migration completed'
    ]);
    exit(0);

} catch (Throwable $e) {
    $msg = $e->getMessage();

    // Handle known error codes
    if ($msg === 'SIMPLE_NO_DOWN') {
        echo json_encode(['error' => 'Migration does not support rollback (simple migration)', 'type' => 'simple']);
        exit(2);
    }
    if ($msg === 'OBJECT_NO_DOWN') {
        echo json_encode(['error' => 'Migration object has no down() method', 'type' => 'object']);
        exit(2);
    }
    if ($msg === 'OBJECT_NO_UP') {
        echo json_encode(['error' => 'Migration object has no up() method', 'type' => 'object']);
        exit(1);
    }
    if (str_starts_with($msg, 'INVALID_RETURN:')) {
        echo json_encode(['error' => 'Migration must return nothing (simple) or an object with up()/down() methods', 'returned' => substr($msg, 15)]);
        exit(1);
    }

    echo json_encode([
        'error' => $msg,
        'type' => 'exception',
        'class' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit(3);
}
