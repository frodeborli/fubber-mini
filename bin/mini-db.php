#!/usr/bin/env php
<?php
/**
 * Database REPL - Interactive SQL shell for Mini framework
 *
 * Usage:
 *   bin/mini db                      - Connect to app database
 *   bin/mini vdb                     - Use VirtualDatabase (for testing)
 *   bin/mini db 'SELECT ...'         - Execute query directly
 *   bin/mini vdb '.tables'           - Run dot-command directly
 *   bin/mini vdb --format=json 'SELECT ...'  - Output as JSON
 *
 * Formats: markdown (default), json, csv
 */

require __DIR__ . '/../ensure-autoloader.php';

use mini\CLI\ReadlineManager;
use mini\CLI\ArgManager;
use mini\Database\DatabaseInterface;
use mini\Database\VirtualDatabase;

// Parse arguments
$args = ArgManager::parse($argv)
    ->withFlag('v', 'virtual')
    ->withOptionalValue('f', 'format', 'markdown');
$useVirtual = $args->getFlag('v') > 0;
$format = $args->getOption('format') ?? 'markdown';
$unparsed = $args->getUnparsedArgs();
$query = $unparsed[0] ?? null;

if (!in_array($format, ['markdown', 'json', 'csv'])) {
    fwrite(STDERR, "Error: Invalid format '$format'. Use: markdown, json, csv\n");
    exit(1);
}

// Get database connection
if ($useVirtual) {
    $configPath = getcwd() . '/_config/mini/Database/VirtualDatabase.php';
    if (file_exists($configPath)) {
        $db = require $configPath;
        if (!$db instanceof VirtualDatabase) {
            fwrite(STDERR, "Error: Config must return a VirtualDatabase instance\n");
            exit(1);
        }
    } else {
        $db = new VirtualDatabase();
        if ($query === null) {
            // Only show note in interactive mode
            echo "Note: No VirtualDatabase config found at _config/mini/Database/VirtualDatabase.php\n";
            echo "Using empty VirtualDatabase\n";
        }
    }
    $prompt = 'vdb> ';
} else {
    if (!function_exists('mini\\bootstrap')) {
        fwrite(STDERR, "Error: mini\\bootstrap() not available. Run from a Mini project directory.\n");
        exit(1);
    }
    \mini\bootstrap();
    $db = \mini\db();
    $prompt = 'sql> ';
}

// If query/command provided as argument, execute and exit
if ($query !== null) {
    if (str_starts_with($query, '.')) {
        handleDotCommand($db, $query, $useVirtual, $format);
    } else {
        executeQuery($db, $query, $format);
    }
    exit(0);
}

// Interactive REPL
$rl = new ReadlineManager($prompt);

// Set up completion - keywords (case insensitive) vs identifiers (case sensitive)
$keywords = [
    'SELECT', 'FROM', 'WHERE', 'AND', 'OR', 'NOT', 'IN', 'LIKE', 'BETWEEN',
    'ORDER', 'BY', 'ASC', 'DESC', 'LIMIT', 'OFFSET', 'GROUP', 'HAVING',
    'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE',
    'JOIN', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'ON', 'AS',
    'NULL', 'IS', 'TRUE', 'FALSE', 'DISTINCT', 'COUNT', 'SUM', 'AVG', 'MIN', 'MAX',
];

$identifiers = ['.help', '.tables', '.schema', '.quit', '.exit', '.format'];

// Add table and column names for VirtualDatabase
if ($db instanceof VirtualDatabase) {
    foreach ($db->getTableNames() as $table) {
        $identifiers[] = $table;
        $t = $db->getTable($table);
        if ($t) {
            foreach ($t->getColumns() as $colName => $def) {
                $identifiers[] = $colName;
            }
        }
    }
}

// Context-aware completion: regex => array|Closure (all matching patterns contribute)
$allTables = [];
$allColumns = [];
if ($db instanceof VirtualDatabase) {
    $allTables = $db->getTableNames();
    foreach ($allTables as $table) {
        $t = $db->getTable($table);
        if ($t) {
            foreach ($t->getColumns() as $colName => $_) {
                $allColumns[$colName] = true;
            }
        }
    }
    $allColumns = array_keys($allColumns);
}

$completionPatterns = [
    '/\bselect\s+(.+,\s*)?$/i' => fn() => array_merge(['*'], $allColumns),
    '/\bfrom\s+(.+,\s*)?$/i' => fn() => array_merge($allTables, array_map(fn($t) => "$t ", $allTables)),
];

$multilineBuffer = '';

