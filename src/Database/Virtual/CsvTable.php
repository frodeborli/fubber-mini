<?php

namespace mini\Database\Virtual;

use mini\Parsing\SQL\AST\SelectStatement;

/**
 * Helper to create a CSV-backed virtual table
 *
 * Simple example showing how to create virtual tables.
 * Real implementations can optimize by inspecting the AST.
 *
 * Example:
 * ```php
 * $vdb->registerTable('countries', CsvTable::fromFile(
 *     '/path/to/countries.csv',
 *     hasHeader: true
 * ));
 * ```
 */
class CsvTable
{
    /**
     * Create a VirtualTable from a CSV file
     *
     * @param string $filePath Path to CSV file
     * @param bool $hasHeader Whether first row is headers
     * @param string $delimiter Field delimiter
     * @param string $enclosure Field enclosure character
     * @param string $escape Escape character
     */
    public static function fromFile(
        string $filePath,
        bool $hasHeader = true,
        string $delimiter = ',',
        string $enclosure = '"',
        string $escape = '\\'
    ): VirtualTable {
        return new VirtualTable(
            selectFn: function(SelectStatement $ast, \Collator $collator) use ($filePath, $hasHeader, $delimiter, $enclosure, $escape): iterable {
                $file = fopen($filePath, 'r');
                if ($file === false) {
                    throw new \RuntimeException("Cannot open CSV file: $filePath");
                }

                try {
                    // Read headers
                    $headers = null;
                    if ($hasHeader) {
                        $headers = fgetcsv($file, 0, $delimiter, $enclosure, $escape);
                    }

                    // No OrderInfo - let engine handle everything
                    // Advanced implementation could:
                    // - Check if file is sorted
                    // - Yield OrderInfo(column: 'id', desc: false)
                    // - Apply WHERE filters during scan for efficiency

                    // Yield Row instances with row index as ID
                    $rowId = 0;
                    while (($row = fgetcsv($file, 0, $delimiter, $enclosure, $escape)) !== false) {
                        if ($headers) {
                            yield new Row($rowId, array_combine($headers, $row));
                        } else {
                            yield new Row($rowId, $row);
                        }
                        $rowId++;
                    }
                } finally {
                    fclose($file);
                }
            }
        );
    }

    /**
     * Create a VirtualTable from in-memory array
     *
     * Useful for testing or small datasets
     *
     * @param array $rows Rows to include. Can be:
     *   - Associative array with row IDs as keys: ['id1' => [...], 'id2' => [...]]
     *   - Sequential array (will use numeric indices as IDs): [0 => [...], 1 => [...]]
     */
    public static function fromArray(array $rows): VirtualTable
    {
        return new VirtualTable(
            selectFn: function(SelectStatement $ast, \Collator $collator) use ($rows): iterable {
                // Yield Row instances with keys preserved (row IDs)
                foreach ($rows as $rowId => $columns) {
                    yield new Row($rowId, $columns);
                }
            }
        );
    }
}
