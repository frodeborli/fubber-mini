<?php
/**
 * Migration Runner
 *
 * Runs all migration files in _migrations/ directory in alphabetical order.
 * Each migration should be idempotent (safe to run multiple times).
 */

// Find composer autoload by walking up directory tree
function findAutoload(): ?string {
    $dir = __DIR__;
    $previousDir = null;

    while ($dir !== $previousDir) {
        $autoloadPath = $dir . '/vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            return $autoloadPath;
        }

        $previousDir = $dir;
        $dir = dirname($dir);
    }

    return null;
}

$autoloadPath = findAutoload();
if (!$autoloadPath) {
    echo "Error: Could not find vendor/autoload.php\n";
    echo "Please run 'composer install' first.\n";
    exit(1);
}

require_once $autoloadPath;

// Bootstrap the mini framework to get database access
mini\bootstrap();

try {
    $db = mini\db();
    echo "Connected to database\n";

    // Look for _migrations directory in current working directory
    $migrationDir = getcwd() . '/_migrations';

    if (!is_dir($migrationDir)) {
        echo "Creating _migrations directory...\n";
        mkdir($migrationDir, 0755, true);
    }

    // Get all PHP files in _migrations directory
    $migrationFiles = glob($migrationDir . '/*.php');
    sort($migrationFiles); // Ensure alphabetical order

    if (empty($migrationFiles)) {
        echo "No migration files found in {$migrationDir}\n";
        exit(0);
    }
    
    echo "Found " . count($migrationFiles) . " migration file(s)\n\n";
    
    foreach ($migrationFiles as $file) {
        $fileName = basename($file);
        echo "Running migration: {$fileName}\n";
        
        try {
            // Include the migration file
            // Each migration file should work with the $db variable
            require $file;
            echo "✓ Migration {$fileName} completed successfully\n";
        } catch (Exception $e) {
            echo "✗ Migration {$fileName} failed: " . $e->getMessage() . "\n";
            echo "Stopping migration process.\n";
            exit(1);
        }
        
        echo "\n";
    }
    
    echo "All migrations completed successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}