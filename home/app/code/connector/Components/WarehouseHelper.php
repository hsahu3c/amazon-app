<?php

namespace App\Connector\Components;

class WarehouseHelper extends \App\Core\Components\Base
{

    public function getAllWarehouses()
    {
        $userId = $this->di->getUser()->id;
        $warehouses = \App\Connector\Models\Warehouse::find("merchant_id='{$userId}'");
        $allWarehouses = [];
        $allWarehouses[] = [
            'id' => 'total_qty',
            'value' => 'Total Quantity'
        ];
        if ($warehouses && count($warehouses) > 0) {
            $warehouses = $warehouses->toArray();
            foreach ($warehouses as $value) {
                $allWarehouses[] = [
                    'id' => $value['id'],
                    'value' => $value['name']
                ];
            }
        }

        return $allWarehouses;
    }
}
