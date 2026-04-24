<?php

namespace App\Shopifyhome\Controllers;

use App\Core\Controllers\BaseController;
use App\Shopifyhome\Components\Helper;
class ShipmentController extends BaseController
{
    public function fulfillOrderAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (!empty($rawBody['orderIds'])) {
            $orderIds = $rawBody['orderIds'];
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $query = $mongo->getCollectionForTable('order_container');
            $marketplace = 'shopify';
            $params = [
                'user_id' => !empty($rawBody['user_id']) ? $rawBody['user_id'] : ['$ne' => null],
                'shop_id' => !empty($rawBody['shop_id']) ? $rawBody['shop_id'] : ['$ne' => null],
                'object_type' => ['$in' => ['source_order', 'target_order']],
                'marketplace_shop_id' => ['$ne' => null],
                'marketplace' => $marketplace,
                'marketplace_reference_id' =>  ['$in' => $orderIds]
            ];
            $orderData = $query->find(
                $params,
                [
                    'projection' => [
                        'marketplace_shop_id' => 1,
                        'user_id' => 1,
                        'marketplace_reference_id' => 1
                    ]
                ]
            )->toArray();
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $shopifyHelper = $this->di->getObjectManager()->get(Helper::class);
            $object = $this->di->getObjectManager()->get('App\\Connector\\Models\\SourceModel');
            $successIds = $notFulfilledIds = $users = [];
            if (!empty($orderData)) {
                foreach ($orderData as $data) {
                    if (!isset($users[$data['user_id']])) {
                        $users[$data['user_id']]['shop_id'] = $data['marketplace_shop_id'];
                    }

                    $users[$data['user_id']]['orderIds'][] = $data['marketplace_reference_id'];
                }

                foreach ($users as $user => $data) {
                    $shopData = $userDetails->getShop($data['shop_id'], $user);
                    if (!empty($shopData)) {
                        $appCode = $shopData['apps'][0]['code'] ?? 'default';
                        foreach ($data['orderIds'] as $orderId) {
                            $orderFetchParams = [
                                'shop_id' => $shopData['remote_shop_id'],
                                'id' => $orderId,
                                'app_code' => $appCode
                            ];
                            $response = $shopifyHelper->sendRequestToShopify('/order', [], $orderFetchParams, 'GET');
                            if ($response['success'] && isset($response['data']['fulfillments'])
                                && !empty($response['data']['fulfillments'])) {
                                $prepareData = [];
                                foreach ($response['data']['fulfillments'] as $fulfillments) {
                                    $prepareData = [
                                        'shop' => $shopData['myshopify_domain'],
                                        'data' => $fulfillments,
                                        'user_id' => $user,
                                        'shop_id' => $data['shop_id'],
                                        'type' => 'full_class',
                                        'class_name' => '\\App\\Connector\\Models\\SourceModel',
                                        'action' => 'fulfillments_update',
                                        'marketplace' => $marketplace
                                    ];
                                    $getResponse = $object->triggerWebhooks($prepareData);
                                    if ($getResponse) {
                                        $successIds[] = $orderId;
                                    }
                                }
                            } else {
                                $notFulfilledIds[] = $orderId;
                            }
                        }
                    }
                }
            }

            $completeData = [
                'successIds' => array_values(array_unique($successIds)),
                'notFulfilledIds' => array_values(array_unique($notFulfilledIds))
            ];
            return $this->prepareResponse($completeData);
        }
    }

    /**
     * retryShipmentByOrderId function
     * Action to retry shipment from shopify as webhook backup
     * @return array
    */
    public function retryShipmentByOrderIdAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Order\Shipment')->retryShipmentByOrderId($rawBody);
        return $this->prepareResponse($response);
    }
}
