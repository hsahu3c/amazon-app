<?php
namespace App\Connector\Components\Backup;

interface Backup {
    public function start(string $status, string $fromDate, string $toDate);

    public function process($data);
}