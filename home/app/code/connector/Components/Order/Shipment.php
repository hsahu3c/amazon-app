<?php

namespace App\Connector\Components\Order;

class Shipment extends \App\Core\Components\Base
{
    /**
     * intiateShipment function
     * To call source and target marketplace functions
     * @param [array] $data
     * @return array
     */
    public function initiateShipment($data)
    {
        $className = \App\Connector\Contracts\Sales\Order\ShipInterface::class;
        $logFile = "shipment/{$data['user_id']}/" . date('d-m-Y') . '.log';
        $sourceShipmentService = $this->di->getObjectManager()->get($className, [], $data['marketplace']);
        $moldData = $sourceShipmentService->mold($data);
        if (!empty($moldData)) {
            $connectorShipmentService = $this->di->getObjectManager()->get($className, []);
            $this->addLog($logFile, 'Initiating Connector Call...', "", 'optional');
            if (!isset($moldData['marketplace']) || !isset($moldData['shop_id'])) {
                $moldData['marketplace'] = $data['marketplace'];
                $moldData['shop_id'] = $data['shop_id'];
            }
            $shipmentServiceV2 = $this->di->getConfig()->get('shipmentServiceV2') ?? false;
            if ($shipmentServiceV2) {
                $shipmentTransactionBetaUsers = $this->di->getConfig()->get('shipmentTransactionBetaUsers') ? $this->di->getConfig()->get('shipmentTransactionBetaUsers')->toArray() : [];
                if (!empty($shipmentTransactionBetaUsers) && in_array($data['user_id'], $shipmentTransactionBetaUsers)) {
                    $savedData = $this->di->getObjectManager()->get('App\Connector\Service\ShipmentTransaction')
                        ->ship($moldData);
                } else {
                    $savedData = $this->di->getObjectManager()->get('App\Connector\Service\ShipmentV2')
                        ->ship($moldData);
                }
            } else {
                $savedData = $connectorShipmentService->ship($moldData);
            }

            if (isset($savedData['success']) && $savedData['success']) {
                $this->addLog($logFile, 'Connector Call Completed Successfully!!', "", 'optional');
                $targetShipmentService = $this->di->getObjectManager()
                    ->get($className, [], $savedData['data']['marketplace']);
                $targetShipmentService->ship($savedData['data']);
                return ['success' => true, 'message' => "Shipment Initiated"];
            } else {
                $this->addLog($logFile, 'Unable to prepare shipment data, Error:', $savedData['message'], 'critical');
                return ['success' => false, 'message' => $savedData['message']];
            }
        } else {
            $this->addLog($logFile, 'Empty Mold Data', "", 'critical');
            return ['success' => false, 'message' => 'Data not found'];
        }

        $this->di->getLog()->logContent('Empty Mold Data', 'info', $logFile);
        return ['success' => false, 'message' => 'Data not found'];
    }

    public function addLog($file, $key = "", $data = "", $priorityLevel = null)
    {
        $orderLogConfig = $this->di->getConfig()->path('logger_config.shipment_sync');
        $priorityEnabled = $orderLogConfig['priority'][$priorityLevel] ?? true;
        $isLogEnabled = $orderLogConfig['enabled'] ?? true;
        $shipmentLogEnabled = ($priorityEnabled && $isLogEnabled);
        if ($shipmentLogEnabled) {
            if (!empty($data)) {
                $this->di->getLog()->logContent($key . ' ' . json_encode($data, true), 'info', $file);
            } else {
                $this->di->getLog()->logContent($key, 'info', $file);
            }
        }
    }
}
