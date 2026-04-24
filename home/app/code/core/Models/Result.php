<?php

namespace App\Core\Models;

class Result
{
    public $current = 0;
    public $rows = 0;
    public $count = 0;
    public function __construct($rows)
    {
        $this->rows = $rows;
        $this->count = count($rows);
    }

    public function numRows()
    {
        return $this->count;
    }

    public function fetchAll()
    {
        return $this->rows;
    }
    public function setFetchMode($mode)
    {
        return $this;
    }

    public function count()
    {
        return $this->count;
    }


    public function fetch()
    {
        return $this->rows[$this->current++] ?? false;
    }
}
