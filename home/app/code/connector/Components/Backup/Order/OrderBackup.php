<?php

namespace App\Connector\Components\Backup\Order;

use App\Connector\Components\Backup\Observer;
use App\Connector\Components\Backup\Subject;
use App\Connector\Models\OrderContainer;
use App\Connector\Models\Order\BackupOrder;

class OrderBackup implements Observer
{

    public function update(Subject $subject): void
    {
        $orders = $subject->getOrders();
        $this->backupOrders($orders);
        $subject->clearOrders();
    }

    private function backupOrders($orders): void
    {
        $bulkInsert = [];
        $bulkDelete = [];
        foreach ($orders as $order) {
            $order['_id'] = new \MongoDB\BSON\ObjectId($order['_id']);
            $bulkDelete[] = $order['_id'];
            $tempOrders[] = $order;
        }

        if(!empty($bulkInsert)) {
            BackupOrder::insertMany($tempOrders);
        }

        if(!empty($bulkDelete)) {
            BackupOrder::deleteMany($bulkDelete);
        }
    }

}