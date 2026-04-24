<?php

namespace App\Amazon\Components\Template\BulkAttributesEdit;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class ChunkReadFilter implements IReadFilter
{
    private $startRow = 1;
    private $endRow = 1;
    private $headerRow = 1; // Default header row
    private $columns = [];

    /**
     * Optionally, pass in the header row (default is 1).
     */
    public function __construct(int $headerRow = 1)
    {
        $this->headerRow = $headerRow;
    }

    /**
     * Set the row range for the current chunk.
     *
     * @param int   $startRow  The starting row of the chunk.
     * @param int   $chunkSize The number of rows to process.
     * @param array $columns   Optional array of columns to read (e.g., ['A', 'B', 'C'])
     */
    public function setRows(int $startRow, int $chunkSize, array $columns = [])
    {
        $this->startRow = $startRow;
        $this->endRow = $startRow + $chunkSize - 1;
        $this->columns = $columns;
    }

    /**
     * Decide if a cell should be read.
     */
    public function readCell($column, $row, $worksheetName = '')
    {
        // Always include the header row
        if ($row == $this->headerRow) {
            if (!empty($this->columns)) {
                return in_array($column, $this->columns);
            }
            return true;
        }

        // Include cells that are within the current chunk range.
        if ($row >= $this->startRow && $row <= $this->endRow) {
            if (!empty($this->columns)) {
                return in_array($column, $this->columns);
            }
            return true;
        }
        return false;
    }
}