$rl->setCompletionFunction(function ($input) use ($keywords, $identifiers, &$completionPatterns, &$multilineBuffer) {
    $buffer = ltrim($multilineBuffer . readline_info('line_buffer'));
    $isUpper = $input !== '' && ctype_upper($input[0]);

    // Complete standalone symbols to trigger space insertion
    if ($input === '*') {
        return ['*'];
    }

    // Try context-aware patterns (all matching patterns contribute)
    $matches = [];
    $hasPatternMatch = false;
    foreach ($completionPatterns as $pattern => $suggestions) {
        if (preg_match($pattern, $buffer, $matches)) {
            $hasPatternMatch = true;
            // Get suggestions from closure or array
            $items = is_callable($suggestions) ? $suggestions($matches) : $suggestions;

            foreach ($items as $item) {
                if ($input === '' || stripos($item, $input) === 0) {
                    // Preserve case for identifiers (lowercase first char), apply user case for keywords
                    if ($item !== '' && ctype_lower($item[0])) {
                        $matches[] = $item;
                    } else {
                        $matches[] = $isUpper ? strtoupper($item) : strtolower($item);
                    }
                }
            }
        }
    }

    if ($hasPatternMatch) {
        return array_unique($matches);
    }

    // Fall back to general keyword/identifier completion
    $matches = [];
    if ($input !== '') {
        foreach ($keywords as $kw) {
            if (stripos($kw, $input) === 0) {
                $matches[] = $isUpper ? $kw : strtolower($kw);
            }
        }
        foreach ($identifiers as $id) {
            if (strpos($id, $input) === 0) {
                $matches[] = $id;
            }
        }
    }

    return array_unique($matches);
});

// Set up Ctrl+C handling
pcntl_signal(SIGINT, function() use ($rl) {
    echo "^C\n";
    $rl->cancel();
});
pcntl_async_signals(true);

// Load history from file
$historyFile = getenv('HOME') . '/.mini_db_history';
if (file_exists($historyFile)) {
    $history = file($historyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $rl->loadHistory(array_slice($history, -100)); // Keep last 100 entries
}

echo "Mini Database REPL" . ($useVirtual ? " (VirtualDatabase)" : "") . "\n";
echo "Type SQL queries, .help for commands, or Ctrl+D to exit\n";
echo "Output format: $format (change with .format <markdown|json|csv>)\n\n";

$multilinePrompt = '...> ';

while (($line = $rl->prompt($multilineBuffer ? $multilinePrompt : null)) !== null) {
    if ($line === '') {
        // Empty line or Ctrl+C - clear multiline buffer if any
        if ($multilineBuffer) {
            $multilineBuffer = '';
            echo "Query cancelled\n";
        }
        continue;
    }

    // Accumulate multiline input
    $multilineBuffer .= ($multilineBuffer ? "\n" : '') . $line;

    // Check for dot commands (must be single line)
    if ($multilineBuffer[0] === '.' && strpos($multilineBuffer, "\n") === false) {
        handleDotCommand($db, $multilineBuffer, $useVirtual, $format);
        $multilineBuffer = '';
        continue;
    }

    // Check if statement is complete (ends with semicolon)
    $trimmed = rtrim($multilineBuffer);
    if (substr($trimmed, -1) !== ';') {
        continue; // Wait for more input
    }

    // Execute the complete query
    $rl->addHistory($multilineBuffer);
    file_put_contents($historyFile, $multilineBuffer . "\n", FILE_APPEND);

    executeQuery($db, $multilineBuffer, $format);
    $multilineBuffer = '';
}

echo "\nBye!\n";

function executeQuery(DatabaseInterface $db, string $sql, string $format): void
{
    $sql = rtrim($sql, "; \t\n\r");

    try {
        $result = $db->query($sql);
        displayResults($result, $format);
    } catch (Throwable $e) {
        if ($format === 'json') {
            echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT) . "\n";
        } else {
            fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        }
    }
}

function displayResults(iterable $result, string $format): void
{
    $rows = [];
    $columns = [];

    foreach ($result as $row) {
        $row = (array) $row;
        if (empty($columns)) {
            $columns = array_keys($row);
        }
        $rows[] = $row;
    }

    match ($format) {
        'json' => displayJson($rows),
        'csv' => displayCsv($rows, $columns),
        default => displayMarkdown($rows, $columns),
    };
}

