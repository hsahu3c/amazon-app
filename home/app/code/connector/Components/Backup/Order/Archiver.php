<?php
namespace App\Connector\Components\Backup\Order;
use App\Connector\Components\Backup\Backup;
use App\Core\Components\Base;
use App\Core\Components\Message\Handler\Sqs;
use App\Connector\Models\OrderContainer;

class Archiver extends Base implements Backup{
    public function start(string $status, string $fromDate, string $toDate): void {
        $queueData = [
            'queue_name' => 'backup_order',
            'type' => 'full_class',
            'from' => $fromDate,
            'to' => $toDate,
            'status' => $status,
            'page' => 1,
            'class' => 'App\Connector\Components\Backup\Order\Archiver',
            'method' => 'process'
        ];
        $this->di->getObjectManager()->get(Sqs::class)->pushMessage($queueData);
    }

    public function process($data) {
        $sourceOrders = OrderContainer::find([
            'created_at' => [
                '$lte' => new \MongoDB\BSON\UTCDateTime(strtotime($data['to']) * 1000)
            ],
            'object_type' => 'source_order',
            'status' => $data['status']
        ], [
            'projection' => [
                'cif_order_id' => 1
            ],
            'limit' => 100,
            'skip' => ($data['page'] - 1) * 100,
            'typeMap' => [
                'root' => 'array',
                'document' => 'array'
            ]
        ])->toArray();

        if(count($sourceOrders) == 0) {
            $this->notify();
            return true;
        }

        $orderManager = $this->di->getObjectManager()->get(OrderManager::class);
        $orderBackup = $this->di->getObjectManager()->get(OrderBackup::class);
        $orderManager->attach($orderBackup);

        $orderDocs = [];

        foreach($sourceOrders as $sourceOrder) {
            $orderDocs = OrderContainer::find([
                'cif_order_id' => $sourceOrder['cif_order_id'],
            ], [
                'typeMap' => [
                    'root' => 'array',
                    'document' => 'array'
                ]
            ])->toArray();
            $orderManager->addOrder($orderDocs);
        }

        $orderManager->detach($orderBackup);
        $data['page']++;
        $this->di->getObjectManager()->get(Sqs::class)->pushMessage($data);
        return true;
    }

    public function notify(): void {
        $this->di->getLogger()->info('Orders backed up successfully.');
    }
}