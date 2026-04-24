<?php


namespace App\Amazon\Components\Order;

use App\Connector\Models\Order;
use App\Core\Components\Base;
use MongoDB\BSON\ObjectId;
use App\Amazon\Service\Shipment as AmazonShipmentService;
use Exception;

class Shipment extends Base
{
    private Order $orderCollection;

    const SOURCE_ORDER = "source_order";
    const SOURCE_SHIPMENT = "source_shipment";
    const FAILED_SHIPMENT_ORDER_LIMIT = 1000;
    const MARKETPLACE = 'amazon';
    const FAILED_SHIPMENT_QUEUE = 'amazon_sync_failed_shipment';
    const SEARCH_INDEX = 'order_stat';

    public function __construct(Order $orderContainer)
    {
        $this->orderCollection = $orderContainer;
    }


    /**
     * Initiating failed shipment backup process through CRON
     *
     * @param array $sqsData
     * @return array
     */
    public function initiateFailedShipmentSyncProcess($sqsData = [])
    {
        try {
            $logFile = self::MARKETPLACE . "/" . "failed_shipment_sync_cron/" . date('d-m-Y') . '.log';
            $today = date("Y-m-d");
            $id = null;
            if (!empty($sqsData['last_traversed_object_id']['$oid'])) {
                $id = new ObjectId($sqsData['last_traversed_object_id']['$oid']);
            }

            $searchPipeline = [
                [
                    '$search' => [
                        'index' => self::SEARCH_INDEX,
                        'compound' => [
                            'must' => [
                                ['equals' => ['value' => self::SOURCE_ORDER, 'path' => 'object_type']],
                                ['equals' => ['value' => self::MARKETPLACE, 'path' => 'marketplace']],
                                ['text' => ['query' => 'Created', 'path' => 'status']],
                                ['equals' => ['value' => 'merchant', 'path' => 'fulfilled_by']],
                                [
                                    'compound' => [
                                        'should' => [
                                            [
                                                'compound' => [
                                                    'must' => [
                                                        ['text' => ['query' => 'Unshipped', 'path' => 'shipping_status']],
                                                        ['exists' => ['path' => 'shipment_error']]
                                                    ]
                                                ]
                                            ],
                                            [
                                                'compound' => [
                                                    'must' => [
                                                        ['text' => ['query' => 'In Progress', 'path' => 'shipping_status']],
                                                        ['equals' => ['value' => 'Unshipped', 'path' => 'marketplace_status']]
                                                    ],
                                                    'mustNot' => [
                                                        ['exists' => ['path' => 'shipment_error']]
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ],
                        'sort' => ['_id' => 1]
                    ]
                ],
                $id !== null ? ['$match' => ['_id' => ['$gt' => $id]]] : [],
                [
                    '$match' => [
                        'attributes' => [
                            '$elemMatch' => [
                                'key' => 'Amazon LatestShipDate',
                                'value' => ['$gte' => $today]
                            ]
                        ]
                    ]
                ],
                ['$limit' => self::FAILED_SHIPMENT_ORDER_LIMIT],
                [
                    '$project' => [
                        'user_id' => 1,
                        'marketplace_reference_id' => 1,
                        'marketplace_shop_id' => 1,
                        'shipment_error' => 1
                    ]
                ]
            ];
            $searchPipeline = array_values(array_filter($searchPipeline));
            $failedShipmentData = $this->orderCollection->getCollection()->aggregate($searchPipeline, [
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ])->toArray();
            if (!empty($failedShipmentData)) {
                $preparedData = [];
                foreach ($failedShipmentData as $data) {
                    if (isset($data['user_id'], $data['marketplace_shop_id'], $data['marketplace_reference_id']) && (empty($data['shipment_error']) || $this->shouldReattemptShipment(end($data['shipment_error'])))) {
                        $preparedData[$data['user_id']][$data['marketplace_shop_id']][] = $data['marketplace_reference_id'];
                    }
                }
                if (!empty($preparedData)) {
                    $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
                    foreach ($preparedData as $userId => $shop) {
                        $response = $this->setDiForUser($userId);
                        if (isset($response['success']) && $response['success']) {
                            foreach ($shop as $shopId => $orderIds) {
                                $handlerData = [
                                    'type' => 'full_class',
                                    'class_name' => \App\Amazon\Components\Order\Shipment::class,
                                    'method' => 'syncFailedShipmentByCRON',
                                    'queue_name' => self::FAILED_SHIPMENT_QUEUE,
                                    'user_id' => $userId,
                                    'shop_id' => (string) $shopId,
                                    'data' => $orderIds
                                ];
                                $sqsHelper->pushMessage($handlerData);
                            }
                        } else {
                            $this->di->getLog()->logContent('Unable to set di for user: ' . json_encode($userId), 'info', $logFile);
                        }
                    }

                    $endUser = end($failedShipmentData);
                    $handlerData = [
                        'type' => 'full_class',
                        'class_name' => \App\Amazon\Components\Order\Shipment::class,
                        'method' => 'initiateFailedShipmentSyncProcess',
                        'queue_name' => self::FAILED_SHIPMENT_QUEUE,
                        'user_id' => $endUser['user_id'],
                        'last_traversed_object_id' => $endUser['_id']
                    ];
                    $sqsHelper->pushMessage($handlerData);
                }
            }
            return ['success' => true, 'message' => 'Shipment process initiated'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from initiateFailedShipmentSyncProcess(), Error: ' . json_encode($e->getMessage()), 'emergency', 'exception.log');
        }
    }

    /**
     * Calling Amazon shipment service to sync failed shipments by CRON
     *
     * @param array $sqsData
     * @return array
     */
    public function syncFailedShipmentByCRON($sqsData = [])
    {
        $logFile = self::MARKETPLACE . "/" . "failed_shipment_sync_cron/" . date('d-m-Y') . '.log';
        $this->di->getLog()->logContent('Initiating failed shipment sync cron process for: ' . json_encode([
            'user_id' => $this->di->getUser()->id ?? $sqsData['user_id'],
            'order_ids' => $sqsData['data']
        ]), 'info', $logFile);
        $params = [
            'user_id' => $this->di->getUser()->id ?? $sqsData['user_id'],
            'object_type' => self::SOURCE_SHIPMENT,
            'marketplace_shop_id' => $sqsData['shop_id'],
            'marketplace' => self::MARKETPLACE,
            'marketplace_reference_id' => ['$in' => $sqsData['data']]
        ];
        $sourceShipmentData = $this->orderCollection->find(
            $params,
            ["typeMap" => ['root' => 'array', 'document' => 'array']]
        )->toArray();
        if (!empty($sourceShipmentData)) {
            $shipmentSuccess = [];
            $shipmentErrors = [];
            $shipmentService = $this->di->getObjectManager()->get(AmazonShipmentService::class, []);
            foreach ($sourceShipmentData as $data) {
                $data['shipment_mail'] = false; // Not sending mail when reattempting shipment
                if (!empty($data['shipment_sync_history'])) {
                    $lastSyncedShipmentDetails = end($data['shipment_sync_history']);
                    if (!empty($lastSyncedShipmentDetails['response_data']['message']) && $this->shouldReattemptShipment($lastSyncedShipmentDetails['response_data']['message'])) {
                        if (
                            !empty($lastSyncedShipmentDetails['response_data']['error']['message']['error']) &&
                            $lastSyncedShipmentDetails['response_data']['error']['message']['error'] == 'invalid_grant'
                        ) {
                            $knownShipmentError = 'Token Expired';
                            $shipmentErrors[$data['marketplace_reference_id']][$data['shipment_id']] = "By passing known shipment error: $knownShipmentError";
                            continue;
                        }
                        $this->setShippingStatus($data);
                        $response = $shipmentService->ship($data);
                        if (isset($response['success']) && $response['success']) {
                            $shipmentSuccess[$data['marketplace_reference_id']][$data['shipment_id']] = $response['message'] ?? 'Shipment Synced Successfully';
                        } else {
                            $shipmentErrors[$data['marketplace_reference_id']][$data['shipment_id']] = $response['message'] ?? 'Something Went Wrong';
                        }
                    } else {
                        $knownShipmentError = $lastSyncedShipmentDetails['response_data']['message'] ?? 'Blank shipment error message';
                        $shipmentErrors[$data['marketplace_reference_id']][$data['shipment_id']] = "By passing known shipment error: $knownShipmentError";
                    }
                } else {
                    $this->setShippingStatus($data);
                    $response = $shipmentService->ship($data);
                    if (isset($response['success']) && $response['success']) {
                        $shipmentSuccess[$data['marketplace_reference_id']][$data['shipment_id']] = $response['message'] ?? 'Shipment Synced Successfully';
                    } else {
                        $shipmentErrors[$data['marketplace_reference_id']][$data['shipment_id']] = $response['message'] ?? 'Something Went Wrong';
                    }
                }
            }
            $this->di->getLog()->logContent('Shipment sync response data: ' . json_encode([
                'user_id' => $this->di->getUser()->id ?? $sqsData['user_id'],
                'shipmentSuccess' => $shipmentSuccess,
                'shipmentErrors' => $shipmentErrors
            ]), 'info', $logFile);
        } else {
            $this->di->getLog()->logContent('Source shipment data not found for: ' . json_encode([
                'user_id' => $this->di->getUser()->id ?? $sqsData['user_id'],
                'order_ids' => $sqsData['data']
            ]), 'info', $logFile);
        }

        return ['success' => true, 'message' => 'Shipment sync process completed'];
    }

    /**
     * Updating shipping_status to In Progress before calling Amazon Ship Service
     *
     * @param array $data
     * @return null
     */
    private function setShippingStatus($data): void
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $orderContainer = $mongo->getCollectionForTable('order_container');
            $orderContainer->updateMany(
                [
                    'user_id' => $data['user_id'],
                    'object_type' => self::SOURCE_ORDER,
                    'marketplace_shop_id' => (string)$data['shop_id'],
                    'marketplace' => self::MARKETPLACE,
                    'marketplace_reference_id' => $data['marketplace_reference_id']
                ],
                [
                    '$set' => ['shipping_status' => 'In Progress']
                ]
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from setShippingStatus(), Error: ' . json_encode($e->getMessage()), 'emergency', 'exception.log');
        }
    }

    /**
     * Calling Amazon shipment service to sync failed shipments by manual
     *
     * @param array $data
     * @return array
     */
    public function syncFailedShipmentByManual($data)
    {
        $logFile = self::MARKETPLACE . "/" . "failed_shipment_sync_manual/" . date('d-m-Y') . '.log';
        if (!empty($data['order_id'])) {
            $this->di->getLog()->logContent('Initiating failed shipment sync manual process for: ' . json_encode([
                'user_id' => $this->di->getUser()->id ?? $data['user_id'],
                'order_id' => $data['order_id']
            ]), 'info', $logFile);
            $shopId =  $this->di->getRequester()->getTargetId()  ?? $data['target_shop_id'];
            $params = [
                'user_id' => $this->di->getUser()->id ?? $data['user_id'],
                'object_type' => self::SOURCE_SHIPMENT,
                'marketplace_shop_id' => $shopId,
                'marketplace' => self::MARKETPLACE,
                'marketplace_reference_id' => $data['order_id']
            ];
            $sourceShipmentData = $this->orderCollection->find(
                $params,
                ["typeMap" => ['root' => 'array', 'document' => 'array']]
            )->toArray();
            if (!empty($sourceShipmentData)) {
                $prepareData = [
                    'user_id' => $this->di->getUser()->id ?? $data['user_id'],
                    'shop_id' => $shopId,
                    'marketplace_reference_id' => $data['order_id']
                ];
                $shipmentService = $this->di->getObjectManager()->get(AmazonShipmentService::class, []);
                $shipmentErrors = [];
                $shipmentSuccess = [];
                foreach ($sourceShipmentData as $data) {
                    $knownShipmentError = null;
                    $data['shipment_mail'] = false; // Not sending mail when reattempting shipment
                    if (!empty($data['shipment_sync_history'])) {
                        $lastSyncedShipmentDetails = end($data['shipment_sync_history']);
                        if (!empty($lastSyncedShipmentDetails['response_data']['message']) && $this->shouldReattemptShipment($lastSyncedShipmentDetails['response_data']['message'])) {
                            if (
                                !empty($lastSyncedShipmentDetails['response_data']['error']['message']['error']) &&
                                $lastSyncedShipmentDetails['response_data']['error']['message']['error'] == 'invalid_grant'
                            ) {
                                $shipmentErrors[$data['marketplace_reference_id']][$data['shipment_id']] = 'Token Expired';
                                continue;
                            }
                            $this->setShippingStatus($prepareData);
                            $response = $shipmentService->ship($data);
                            if (isset($response['success']) && $response['success']) {
                                $shipmentSuccess[$data['marketplace_reference_id']][$data['shipment_id']] = $response['message'] ?? 'Shipment Synced Successfully';
                            } else {
                                $shipmentErrors[$data['marketplace_reference_id']][$data['shipment_id']] = $response['message'] ?? 'Something Went Wrong';
                            }
                        } else {
                            $knownShipmentError = $lastSyncedShipmentDetails['response_data']['message'] ?? 'Blank shipment error message';
                            $shipmentErrors[$data['marketplace_reference_id']][$data['shipment_id']] = "By passing known shipment error: $knownShipmentError";
                        }
                    } else {
                        $this->setShippingStatus($prepareData);
                        $response = $shipmentService->ship($data);
                        if (isset($response['success']) && $response['success']) {
                            $shipmentSuccess[$data['marketplace_reference_id']][$data['shipment_id']] = $response['message'] ?? 'Shipment Synced Successfully';
                        } else {
                            $shipmentErrors[$data['marketplace_reference_id']][$data['shipment_id']] = $response['message'] ?? 'Something Went Wrong';
                        }
                    }
                }
                $responseData = ['success' => true, 'message' => 'Shipment synchronization was retried and succeeded'];
                if (!empty($shipmentErrors)) {
                    if(!empty($knownShipmentError)) {
                        $message = "Shipment sync cannot be retried. This error cannot be resolved from the app. Contact support for further assistance.";
                    } else {
                        $message = empty($shipmentSuccess)
                        ? 'Shipment sync failed. See the "Errors" tab in the Orders Grid for details.'
                        : 'Shipment sync was only partially successful. Review the "Errors" tab in the Orders Grid to see which shipments failed.';
                    }
                    $responseData = [
                        'success' => false,
                        'message' => $message,
                        'data' => $shipmentErrors,
                    ];
                }
                $this->di->getLog()->logContent('Shipment sync response data: ' . json_encode([
                    'user_id' => $this->di->getUser()->id ?? $data['user_id'],
                    'order_id' => $data['order_id'],
                    'shipmentSuccess' => $shipmentSuccess,
                    'shipmentErrors' => $shipmentErrors
                ]), 'info', $logFile);
                return $responseData;
            } else {
                $message = 'Unable to fetch shipment docs';
                $this->di->getLog()->logContent($message, 'info', $logFile);
            }
        } else {
            $message = 'Required params missing';
            $this->di->getLog()->logContent($message, 'info', $logFile);
        }
        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    private function shouldReattemptShipment($errorMessage)
    {
        $knownShipmentErrors = [
            'ErrorCode: CarrierNameCorrection Description',
            'ErrorCode: BlockedTrackingId Description',
            'ErrorCode: DuplicateTrackingId Description',
            'ErrorCode: NonexistentOrderItem Description',
            'ErrorCode: InvalidCarrier Description: Selected carrier is not supported',
            'Failed to create package due to not finding a matching package or order already fulfilled',
            'Failed to create/update package due to overfulfillment',
            'Failed validation because order',
            'Access to requested resource is denied',
            'Unable to sync tracking details because order is either already fulfilled',
            'Unable to sync tracking details because some items in the order are invalid',
            'Tracking ID: Amazon invalid - Enter a valid tracking ID',
            'Account is in vacation mode or status is inactive.Kindly resolve the problem to ship the order.'
        ];
        foreach ($knownShipmentErrors as $shipmentError) {
            if (str_contains($errorMessage, $shipmentError)) {
                return false;
            }
        }
        return true;
    }

    /**
     * setDiForUser function
     * Setting di for user by userId
     * @param [array] $userId
     * @return array
     */
    private function setDiForUser($userId)
    {
        try {
            $getUser = \App\Core\Models\User::findFirst([['_id' => $userId]]);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
        if (empty($getUser)) {
            return [
                'success' => false,
                'message' => 'User not found.'
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
