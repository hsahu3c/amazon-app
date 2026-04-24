<?php

namespace App\Amazon\Components\AmazonBuyShipping\Helpers;

use App\Core\Components\Base;

class GetShipmentDocumentHelper extends Base
{
    public function getShipmentId($params)
    {
        $shipmentId = $params['shipment_id'] ?? false;
        if (!$shipmentId) {
            return ['success' => false, 'message' => 'shipment_id required'];
        }

        return ['success' => true, 'data' => $shipmentId];
    }

    public function getPackageClientReferenceId($params)
    {
        $packageClientReferenceId = $params['package_client_reference_id'] ?? false;
        if (!$packageClientReferenceId) {
            return ['success' => false, 'message' => 'packageClientReferenceId is required'];
        }

        return ['success' => true, 'data' => $packageClientReferenceId];
    }

    public function getDocumentFormat($params)
    {
        $documentFormat = $params['document_format'] ?? 'PDF';
        
        return ['success' => true, 'data' => $documentFormat];
    }
}