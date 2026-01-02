#!/usr/bin/env php
<?php
/**
 * Migration Runner - Isolated execution wrapper
 *
 * This script runs a single migration file in isolation. It's invoked by
 * mini-migrations.php as a subprocess to ensure each migration has a clean state.
 *
 * Arguments:
 *   1. Migration file path (absolute)
 *   2. Direction: 'up' or 'down'
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

if ($argc < 3) {
    echo json_encode(['error' => 'Usage: mini-migration-runner.php <file> <up|down>']);
    exit(1);
}

$migrationFile = $argv[1];
$direction = $argv[2];

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

// Run the migration
try {
    // Provide $db variable for backwards compatibility with simple migrations
    $db = mini\db();

    // Include the migration file and capture return value
    $result = require $migrationFile;

    // Determine migration type based on return value
    if ($result === 1 || $result === true || $result === null) {
        // Simple migration - file executed directly, no rollback support
        if ($direction === 'down') {
            echo json_encode([
                'error' => 'Migration does not support rollback (simple migration)',
                'type' => 'simple'
            ]);
            exit(2);
        }
        // Up already executed by require
        echo json_encode([
            'success' => true,
            'type' => 'simple',
            'message' => 'Migration executed successfully'
        ]);
        exit(0);
    }

    if (is_object($result)) {
        // Object migration - check for up/down methods
        $method = $direction;

        if (!method_exists($result, $method)) {
            if ($direction === 'down') {
                echo json_encode([
                    'error' => "Migration object has no down() method",
                    'type' => 'object'
                ]);
                exit(2);
            }
            echo json_encode([
                'error' => "Migration object has no up() method",
                'type' => 'object'
            ]);
            exit(1);
        }

        // Use Mini::inject() for dependency injection
        $invoker = mini\Mini::$mini->inject([$result, $method]);
        $invoker();

        echo json_encode([
            'success' => true,
            'type' => 'object',
            'reversible' => method_exists($result, 'down'),
            'message' => ucfirst($direction) . ' migration completed'
        ]);
        exit(0);
    }

    // Unknown return type
    echo json_encode([
        'error' => 'Migration must return nothing (simple) or an object with up()/down() methods',
        'returned' => gettype($result)
    ]);
    exit(1);

} catch (Throwable $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'type' => 'exception',
        'class' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit(3);
}