function displayJson(array $rows): void
{
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

function displayCsv(array $rows, array $columns): void
{
    if (empty($rows)) {
        return;
    }

    // Header
    echo implode(',', array_map('escapeCsvField', $columns)) . "\n";

    // Rows
    foreach ($rows as $row) {
        $values = [];
        foreach ($columns as $col) {
            $values[] = escapeCsvField($row[$col] ?? '');
        }
        echo implode(',', $values) . "\n";
    }
}

function escapeCsvField(mixed $value): string
{
    $str = (string) $value;
    if (str_contains($str, ',') || str_contains($str, '"') || str_contains($str, "\n")) {
        return '"' . str_replace('"', '""', $str) . '"';
    }
    return $str;
}

function displayMarkdown(array $rows, array $columns): void
{
    if (empty($rows)) {
        echo "(0 rows)\n";
        return;
    }

    // Calculate column widths
    $widths = [];
    foreach ($columns as $col) {
        $widths[$col] = strlen($col);
    }
    foreach ($rows as $row) {
        foreach ($columns as $col) {
            $val = $row[$col] ?? '';
            $widths[$col] = max($widths[$col], strlen((string) $val));
        }
    }

    // Print header
    $header = '|';
    foreach ($columns as $col) {
        $header .= ' ' . str_pad($col, $widths[$col]) . ' |';
    }
    echo $header . "\n";

    // Print separator
    $sep = '|';
    foreach ($columns as $col) {
        $sep .= str_repeat('-', $widths[$col] + 2) . '|';
    }
    echo $sep . "\n";

    // Print rows
    foreach ($rows as $row) {
        $output = '|';
        foreach ($columns as $col) {
            $val = $row[$col] ?? '';
            $output .= ' ' . str_pad((string) $val, $widths[$col]) . ' |';
        }
        echo $output . "\n";
    }

    echo "\n(" . count($rows) . " row" . (count($rows) !== 1 ? "s" : "") . ")\n";
}

function handleDotCommand(DatabaseInterface $db, string $cmd, bool $isVirtual, string &$format): void
{
    $parts = preg_split('/\s+/', trim($cmd), 2);
    $command = $parts[0];
    $arg = $parts[1] ?? '';

    switch ($command) {
        case '.help':
            echo "Commands:\n";
            echo "  .help              Show this help\n";
            echo "  .tables            List all tables\n";
            echo "  .schema [table]    Show table schema\n";
            echo "  .format <fmt>      Set output format (markdown, json, csv)\n";
            echo "  .quit              Exit the REPL\n";
            break;

        case '.format':
            if (!in_array($arg, ['markdown', 'json', 'csv'])) {
                echo "Usage: .format <markdown|json|csv>\n";
                break;
            }
            $format = $arg;
            echo "Output format set to: $format\n";
            break;

        case '.tables':
            $tables = [];
            foreach ($db->getSchema()->eq('type', 'column') as $row) {
                $tables[$row->table_name] = true;
            }
            $tables = array_keys($tables);
            sort($tables);

            if ($format === 'json') {
                echo json_encode($tables, JSON_PRETTY_PRINT) . "\n";
            } else {
                foreach ($tables as $table) {
                    echo "$table\n";
                }
            }
            break;

        case '.schema':
            $schema = $db->getSchema();
            if ($arg) {
                $schema = $schema->eq('table_name', $arg);
            }

            // Group by table
            $tableSchemas = [];
            foreach ($schema as $row) {
                $tableName = $row->table_name;
                if (!isset($tableSchemas[$tableName])) {
                    $tableSchemas[$tableName] = ['columns' => [], 'indexes' => []];
                }
                if ($row->type === 'column') {
                    $tableSchemas[$tableName]['columns'][$row->name] = [
                        'type' => $row->data_type,
                        'nullable' => $row->is_nullable,
                        'default' => $row->default_value,
                        'ordinal' => $row->ordinal,
                    ];
                } else {
                    $tableSchemas[$tableName]['indexes'][$row->name] = [
                        'type' => $row->type,
                        'columns' => $row->extra,
                    ];
                }
            }

            if ($format === 'json') {
                echo json_encode($tableSchemas, JSON_PRETTY_PRINT) . "\n";
            } else {
                foreach ($tableSchemas as $table => $info) {
                    echo "## $table\n\n";

                    // Columns
                    echo "| Column | Type | Nullable | Default |\n";
                    echo "|--------|------|----------|--------|\n";
                    foreach ($info['columns'] as $name => $col) {
                        $nullable = $col['nullable'] ? 'yes' : 'no';
                        $default = $col['default'] ?? '-';
                        echo "| $name | {$col['type']} | $nullable | $default |\n";
                    }

                    // Indexes
                    if (!empty($info['indexes'])) {
                        echo "\n**Indexes:**\n";
                        foreach ($info['indexes'] as $name => $idx) {
                            echo "- $name ({$idx['type']}): {$idx['columns']}\n";
                        }
                    }
                    echo "\n";
                }
            }
            break;

        case '.quit':
        case '.exit':
            exit(0);

        default:
            echo "Unknown command: $command\n";
            echo "Type .help for available commands\n";
    }
}
