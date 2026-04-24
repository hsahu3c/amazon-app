<?php

namespace App\Amazon\Components\AmazonBuyShipping\Helpers;

use App\Core\Components\Base;

class GetTrackingHelper extends Base
{
    public function getTrackingId($params)
    {
        $trackingId = $params['tracking_id'] ?? null;
        if (!$trackingId) {
            return ['success' => false, 'message' => 'tracking_id required'];
        }

        return ['success' => true, 'data' => $trackingId];
    }

    public function getCarrierId($params)
    {
        $carrierId = $params['carrier_id'] ?? null;
        if (!$carrierId) {
            return ['success' => false, 'message' => 'carrier_id required'];
        }

        return ['success' => true, 'data' => $carrierId];
    }
}