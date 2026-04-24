<?php

namespace App\Amazon\Components\AmazonBuyShipping\Helpers;

use App\Core\Components\Base;

class CancelShipmentHelper extends Base
{
    public function getShipmentId($params)
    {
        $shipmentId = $params['shipment_id'] ?? null;
        if (!$shipmentId) {
            return ['success' => false, 'message' => 'shipment_id required'];
        }

        return ['success' => true, 'data' => $shipmentId];
    }
}
