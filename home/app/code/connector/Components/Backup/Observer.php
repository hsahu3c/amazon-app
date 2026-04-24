<?php
namespace App\Connector\Components\Backup;
interface Observer {
    public function update(Subject $subject);
}
