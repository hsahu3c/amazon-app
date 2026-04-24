<?php

namespace App\Amazon\Controllers;

use App\Core\Controllers\BaseController;
use App\Amazon\Components\Common\Helper;
use App\Amazon\Service\Shipment;
use App\Core\Models\User;
use Exception;
class ShipmentController extends BaseController
{

    /**
     * syncFailedShipmentAction function
     * To sync failed shipment either through Serverless or Phalcon
     * @param [array] $params
     */
    public function syncFailedShipmentAction(): void
    {
        $completeData = [];
        $successIds = [];
        $notFulfilledIds = [];
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (!empty($rawBody['orderIds'])) {
            $orderIds = $rawBody['orderIds'];
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $query = $mongo->getCollectionForTable(Helper::ORDER_CONTAINER);
            $params = [
                'user_id' => ['$exists' => true],
                'object_type' => 'source_shipment',
                'marketplace' => 'amazon',
                'marketplace_reference_id' => ['$in' => $orderIds],
            ];
            if (!empty($rawBody['user_id'])) {
                $params['user_id'] = $rawBody['user_id'];
            }

            if (!empty($rawBody['shop_id'])) {
                $params['shop_id'] = $rawBody['shop_id'];
            }

            $sourceShipmentData = $query->find(
                $params,
                ["typeMap" => ['root' => 'array', 'document' => 'array']]
            )->toArray();
            if (!empty($sourceShipmentData)) {
                $amazonOrderService = $this->di->getObjectManager()->get(Shipment::class, []);
                foreach ($sourceShipmentData as $data) {
                    if (isset($rawBody['serverless'])) {
                        $data['serverless'] = true;
                    }

                    $response = $amazonOrderService->ship($data);
                    if (isset($response['success']) && $response['success']) {
                        $successIds[] = $data['marketplace_reference_id'];
                    } else {
                        $notFulfilledIds[] = $data['marketplace_reference_id'];
                    }
                }

                $completeData = [
                    'successIds' => array_values($successIds),
                    'notFulfilledIds' => array_values($notFulfilledIds)
                ];
                print_r($completeData);
                die;
            }
        }
    }

    /**
     * syncFailedShipmentAttempt function
     * To sync failed shipment through frontend
     * @return [array]
    */
    public function syncFailedShipmentAttemptAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get(\App\Amazon\Components\Order\Shipment::class)->syncFailedShipmentByManual($rawBody);
        return $this->prepareResponse($response);
    }

    /**
     * setDiForUser function
     * To sync failed shipment either through Serverless or Phalcon
     * @param [array] $params
     * @return array
     */
    public function setDiForUser($userId)
    {
        if ($userId ==  $this->di->getUser()->id) {
            return [
                'success' => true,
                'message' => 'Di Already set'
            ];
        }

        try {
            $getUser = User::findFirst([['_id' => $userId]]);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        if (empty($getUser)) {
            return [
                'success' => false,
                'message' => 'User not found'
            ];
        }

        $getUser->id = (string) $getUser->_id;
        $this->di->setUser($getUser);
        if ($this->di->getUser()->getConfig()['username'] == 'admin') {
            return [
                'success' => false,
                'message' => 'user not found in DB. Fetched di of admin.'
            ];
        }

        return [
            'success' => true,
            'message' => 'user set in di successfully'
        ];
    }
}