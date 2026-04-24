<?php
namespace App\Connector\Models\Order;
use App\Core\Models\BaseMongo;

class BackupOrder {
    const collection = 'backup_orders';

    public static function insertMany($orders) {
        $baseMongo = new BaseMongo();
        $baseMongo->setCollection(self::collection);
        return $baseMongo->getCollection()->insertMany($orders);
    }

    public static function deleteMany($orders) {
        $baseMongo = new BaseMongo();
        $baseMongo->setCollection(self::collection);
        return $baseMongo->getCollection()->deleteMany($orders);
    }
}