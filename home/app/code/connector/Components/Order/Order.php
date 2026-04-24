<?php
namespace App\Connector\Components\Order;

use App\Connector\Contracts\Sales\OrderInterface;

class Order extends \App\Core\Components\Base
{
    private string $logOrderFile = 'order/order-create/orders.log';

    private string $logFile = 'order/order-create/order-process.log';

    const LOG_PRIORITY_LEVEL_CRITICAL = 'critical';

    const LOG_PRIORITY_LEVEL_IMPORTANT = 'important';

    const LOG_PRIORITY_LEVEL_STANDARD = 'standard';

    /**
     * @format array {"shop_id":"","marketplace":"","type":"source/target","order":""}
     */
    public function create(array $orderData): array
    {
        $this->logFile = 'order/order-create/' . $this->di->getUser()->id . '/' . date('Y-m-d') . '/order-process.log';
        $this->addLog("", $this->logFile, "--------------------- Order Create Process Start ------------------------", self::LOG_PRIORITY_LEVEL_STANDARD);
        $type = "sources";
        if (isset($orderData['type'])) {
            $type = ($orderData['type'] == 'target' ? 'sources' : "targets");
        }

        $this->addLog("", $this->logFile, " >>> Step 1: Create source order <<< ", self::LOG_PRIORITY_LEVEL_STANDARD);
        if (isset($orderData['marketplace'], $orderData['shop_id'])) {

            $this->addLog("", $this->logFile, "Source marketplace: " . $orderData['marketplace'] . "  Shop id: " . $orderData['shop_id'], self::LOG_PRIORITY_LEVEL_IMPORTANT);
            $orderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class, [], $orderData['marketplace']);
            $this->addLog("", $this->logFile, "P1      : Prepare Source Order Data", self::LOG_PRIORITY_LEVEL_STANDARD);
            $preparedData = $orderService->prepareForDb($orderData);
            if ($preparedData['success']) {
                $this->addLog("", $this->logFile, "prepareForDb response: success", self::LOG_PRIORITY_LEVEL_STANDARD);
                $connectorOrderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class);
                $params = [];
                $params['user_id'] = $this->di->getUser()->id;
                $params['object_type'] = 'source_order';
                $params['marketplace'] = $orderData['marketplace'];
                $params['marketplace_shop_id'] = $orderData['shop_id'];
                $params['marketplace_reference_id'] = $preparedData['data']['marketplace_reference_id'];

                $this->addLog($preparedData['data']['marketplace_reference_id'], $this->logFile, "Order ID received: ", self::LOG_PRIORITY_LEVEL_CRITICAL);
                $this->logOrderFile = 'order/order-create/' . $this->di->getUser()->id . '/' . date('Y-m-d') . '/' . $preparedData['data']['marketplace_reference_id'] . '.log';
                $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
                $collection = $mongo->getCollection('order_container');
                $savedOrder = $collection->findOne($params, $options);

                $this->addLog("", $this->logFile, "P2      : Check order duplicacy", self::LOG_PRIORITY_LEVEL_STANDARD);
                if (!empty($savedOrder)) {
                    if ($savedOrder['user_id'] == $this->di->getUser()->id) {
                        $retryCheck = $this->isRetryAllowed($savedOrder);
                        if ($retryCheck['allowed']) {
                            $this->addLog(
                                $retryCheck['reason'],
                                $this->logFile,
                                "Retry allowed, re-attempting order creation",
                                self::LOG_PRIORITY_LEVEL_IMPORTANT
                            );
                            $retryResponse = $this->createSavedOrder(['savedData' => $savedOrder]);
                            $this->addLog(
                                json_encode($retryResponse),
                                $this->logFile,
                                "Retry response => ",
                                self::LOG_PRIORITY_LEVEL_STANDARD
                            );
                            return $retryResponse;
                        }
                    }

                    $this->addLog("", $this->logFile, "Order duplicacy found. Can\'t proceed", self::LOG_PRIORITY_LEVEL_CRITICAL);
                    $this->addLog("", $this->logFile, "--------------------- Order Create Process End ------------------------", self::LOG_PRIORITY_LEVEL_STANDARD);
                    if ($savedOrder['user_id'] != $this->di->getUser()->id) {
                        return ['success' => false, 'message' => 'Wrong user set for order create'];
                    }

                    return ['success' => false, 'message' => 'Dublicate Order found'];
                }

                $this->addLog("", $this->logFile, "Order duplicacy: not found. Processing....", self::LOG_PRIORITY_LEVEL_STANDARD);
                $connectorOrderService->setSchemaObject('source_order');
                $preparedData['data']['status'] = 'Pending';
                $preparedData['data']['schema_version'] = $connectorOrderService::SCHEMA_VERSION;
                 /**
                 * if enabled from config then encrypt the mentioned keys in config
                 */
                if ($this->di->getConfig()->get('pii_data_encryption_manager_status')) {
                    $piiManager = $this->di->getObjectManager()->get(\App\Connector\Components\PIIDataManager::class);
                    $piiManager->encryptDecrypt($preparedData['data']);
                }
                $this->addLog("", $this->logFile, "P3      : Order create and save in db", self::LOG_PRIORITY_LEVEL_STANDARD);
                $connectorResponse = $connectorOrderService->create($preparedData['data']);
                if ($connectorResponse['success']) {
                    $this->addLog("", $this->logFile, "Save successful!!", self::LOG_PRIORITY_LEVEL_IMPORTANT);
                    $this->addLog("", $this->logFile, "Check history in " . $this->logOrderFile, self::LOG_PRIORITY_LEVEL_IMPORTANT);
                    $this->addLog("", $this->logFile, " -------------------------------------------- ", self::LOG_PRIORITY_LEVEL_STANDARD);
                    return $this->manageOrder($connectorResponse, $orderData['shop_id']);
                }

                $this->addLog(json_encode($connectorResponse), $this->logFile, "Save fail!! Response => ", self::LOG_PRIORITY_LEVEL_CRITICAL);
                $this->addLog("", $this->logFile, "--------------------- Order Create Process End ------------------------", self::LOG_PRIORITY_LEVEL_STANDARD);
                return $connectorResponse;
            }

            $this->addLog(json_encode($preparedData), $this->logFile, "prepareForDb response: fail! Response => ", self::LOG_PRIORITY_LEVEL_CRITICAL);
            $this->addLog("", $this->logFile, "--------------------- Order Create Process End ------------------------", self::LOG_PRIORITY_LEVEL_STANDARD);
            return $preparedData;
        }

        $this->addLog("", $this->logFile, "marketplace/shop_id not found in order create data", self::LOG_PRIORITY_LEVEL_CRITICAL);
        $this->addLog("", $this->logFile, "--------------------- Order Create Process End ------------------------", self::LOG_PRIORITY_LEVEL_STANDARD);
        return ['success' => false, 'message' => 'Marketplace/ShopId missing'];
    }


    public function manageOrder($connectorResponse, $orderSourceShopId, $type = "sources")
    {
        if (!empty($this->di->getUser()->id) && !empty($connectorResponse['data']['marketplace_reference_id'])) {
            $this->logOrderFile = 'order/order-create/' . $this->di->getUser()->id . '/' . date('Y-m-d') . '/' . $connectorResponse['data']['marketplace_reference_id'] . '.log';
        } else {
            $this->logOrderFile = 'order/order-create/' . date('Y-m-d') . '/manual-order/order-process.log';
        }

        $orderRetryHandler = null;
        $useRetryScheduler = $connectorResponse['data']['use_failed_order_scheduler'] ?? $this->di->getConfig()->use_failed_order_scheduler ?? false;
        if ($useRetryScheduler) {
            $orderRetryHandler = $this->di->getObjectManager()->get(\App\Connector\Components\Order\Retry\Handler::class);
        }

        $this->addLog("", $this->logOrderFile, " >>>>>>>>>>>>>>>> Managing orders and create on target <<<<<<<<<<<<<<<<<<< ", self::LOG_PRIORITY_LEVEL_STANDARD);
        $this->addLog(PHP_EOL . "In manageOrder function", $this->logOrderFile, " >> Step 2: Processing targets and get data to create <<< ", self::LOG_PRIORITY_LEVEL_STANDARD);
        $connectorOrderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class);
        $cifOrderId = $connectorResponse['data']['cif_order_id'];

        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shop = $userDetails->getShop($orderSourceShopId, $this->di->getUser()->id);
        $allSources = $shop[$type];
        $successSources = [];
        $failedSources = [];
        $time = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
        $start_time_source = microtime(true);
        $this->addLog(" Microtime start: " . $start_time_source, $this->logOrderFile, "Beginning loop for targets. Start time: " . $time, self::LOG_PRIORITY_LEVEL_STANDARD);
        foreach ($allSources as $source) {
            $this->addLog("", $this->logOrderFile, " ______ loop start _______ ", self::LOG_PRIORITY_LEVEL_STANDARD);
            if (isset($connectorResponse['data']['manual_source_shop_id'])) {
                if ($source['shop_id'] != $connectorResponse['data']['manual_source_shop_id']) {
                    $this->addLog(true, $this->logOrderFile, "manual_source_shop_id set and equal to shop_id? ", self::LOG_PRIORITY_LEVEL_CRITICAL);
                    continue;
                }
            }

            if (isset($source['disconnected']) && $source['disconnected']) {
                $disabledOrderData = [
                    'shop_id' => $source['shop_id'],
                    'marketplace' => $source['marketplace'] ?? 'Not available',
                    'message' => 'shop is disconnected',
                    'cif_order_id' => $connectorResponse['data']['cif_order_id']
                ];
                $failedSources['disabled'][$source['shop_id']] = $disabledOrderData;
                $this->addLog(true, $this->logOrderFile, "target disconnected for shop " . $source['shop_id'], self::LOG_PRIORITY_LEVEL_CRITICAL);
                continue;
            }

            $this->addLog("", $this->logOrderFile, "A =>   get target marketplace", self::LOG_PRIORITY_LEVEL_STANDARD);
            if (isset($source['marketplace'])) {
                $source['code'] = $source['marketplace'];
                $this->addLog($source['code'], $this->logOrderFile, "target marketplace: ", self::LOG_PRIORITY_LEVEL_IMPORTANT);
            }

            $this->addLog("", $this->logOrderFile, "B =>    checkOrderSyncEnable", self::LOG_PRIORITY_LEVEL_STANDARD);
            $this->addLog("Source shop id: " . $source['shop_id'] . " Target shop id: " . $orderSourceShopId, $this->logOrderFile, "Getting settings for shop ids: ", self::LOG_PRIORITY_LEVEL_IMPORTANT);
            $checkOrderSync = $this->checkOrderSyncEnable($source['shop_id'], $orderSourceShopId);

            if ($checkOrderSync['success']) {
                $this->addLog("", $this->logOrderFile, "checkOrderSyncEnable: success", self::LOG_PRIORITY_LEVEL_IMPORTANT);
                $sourceSetting = $checkOrderSync['data'];

                if (isset($connectorResponse['data']['is_ispu']) && $connectorResponse['data']['is_ispu']) {
                    if (!isset($sourceSetting['ispu_order_sync']) || !$sourceSetting['ispu_order_sync']['value']) {
                        $failedSources['disabled'][$source['shop_id']] = [
                            'shop_id' => $source['shop_id'],
                            'marketplace' => $source['code'],
                            'message' => 'ispu_order_sync not enabled',
                            'cif_order_id' => $connectorResponse['data']['cif_order_id']
                        ];
                        $this->addLog("", $this->logOrderFile, "is_ispu order", self::LOG_PRIORITY_LEVEL_IMPORTANT);
                        continue;
                    }
                }

                if (
                    isset($connectorResponse['data']['fulfilled_by'])
                    && $connectorResponse['data']['fulfilled_by'] == 'other'
                ) {
                    if (
                        !isset($sourceSetting['fbo_order_sync'])
                        || !$sourceSetting['fbo_order_sync']['value']
                    ) {
                        $failedSources['disabled'][$source['shop_id']] = [
                            'shop_id' => $source['shop_id'],
                            'marketplace' => $source['code'],
                            'message' => 'fbo_order_sync not enabled',
                            'cif_order_id' => $connectorResponse['data']['cif_order_id']
                        ];
                        $this->addLog("", $this->logOrderFile, "fbo_order", self::LOG_PRIORITY_LEVEL_IMPORTANT);
                        continue;
                    }

                    $this->setFboOrderCustomerName($connectorResponse, $sourceSetting);
                }

                $sourceData = [];
                $sourcerOrderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class, [], $source['code']);
                $sourceData['shop_id'] = $source['shop_id'];
                $sourceData['marketplace'] = $source['code'];
                isset($source['app_code']) && $sourceData['app_code'] = $source['app_code'];
                $sourceData['target_shop_id'] = $orderSourceShopId;
                $sourceData['order'] = $connectorResponse['data'];
                $sourceData['order_settings'] = $sourceSetting;
                $this->addLog("", $this->logOrderFile, "C =>    validateForCreate", self::LOG_PRIORITY_LEVEL_STANDARD);
                $sourceResponse = $sourcerOrderService->validateForCreate($sourceData);

                if ($sourceResponse['success']) {
                    $this->addLog("", $this->logOrderFile, "validateForCreate: success", self::LOG_PRIORITY_LEVEL_IMPORTANT);
                    if (isset($sourceResponse['failed'])) {
                        $this->addLog("", $this->logOrderFile, "validateForCreate: contains failed items", self::LOG_PRIORITY_LEVEL_CRITICAL);
                        $failedPrepare = [];
                        $failedPrepare['shop_id'] = $source['shop_id'];
                        $failedPrepare['marketplace'] = $source['code'];
                        $failedPrepare['order_id'] = $connectorResponse['data']['marketplace_reference_id'];
                        $failedPrepare['failed'] = $sourceResponse['failed'];
                        $failedSources['save'][$source['shop_id']] = $failedPrepare;

                        $sourceData['order']['items'] = $sourceResponse['data'];
                        $this->addLog("", $this->logOrderFile, "validateForCreate: adding success items for creation and adding failedSources", self::LOG_PRIORITY_LEVEL_IMPORTANT);
                        if (!empty($sourceData['shop_id']))
                            $successSources[$sourceData['shop_id']] = $sourceData;

                    } else {
                        $this->addLog("", $this->logOrderFile, "validateForCreate: contains all success items", self::LOG_PRIORITY_LEVEL_IMPORTANT);
                        $sourceData['order']['items'] = $sourceResponse['data'];
                        if (!empty($sourceData['shop_id']))
                            $successSources[$sourceData['shop_id']] = $sourceData;
                    }
                } else {
                    $this->addLog("", $this->logOrderFile, "validateForCreate: fail", self::LOG_PRIORITY_LEVEL_CRITICAL);
                    $failedPrepare = [];
                    $failedPrepare['shop_id'] = $source['shop_id'];
                    $failedPrepare['marketplace'] = $source['code'];
                    $failedPrepare['order_id'] = $connectorResponse['data']['marketplace_reference_id'] ?? '';
                    $failedPrepare['failed'] = $sourceResponse['message'] ?? "";
                    if (empty($sourceResponse['disabled'])) {
                        $failedSources['save'][$source['shop_id']] = $failedPrepare;
                        $this->addLog("", $this->logOrderFile, "validateForCreate: adding failure data", self::LOG_PRIORITY_LEVEL_CRITICAL);
                    } else {
                        $failedSources['disabled'][$source['shop_id']] = [
                            'shop_id' => $source['shop_id'],
                            'marketplace' => $source['code'],
                            'message' => array_column($sourceResponse['disabled'], 'reason'),
                            'cif_order_id' => $connectorResponse['data']['cif_order_id']
                        ];
                        $disabledOrderMsg = array_column($sourceResponse['disabled'], 'reason')[0];
                        $this->addLog("", $this->logOrderFile, "validateForCreate: adding disabled data", self::LOG_PRIORITY_LEVEL_CRITICAL);
                    }
                }
            } else {
                $this->addLog("", $this->logOrderFile, "checkOrderSyncEnable: fail", self::LOG_PRIORITY_LEVEL_CRITICAL);
                $failedPrepare = [];
                $failedPrepare['shop_id'] = $source['shop_id'];
                $failedPrepare['marketplace'] = $source['code'];
                $failedPrepare['error'] = $checkOrderSync['message'];
                $failedPrepare['message'] = $checkOrderSync['message'];
                $failedSources['not_save'][$source['shop_id']] = $failedPrepare;
                $disabledOrderData = $failedPrepare;
                $disabledOrderData['cif_order_id'] = $connectorResponse['data']['cif_order_id'];
                $failedSources['disabled'][$source['shop_id']] = $disabledOrderData;
            }

            $this->addLog("", $this->logOrderFile, " ______ loop end _______ ", self::LOG_PRIORITY_LEVEL_STANDARD);
        }

        $hasDuplicateUniqueKey = false;
        $sourceOrderItemMappings = $this->mapItemsWithUniqueKey($successSources, $hasDuplicateUniqueKey);

        $time = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
        $end_time_source = microtime(true);
        $this->addLog(" Microtime end: " . $end_time_source . PHP_EOL . "Difference in source loop: " . ($end_time_source - $start_time_source) . " | Time in seconds: " . date("H:i:s", $end_time_source - $start_time_source), $this->logOrderFile, "Ending loop for targets. End time: " . $time, self::LOG_PRIORITY_LEVEL_STANDARD);
        $this->addLog("", $this->logOrderFile, " >> Step 3: Create order on target <<< ", self::LOG_PRIORITY_LEVEL_IMPORTANT);
        if (!empty($successSources)) {
            $this->addLog("", $this->logOrderFile, "P1     :    canCreateOrder ", self::LOG_PRIORITY_LEVEL_STANDARD);
            $canCreate = $this->canCreateOrder($successSources, $connectorResponse);
            $connectorOrderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class);

            if ($canCreate['success'] && empty($failedSources)) {
                $this->addLog("", $this->logOrderFile, "canCreateOrder: success", self::LOG_PRIORITY_LEVEL_IMPORTANT);
                $allSuccessSourceOrder = [];
                $allSourceCreateError = [];
                $allOrderAcknowledgements = [];
                // $this->addLog("", $this->logOrderFile, "canCreateOrder: success");
                $time = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
                $start_time_create = microtime(true);
                $this->addLog(" Microtime start: " . $start_time_create, $this->logOrderFile, "Loop for canCreateOrder. Start time: " . $time, self::LOG_PRIORITY_LEVEL_STANDARD);
                foreach ($canCreate['data'] as $sourcerShopId => $sourceOrderData) {
                    $sourcerShopId = (string) $sourcerShopId;
                    $sourcerOrderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class, [], $sourceOrderData['marketplace']);
                    $this->addLog("", $this->logOrderFile, "P2      :   checkTaxEnable --> settings check", self::LOG_PRIORITY_LEVEL_STANDARD);
                    $sourceOrderData = $this->checkTaxEnable($sourceOrderData);
                    $this->addLog("", $this->logOrderFile, "checkTaxEnable: check done", self::LOG_PRIORITY_LEVEL_STANDARD);
                    $this->addLog("", $this->logOrderFile, "Processing to create order on target --->", self::LOG_PRIORITY_LEVEL_IMPORTANT);
                    $this->addLog(json_encode($sourceOrderData), $this->logOrderFile, "Data send to create order on target =>", self::LOG_PRIORITY_LEVEL_CRITICAL);
                    if ($hasDuplicateUniqueKey) {
                        $sourceOrderData['has_duplicate_unique_key'] = true;
                    }
                    $sourceOrderCreate = $sourcerOrderService->create($sourceOrderData);
                    if ($sourceOrderCreate['success']) {
                        $this->addLog("", $this->logOrderFile, "Order created successfully!!", self::LOG_PRIORITY_LEVEL_CRITICAL);
                        $sourceCreatedOrder = [];
                        $sourceCreatedOrder['shop_id'] = $sourcerShopId;
                        $sourceCreatedOrder['marketplace'] = $sourceOrderData['marketplace'];
                        $sourceCreatedOrder['order'] = $sourceOrderCreate['data'];
                        $sourceCreatedOrder['user_id'] = $this->di->getUser()->id;
                        $sourceCreatedOrder['status'] = $connectorResponse['data']['marketplace_status'];
                        $this->addLog("", $this->logOrderFile, " >> Step 4: Save target order <<< ", self::LOG_PRIORITY_LEVEL_STANDARD);
                        $sourceOrderCreate = $sourcerOrderService->prepareForDb($sourceCreatedOrder);
                        if ($sourceOrderCreate['success']) {
                            $this->addLog("", $this->logOrderFile, "A      : prepareForDb => SUCCESS ", self::LOG_PRIORITY_LEVEL_STANDARD);
                            $this->addLog($sourceOrderCreate['data']['marketplace_reference_id'], $this->logOrderFile, "Target order ID: ", self::LOG_PRIORITY_LEVEL_CRITICAL);
                            $connectorOrderService->setSchemaObject('target_order');
                            $sourceOrderCreate['data']['cif_order_id'] = $connectorResponse['data']['cif_order_id'];
                            $sourceOrderCreate['data']['status'] = 'Created';
                            $sourceOrderCreate['data']['schema_version'] = $connectorOrderService::SCHEMA_VERSION;
                            $sourceOrderCreate['data']['targets'] = [
                                [
                                    'shop_id' => $connectorResponse['data']['shop_id'],
                                    'marketplace' => $connectorResponse['data']['marketplace'],
                                    'order_id' => $connectorResponse['data']['marketplace_reference_id'],
                                    'status' => $connectorResponse['data']['marketplace_status']
                                ]
                            ];

                            $sourceOrderCreate['data'] = $this->applyUniqueKeyMappings($sourceOrderCreate['data'], $sourceOrderItemMappings, $hasDuplicateUniqueKey);
                            /**
                             * if enabled from config then encrypt the mentioned keys in config
                             */
                            if ($this->di->getConfig()->get('pii_data_encryption_manager_status')) {
                                $piiManager = $this->di->getObjectManager()->get(\App\Connector\Components\PIIDataManager::class);
                                $piiManager->encryptDecrypt($sourceOrderCreate['data']);
                            }
                            $connectorTargetResponse = $connectorOrderService->create($sourceOrderCreate['data']);
                            if ($connectorTargetResponse['success']) {
                                $this->addLog("", $this->logOrderFile, "B      : Save in DB => SUCCESS ", self::LOG_PRIORITY_LEVEL_STANDARD);
                                $sucessOrderUpdate = [];
                                $successOrderUpdate['shop_id'] = $connectorTargetResponse['data']['shop_id'];
                                $successOrderUpdate['marketplace'] = $connectorTargetResponse['data']['marketplace'];
                                $successOrderUpdate['order_id'] = (string) $connectorTargetResponse['data']['marketplace_reference_id'];
                                $this->updateSuccessOrder($cifOrderId, $successOrderUpdate);
                                $allSuccessSourceOrder[] = $successOrderUpdate;

                                $connectorOrderService->fireChangeEvent(
                                        $connectorOrderService::AFTER_TARGET_ORDER_CREATED_EVENT,
                                    [
                                        'shop_id' => $connectorResponse['data']['marketplace_shop_id'],
                                        'marketplace_reference_id' => $connectorResponse['data']['marketplace_reference_id'],
                                        'source_marketplace' => $connectorResponse['data']['marketplace'],
                                        'source_order' => $connectorResponse['data'],
                                        'target_order' => $sourceOrderCreate['data']
                                    ]
                                );
                                $preparedOrderAcknowledgeData = $this->prepareOrderAcknowledgeData(
                                    $connectorTargetResponse['data'],
                                    $connectorResponse['data'],
                                    $sourceOrderData['order_settings'] ?? []
                                );
                                if (!empty($preparedOrderAcknowledgeData)) {
                                    $allOrderAcknowledgements[] = $preparedOrderAcknowledgeData;
                                }
                            } else {
                                $this->addLog("", $this->logOrderFile, "B      : Save in DB => FAIL ", self::LOG_PRIORITY_LEVEL_CRITICAL);
                                // todo need to handle
                            }
                        } else {
                            $this->addLog("", $this->logOrderFile, "A      : prepareForDb => FAIL ", self::LOG_PRIORITY_LEVEL_CRITICAL);
                            //todo need to handle
                        }
                    } else {
                        $this->addLog("", $this->logOrderFile, "Order creation failure!!", self::LOG_PRIORITY_LEVEL_CRITICAL);
                        $mongo = $this->di->getObjectManager()->create(\App\Core\Models\BaseMongo::class);
                        $collection = $mongo->getCollectionForTable('order_container');
                        $checkTargetsObj = $collection->findOne(['user_id' => $this->di->getUser()->id, 'object_type' => 'source_order', 'cif_order_id' => $cifOrderId, 'targets.shop_id' => (string) $sourceOrderData['shop_id']], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
                        $orderStatus = $this->di->getObjectManager()->get('\App\Connector\Components\Order\OrderStatus');
                        if ($checkTargetsObj) {

                            //  var_dump($checkTargetsObj);die;
                            $collection->updateOne(['user_id' => $this->di->getUser()->id, 'object_type' => 'source_order', 'cif_order_id' => $cifOrderId, 'targets.shop_id' => (string) $sourceOrderData['shop_id']], ['$set' => ['targets.$[a].errors' => [$sourceOrderCreate['message']], 'targets.$[a].status' => $orderStatus::failed]], ['arrayFilters' => [['a.shop_id' => (string) $sourceOrderData['shop_id']]]]);
                            //  die;
                        } else {
                            $addTargets = [];
                            $addTargets['shop_id'] = (string) $sourceOrderData['shop_id'];
                            $addTargets['marketplace'] = $sourceOrderData['marketplace'];
                            $addTargets['errors'] = [$sourceOrderCreate['message']];
                            $addTargets['status'] = $orderStatus::failed;
                            $collection->updateOne(['user_id' => $this->di->getUser()->id, 'object_type' => 'source_order', 'cif_order_id' => $cifOrderId], ['$addToSet' => ['targets' => $addTargets]]);
                        }

                        $allSourceCreateError[] = [
                            "marketplace" => $sourceOrderData['marketplace'],
                            "shop_id" => $sourceOrderData['shop_id'],
                            "errors" => [$sourceOrderCreate['message']]
                        ];
                        if (isset($sourceOrderCreate['message']) && !empty($sourceOrderCreate['message']) && empty($sourceOrderCreate['skip_failed_order_email'])) {
                            $this->sendFailedEmail($cifOrderId, [$sourceOrderCreate['message']], $sourcerShopId);
                        }
                        if ($useRetryScheduler && isset($orderRetryHandler)) {
                            $this->scheduleRetry($connectorResponse, $orderRetryHandler);
                        }
                    }

                    $this->addLog("", $this->logOrderFile, "Processing to create order on target end <---", self::LOG_PRIORITY_LEVEL_STANDARD);
                }

                $time = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
                $end_time_create = microtime(true);
                $this->addLog(" Microtime start: " . $end_time_create . PHP_EOL . "Difference in microtime for create: " . ($end_time_create - $start_time_create) . " | Time in seconds: " . date("H:i:s", $end_time_create - $start_time_create), $this->logOrderFile, "Loop for canCreateOrder. End time: " . $time, self::LOG_PRIORITY_LEVEL_STANDARD);
                if (!empty($allSuccessSourceOrder)) {
                    if (
                        isset($sourceSetting['acknowledge_order_on_source']['value'])
                        && $sourceSetting['acknowledge_order_on_source']['value']
                    ) {
                        $this->acknowledgeOnSource(
                            $allOrderAcknowledgements,
                            $connectorResponse['data']['marketplace']
                        );
                    }

                    $this->addLog("", $this->logOrderFile, "Order Processed successfully!!!", self::LOG_PRIORITY_LEVEL_CRITICAL);
                    $this->addLog("", $this->logOrderFile, "--------------------- Order Create Process End ------------------------", self::LOG_PRIORITY_LEVEL_STANDARD);
                    return ['success' => true, 'data' => $allSuccessSourceOrder];
                }

                if (!empty($allSourceCreateError)) {
                    $this->addLog(json_encode($allSourceCreateError), $this->logOrderFile, "Order Processed with some errors! Errors are: ", self::LOG_PRIORITY_LEVEL_CRITICAL);
                    $this->addLog("", $this->logOrderFile, "--------------------- Order Create Process End ------------------------", self::LOG_PRIORITY_LEVEL_STANDARD);
                    return ['success' => false, 'data' => $allSourceCreateError];
                }

            } else {
                $this->addLog("", $this->logOrderFile, "canCreateOrder failed!!", self::LOG_PRIORITY_LEVEL_CRITICAL);
                $failedSources['not_save']['cif'] = $canCreate['message'];
            }
        }

        if (!empty($failedSources)) {
            $this->addLog("", $this->logOrderFile, "failedSources found", self::LOG_PRIORITY_LEVEL_CRITICAL);
            if (isset($failedSources['save'])) {
                $this->updateFailedOrder($cifOrderId, $failedSources['save']);
                $this->addLog(json_encode($failedSources), $this->logOrderFile, "Order item failed to create. FailedSources: ", self::LOG_PRIORITY_LEVEL_CRITICAL);

                if ($useRetryScheduler && isset($orderRetryHandler)) {
                    $this->scheduleRetry($connectorResponse, $orderRetryHandler);
                }

                $this->addLog("", $this->logOrderFile, "--------------------- Order Create Process End ------------------------", self::LOG_PRIORITY_LEVEL_STANDARD);
                return ['success' => false, 'message' => $failedSources];
            }

            if (isset($failedSources['disabled'])) {
                $this->updateDisabledOrder($failedSources['disabled']);
                $this->addLog("", $this->logOrderFile, "order sync settings disabled or user uninstalled", self::LOG_PRIORITY_LEVEL_CRITICAL);
                $this->addLog("", $this->logOrderFile, "--------------------- Order Create Process End ------------------------", self::LOG_PRIORITY_LEVEL_STANDARD);
                return [
                    'success' => false,
                    'data' => $failedSources,
                    'message' => $disabledOrderMsg ?? 'order sync settings disabled or user uninstalled'
                ];
            }
            $this->addLog(json_encode($failedSources), $this->logOrderFile, "Order item failed to create. FailedSources: ", self::LOG_PRIORITY_LEVEL_CRITICAL);
            return ['success' => false, 'message' => 'Order item failed to create', 'data' => $failedSources];
        }

        $this->addLog(json_encode('No success and failed sources found for this order'), $this->logOrderFile, "No success and failed sources found", self::LOG_PRIORITY_LEVEL_CRITICAL);
        $this->addLog("", $this->logOrderFile, "--------------------- Order Create Process End ------------------------", self::LOG_PRIORITY_LEVEL_STANDARD);
        return ['success' => false, 'message' => 'No success and failed sources found'];
    }

    public function mapItemsWithUniqueKey($sourceOrder, &$hasDuplicateUniqueKey = false)
    {
        $allOrderItems = array_column(array_column($sourceOrder, 'order'), 'items');
        $itemMappings = [];
        foreach ($allOrderItems as $orderWiseItems) {
            foreach ($orderWiseItems as $item) {
                $uniqueKey = $this->getUniqueKey($item);
                if (!is_null($uniqueKey) && isset($item['item_link_id'])) {
                    if (!empty($itemMappings[$uniqueKey])) {
                        $hasDuplicateUniqueKey = true;
                    }
                    $itemMappings[$uniqueKey][] = $item['item_link_id'];
                }
            }
        }

        foreach ($itemMappings as $uniqueKey => $linkIds) {
            $itemMappings[$uniqueKey] = array_reverse($linkIds);
        }

        return $itemMappings;
    }


    public function applyUniqueKeyMappings($targetOrder, $itemMappings, $hasDuplicateKeys = false)
    {
        $duplicateKeyConfig = $this->di->getConfig()->get('include_link_id_in_attributes_if_duplicate_key') ?? ['enabled' => true];
        $customAttributeName = $duplicateKeyConfig['attribute_name'] ?? 'ced_item_id';
        $isDuplicateKeyFeatureEnabled = $duplicateKeyConfig['enabled'] ?? true;

        foreach ($targetOrder['items'] as $itemIndex => $item) {
            $uniqueKey = $this->getUniqueKey($item);

            // Skip items without a valid unique key
            if (is_null($uniqueKey)) {
                continue;
            }

            $hasMappingsForKey = !empty($itemMappings[$uniqueKey]);

            if ($hasMappingsForKey) {
                $shouldUseMappingInsteadOfAttribute = $this->shouldUseMappingInsteadOfAttribute(
                    $hasDuplicateKeys,
                    $isDuplicateKeyFeatureEnabled,
                    $item,
                    $customAttributeName
                );

                if ($shouldUseMappingInsteadOfAttribute) {
                    $targetOrder['items'][$itemIndex]['item_link_id'] = array_pop($itemMappings[$uniqueKey]);
                }
            }

            if ($hasDuplicateKeys && !empty($item[$customAttributeName])) {
                $targetOrder['items'][$itemIndex]['item_link_id'] = $item[$customAttributeName];
                unset($targetOrder['items'][$itemIndex][$customAttributeName]);
            }
        }

        return $targetOrder;
    }

    private function shouldUseMappingInsteadOfAttribute($hasDuplicateKeys, $isDuplicateKeyFeatureEnabled, $item, $customAttributeName)
    {
        if (!$hasDuplicateKeys) {
            return true;
        }

        // If duplicate key feature is disabled, use mapping
        if (!$isDuplicateKeyFeatureEnabled) {
            return true;
        }

        // If custom attribute is empty, use mapping
        if (empty($item[$customAttributeName])) {
            return true;
        }

        return false;
    }

    public function getUniqueKey($item)
    {
        return $item['product_identifier'] ?? $item['sku'] ?? $item['title'] ?? null;

    }

    public function prepareOrderAcknowledgeData($targetOrder, $sourceOrder, $orderSettings = [])
    {
        if (
            !isset($orderSettings['acknowledge_order_on_source']['value'])
            || !$orderSettings['acknowledge_order_on_source']['value']
        ) {
            return [];
        }

        return [
            'source_data' => $sourceOrder,
            'target_data' => $targetOrder,
            'order_settings' => $orderSettings
        ];
    }

    public function acknowledgeOnSource($allOrderAcknowledgements, $sourceMarketplace): void
    {
        if (empty($allOrderAcknowledgements)) {
            return;
        }

        if (count($allOrderAcknowledgements) > 1) {
            //discuss what we have to do
            //like not sending anything
            //or comma separated order_ids
            //also handle this on target
        }

        $orderService = $this->di
            ->getObjectManager()
            ->get(\App\Connector\Contracts\Sales\OrderInterface::class, [], $sourceMarketplace);
        if (method_exists($orderService, 'acknowledgeOrder')) {
            $orderService->acknowledgeOrder($allOrderAcknowledgements);
        }
    }

    public function checkTaxEnable(array $sourceData): array
    {
        $connectorOrderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class);

        if (isset($sourceData['order_settings'])) {
            $settings = $sourceData['order_settings'];
            if (isset($sourceData['order']['taxes'])) {


                foreach ($sourceData['order']['taxes'] as $key => $tax) {
                    if ($tax['code'] == 'ItemTax') {
                        if (isset($settings['order_item_tax']) && !$settings['order_item_tax']['value']) {
                            $this->addLog("", $this->logOrderFile, "checkTaxEnable: order_item_tax settings disabled, removing item tax", self::LOG_PRIORITY_LEVEL_STANDARD);
                            $itemTax = $tax['marketplace_price'];
                            $sourceData['order']['tax']['marketplace_price'] = $sourceData['order']['tax']['marketplace_price'] - $itemTax;
                            if (isset($sourceData['order']['taxes_included']) && $sourceData['order']['taxes_included']) {
                                $sourceData['order']['total']['marketplace_price'] = $sourceData['order']['sub_total']['marketplace_price'] + $sourceData['order']['shipping_charge']['marketplace_price'];
                                $sourceData['order']['total']['price'] = $connectorOrderService->getConnectorPrice($sourceData['order']['marketplace_currency'], $sourceData['order']['total']['marketplace_price']);

                                if (isset($settings['taxes_included_order_item_tax']) && !$settings['taxes_included_order_item_tax']['value']) {
                                    $this->addLog("", $this->logOrderFile, "checkTaxEnable: taxes_included_order_item_tax settings false", self::LOG_PRIORITY_LEVEL_STANDARD);
                                    $this->adjustItemTaxForIncludedTaxes($sourceData, $itemTax, $connectorOrderService);
                                }

                            } else {
                                $sourceData['order']['total']['marketplace_price'] = $sourceData['order']['total']['marketplace_price'] - $itemTax;
                                $sourceData['order']['total']['price'] = $connectorOrderService->getConnectorPrice($sourceData['order']['marketplace_currency'], $sourceData['order']['total']['marketplace_price']);
                            }

                            unset($sourceData['order']['taxes'][$key]);
                        }

                        $this->addLog("", $this->logOrderFile, "checkTaxEnable: order_item_tax settings true", self::LOG_PRIORITY_LEVEL_STANDARD);
                    } elseif ($tax['code'] == 'ShippingTax') {
                        if (!empty($settings['order_shipping_tax']) && !$settings['order_shipping_tax']['value']) {
                            $this->addLog("", $this->logOrderFile, "checkTaxEnable: order_shipping_tax settings false, removing shipping tax", self::LOG_PRIORITY_LEVEL_STANDARD);
                            $shippingTax = $tax['marketplace_price'];
                            $sourceData['order']['tax']['marketplace_price'] = (round($sourceData['order']['tax']['marketplace_price']) - round($shippingTax)) > 0 ? (round($sourceData['order']['tax']['marketplace_price']) - round($shippingTax)) : 0;
                            $sourceData['order']['total']['marketplace_price'] = $sourceData['order']['total']['marketplace_price'] - $shippingTax;
                            $sourceData['order']['total']['price'] = $connectorOrderService->getConnectorPrice($sourceData['order']['marketplace_currency'], $sourceData['order']['total']['marketplace_price']);
                            $sourceData['order']['tax']['price'] = $connectorOrderService->getConnectorPrice($sourceData['order']['marketplace_currency'], $sourceData['order']['tax']['marketplace_price']);

                            if (isset($sourceData['order']['taxes_included']) && $sourceData['order']['taxes_included']) {
                                $sourceData['order']['shipping_charge']['marketplace_price'] = $sourceData['order']['shipping_charge']['marketplace_price'] - $shippingTax;
                                $sourceData['order']['shipping_charge']['price'] = $connectorOrderService->getConnectorPrice($sourceData['order']['marketplace_currency'], $sourceData['order']['shipping_charge']['marketplace_price']);

                                $sourceData['order']['total']['marketplace_price'] = $sourceData['order']['sub_total']['marketplace_price'] + $sourceData['order']['shipping_charge']['marketplace_price'];
                                $sourceData['order']['total']['price'] = $connectorOrderService->getConnectorPrice($sourceData['order']['marketplace_currency'], $sourceData['order']['total']['marketplace_price']);


                            }

                            unset($sourceData['order']['taxes'][$key]);
                        }

                        $this->addLog("", $this->logOrderFile, "checkTaxEnable: order_shipping_tax settings true", self::LOG_PRIORITY_LEVEL_STANDARD);
                    }
                }
            }

        }

        return $sourceData;
    }

    private function adjustItemTaxForIncludedTaxes(&$sourceData, $itemTax, $connectorOrderService)
    {
        $sourceData['order']['total']['marketplace_price'] = $sourceData['order']['total']['marketplace_price'] - $itemTax;
        $sourceData['order']['total']['price'] = $connectorOrderService->getConnectorPrice($sourceData['order']['marketplace_currency'], $sourceData['order']['total']['marketplace_price']);
        if ($itemTax <= 0 || !isset($sourceData['order']['items'])) {
            return;
        }

        foreach ($sourceData['order']['items'] as $key => $item) {
            if ($item['tax']['marketplace_price'] > 0) {
                foreach ($item['taxes'] as $tax) {
                    if (isset($tax['code']) && $tax['code'] == 'ItemTax' && $tax['marketplace_price'] > 0) {
                        $sourceData['order']['items'][$key]['marketplace_price'] -= $tax['marketplace_price'];
                        $sourceData['order']['items'][$key]['price'] = $connectorOrderService->getConnectorPrice($sourceData['order']['marketplace_currency'], $sourceData['order']['items'][$key]['marketplace_price']);
                    }
                }
            }
        }
    }

    public function getTaxRate($amount, $tax, $taxIncluded)
    {
        if ($amount == 0 || $tax == 0) {
            return 0;
        }

        if ($taxIncluded) {
            $amount = $amount - $tax;
        }

        $taxPercent = ($tax / $amount);
        $taxP = $taxPercent * 100;
        $taxPp = round($taxP);
        $taxPercent = $taxPp / 100;
        return $taxPercent;
    }



    public function ship($data)
    {
        //mold data format start
        // $mold_data = [
        //     "source_shop_id" => '',
        //     'target_shop_id'=>'',
        //     'target_marketplace'=>'',
        //     "marketplace_order_id" => '', //shopify oid
        //     "marketplace_shipment_id" =>  '',//
        //     "user_id" => '',
        //     "items"  => [
        //         [
        //         "type" => '', // virtual | real ?
        //         "title" => '',
        //         "sku" => '',
        //         "shipped_qty" => '',
        //         "weight" => ''
        //         ]
        //     ],
        //     "shipping_status" =>  '',
        //     "shipping_method" => [
        //         "code" => '',
        //         "title" => '',
        //         "carrier" => ''
        //     ],
        //     "tracking" => [
        //         [
        //             "company" =>  '',
        //             "number" => '',
        //             "name" => '',
        //             "url" => ''
        //         ]
        //         ],
        //     "shipment_created_at" => '',
        //     "shipment_updated_at" => ''

        // ];
        // mold data format ends

        $sourceShipmentService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\ShipInterface::class, [], $data['target_marketplace']);
        $moldData = $sourceShipmentService->mold($data);
        $connectorShipmentService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\ShipInterface::class, []);
        $savedData = $connectorShipmentService->ship($moldData);
        $targetShipmentService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\ShipInterface::class, [], $data['source_marketplace']);
        $targetShipmentService->ship($savedData);
        return ['success' => true, 'message' => 'Shipment Initiated'];
    }


    public function canCreateOrder(array $successOrderData, array $orderData): array
    {
        // echo "<pre>";
        //print_r($successOrderData);die;

        $maketPlaceWiseOrderItem = [];
        $orderItems = $orderData['data']['items'];
        foreach ($orderItems as $item) {
            if (isset($maketPlaceWiseOrderItem[$item['sku']])) {
                $maketPlaceWiseOrderItem[$item['sku']] = $maketPlaceWiseOrderItem[$item['sku']] + $item['qty'];
            } else {
                $maketPlaceWiseOrderItem[$item['sku']] = $item['qty'];
            }
        }

        $successOrder = [];
        $failed = [];
        if (count($successOrderData) > 1) {
            // partial order setting 
            foreach ($successOrderData as $shopId => $shopIdWiseOrder) {
                $failed[$shopId] = ['success' => false, 'message' => 'partial order setting disable'];
            }

            return ['success' => false, 'data' => $failed, 'message' => 'partial order with multiple targets'];
        }

        if (count($successOrderData) > 1) {
            foreach ($successOrderData as $shopId => $shopIdWiseOrder) {
                if (count($maketPlaceWiseOrderItem) == count($shopIdWiseOrder['order']['items'])) {
                    $successOrder = $successOrderData;
                } else {
                    // partial order setting 
                    $failed[$shopId] = ['success' => false, 'message' => 'partial order setting disable'];
                }
            }
        } else {
            $successOrder = $successOrderData;
        }

        if (empty($successOrder)) {
            return ['success' => false, 'data' => $failed, 'message' => 'no success order to create'];
        }

        return ['success' => true, 'data' => $successOrder];

    }

    public function checkOrderSyncEnable($sourceShopId, $targetShopId)
    {
        $objectManager = $this->di->getObjectManager();
        $configObj = $objectManager->get(\App\Core\Models\Config\Config::class);
        $configObj->setGroupCode("order");
        $configObj->setSourceShopId($sourceShopId);
        $configObj->setTargetShopId($targetShopId);
        $configObj->setAppTag(null);
        $configObj->setUserId($this->di->getUser()->id);

        $orderSetting = $configObj->getConfig();
        if (!empty($orderSetting)) {
            $orderSync = false;
            $orderSettingKeyWise = [];
            foreach ($orderSetting as $setting) {
                $orderSettingKeyWise[$setting['key']] = $setting;
                if ($setting['key'] == 'order_sync') {
                    if ($setting['value']) {
                        $orderSync = true;
                    }
                }
            }

            if ($orderSync) {
                return ['success' => true, 'data' => $orderSettingKeyWise];
            }

            return ['success' => false, 'message' => 'order sync not enable'];

        }

        return ['success' => true, 'data' => $orderSetting];

    }

    public function updateFailedOrder($cifOrderId, $failedOrder): void
    {

        $mongo = $this->di->getObjectManager()->create(\App\Core\Models\BaseMongo::class);
        $collection = $mongo->getCollectionForTable('order_container');

        foreach ($failedOrder as $homeShopId => $failedItemwthReason) {

            $failedItem = $failedItemwthReason['failed'];
            $errors = [];
            foreach ($failedItem as $item) {

                $sku = $item['sku'] ?? '';
                if ($sku) {
                    $errors[] = $sku . ':' . $item['reason'];
                } else {
                    $errors[] = $item['reason'];
                }
            }

            $checkTargetsObj = $collection->findOne(['user_id' => $this->di->getUser()->id, 'object_type' => 'source_order', 'cif_order_id' => $cifOrderId, 'targets.shop_id' => (string) $homeShopId], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
            $orderStatus = $this->di->getObjectManager()->get('\App\Connector\Components\Order\OrderStatus');
            if ($checkTargetsObj) {
                $collection->updateOne(['user_id' => $this->di->getUser()->id, 'object_type' => 'source_order', 'cif_order_id' => $cifOrderId, 'targets.shop_id' => $homeShopId], ['$set' => ['targets.$[a].errors' => $errors, 'targets.$[a].status' => $orderStatus::failed]], ['arrayFilters' => [['a.shop_id' => (string) $homeShopId]]]);
                $this->sendFailedEmail($cifOrderId, $errors, $homeShopId);

            } else {
                $orderStatus = $this->di->getObjectManager()->get('\App\Connector\Components\Order\OrderStatus');
                $addTargets = [];
                $addTargets['shop_id'] = (string) $homeShopId;
                $addTargets['marketplace'] = $failedItemwthReason['marketplace'];
                $addTargets['errors'] = $errors;
                $addTargets['status'] = $orderStatus::failed;
                $collection->updateOne(['user_id' => $this->di->getUser()->id, 'object_type' => 'source_order', 'cif_order_id' => $cifOrderId], ['$addToSet' => ['targets' => $addTargets]]);
                $this->sendFailedEmail($cifOrderId, $errors, $addTargets['shop_id']);
            }
        }

        if (!empty($failedOrder)) {
            $orderStatus = $this->di->getObjectManager()->get('\App\Connector\Components\Order\OrderStatus');
            $collection->updateOne(['user_id' => $this->di->getUser()->id, 'object_type' => 'source_order', 'cif_order_id' => $cifOrderId], ['$set' => ['status' => $orderStatus::failed, 'updated_at_iso' => new \MongoDB\BSON\UTCDateTime((int)(microtime(true) * 1000))]]);

        }

    }

    public function sendFailedEmail($cifOrderId, $reasons, $homeShopId): void
    {
        $params = [];
        $params['user_id'] = $this->di->getUser()->id;
        $params['object_type'] = 'source_order';
        $params['cif_order_id'] = $cifOrderId;
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $collection = $mongo->getCollection('order_container');
        $savedOrder = $collection->findOne($params, $options);

        if (!empty($savedOrder)) {
            $emailData = [];
            $emailData['source_order_id'] = $savedOrder['marketplace_reference_id'];

            // $orderSettingsResponse = $this->checkOrderSyncEnable($homeShopId, $savedOrder['marketplace_shop_id']);
            // if (
            //     !$orderSettingsResponse['success']    ||
            //     empty($orderSettingsResponse['data']) ||
            //     !isset($orderSettingsResponse['data']['email_on_failed_order']['value']) ||
            //     !$orderSettingsResponse['data']['email_on_failed_order']['value']
            // ) {
            //     return;
            // }
            if ($this->settingEnabledForMailSend($homeShopId, $savedOrder['marketplace_shop_id'], 'email_on_failed_order') == false) {
                $this->addLog("", $this->logOrderFile, 'Settings disabled for failed mail', self::LOG_PRIORITY_LEVEL_IMPORTANT);
                return;
            }

            $mailAlreadySent = false;
            foreach ($savedOrder['targets'] as $target) {
                if ($target['shop_id'] == $homeShopId) {
                    if (isset($target['failed_order_mail']) && $target['failed_order_mail']) {
                        $mailAlreadySent = true;
                    }
                }
            }

            $this->addLog("mailAlreadySent" . json_encode($mailAlreadySent), 'email/settings_test.log', self::LOG_PRIORITY_LEVEL_IMPORTANT);
            if (!$mailAlreadySent) {
                $appTag = 'default';
                $user_details = $this->di->getUser()->getConfig();
                if (!empty($user_details) && isset($user_details['shops'])) {
                    $targetMarketplace = '';
                    $shopName = '';
                    foreach ($user_details['shops'] as $shop) {
                        if ($shop['_id'] == $homeShopId) {
                            $targetMarketplace = $shop['marketplace'];
                            $shopName = $shop['name'] ?? '';
                            $appTag = $shop['apps'][0]['code'] ?? 'default';
                            break;
                        }
                    }

                    $path = 'connector' . DS . 'views' . DS . 'email' . DS . 'failedOrder.volt';
                    $emailData['source_marketplace'] = ucfirst($savedOrder['marketplace']);
                    $emailData['target_marketplace'] = ucfirst($targetMarketplace);
                    $emailData['reasons'] = $reasons;
                    $emailData['shop_name'] = $shopName;
                    $emailData['name'] = $user_details['name'] ?? 'there';
                    $emailData['email'] = $user_details['source_email'] ?? ($user_details['email'] ?? "");
                    $bccs[] = !empty($user_details['alternate_email']) ? $user_details['alternate_email'] : "";

                    if ($this->di->getUser()->id == "68221f59ed9541d78109dd25") {
                        $bccs = [];
                        $bccs[] = "billy@davidprotein.com";
                    }

                    $emailData['bccs'] = $bccs;
                    $emailData['path'] = $path;
                    $emailData['app_name'] = $this->di->getConfig()->app_name;
                    // $emailData['subject'] = 'Failed Order - '. $emailData['app_name'];
                    if (!empty($emailData['app_name'])) {
                        $emailData['subject'] = 'Order Sync Failed - '. $emailData['app_name'];
                    } else {
                        $emailData['subject'] = 'Order Sync Failed';
                    }
                    $emailData['app_tag'] = $appTag;
                    $emailData['user_id'] = $params['user_id'];
                    $emailData['source_shop_id'] = $homeShopId;
                    $emailData['target_shop_id'] = $savedOrder['marketplace_shop_id'];

                    $emailData['has_unsubscribe_link'] = false;
                    $linkdata = [
                        'user_id' => $params['user_id'],
                        'app_tag' => $appTag,
                        'source_shop_id' => $homeShopId,
                        'setting_name' => 'email_on_failed_order',
                        'target_shop_id' => $savedOrder['marketplace_shop_id'],
                        'source' => $targetMarketplace,
                        'target' => $savedOrder['marketplace']
                    ];
                    $linkResponse = $this->getUnsubscribeLink($linkdata);
                    $this->addLog("", $this->logOrderFile, "linkResponse: " . json_encode($linkResponse), self::LOG_PRIORITY_LEVEL_STANDARD);
                    if ($linkResponse['success']) {
                        $emailData['has_unsubscribe_link'] = true;
                        $emailData['unsubscribe_link'] = $linkResponse['data'];
                    }

                    //    $this->includeFailedOrderLinkIfAvailable($emailData);//this call is now deprecated and replaced with getgridlink

                    $gridlinkRes = $this->di->getObjectManager()->get('App\Connector\Components\Order\Helper')->getGridLink($emailData, $appTag);
                    $emailData['has_order_grid_link'] = false;
                    if ($gridlinkRes) {
                        $emailData['order_grid_link'] = $gridlinkRes;
                        $emailData['has_order_grid_link'] = true;
                    }

                    try {
                        $this->addLog("", $this->logOrderFile, "emailData: " . json_encode($emailData), self::LOG_PRIORITY_LEVEL_IMPORTANT);
                        $sendMailResponse = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($emailData);
                        $eventsManager = $this->di->getEventsManager();
                        $eventsManager->fire('application:orderFail', $this, $emailData);
                        $this->addLog("", $this->logOrderFile, "sendMailResponse: " . json_encode($sendMailResponse), self::LOG_PRIORITY_LEVEL_IMPORTANT);
                    } catch (\Exception $e) {
                        $sendMailResponse = false;
                        $this->addLog("", $this->logOrderFile, "Email send failed: " . $e->getMessage(), self::LOG_PRIORITY_LEVEL_CRITICAL);
                    }

                    $dbResponse = $collection->updateOne(
                        ['user_id' => $this->di->getUser()->id, 'object_type' => 'source_order', 'cif_order_id' => $cifOrderId],
                        ['$set' => ['targets.$[target].failed_order_mail' => $sendMailResponse]],
                        ['arrayFilters' => [['target.shop_id' => $homeShopId, 'target.marketplace' => $targetMarketplace]]]
                    );
                }
            }
        }
    }

    public function includeFailedOrderLinkIfAvailable(&$emailData): void
    {
        if (empty($emailData['target_marketplace'])) {
            return;
        }

        $targetOrderService = $this->di->getObjectManager()
            ->get(\App\Connector\Contracts\Sales\OrderInterface::class, [], $emailData['target_marketplace']);

        if (!method_exists($targetOrderService, 'getFailedOrderGridLink')) {
            return;
        }

        $linkResponse = $targetOrderService->getFailedOrderGridLink($emailData);
        $emailData['has_failed_order_grid_link'] = false;
        if ($linkResponse == false) {
            return;
        }

        $emailData['failed_order_grid_link'] = $linkResponse;
        $emailData['has_failed_order_grid_link'] = true;
    }

    public function updateSuccessOrder($cifOrderId, $successOrder, $status = 'Created'): void
    {
        $mongo = $this->di->getObjectManager()->create(\App\Core\Models\BaseMongo::class);
        $collection = $mongo->getCollectionForTable('order_container');
        $sourceOrderFilter = [
            'user_id' => $this->di->getUser()->id,
            'object_type' => 'source_order',
            'cif_order_id' => $cifOrderId
        ];
        $sourceOrderFilterWithTargetShop = [...$sourceOrderFilter, 'targets.shop_id' => $successOrder['shop_id']];
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $checkTargetsObj = $collection->findOne($sourceOrderFilterWithTargetShop, $options);
        if (isset($checkTargetsObj['user_id'])) {
            $collection->updateOne(
                $sourceOrderFilterWithTargetShop,
                [
                    '$set' => [
                        'status' => $status,
                        'targets.$[a].status' => 'Created',
                        'targets.$[a].order_id' => $successOrder['order_id']
                    ]
                ],
                ['arrayFilters' => [['a.shop_id' => $successOrder['shop_id']]]]
            );
        } else {
            $addTargets = [];
            $addTargets['shop_id'] = $successOrder['shop_id'];
            $addTargets['marketplace'] = $successOrder['marketplace'];
            $addTargets['order_id'] = $successOrder['order_id'];
            $addTargets['status'] = 'Created';
            $collection->updateOne(
                $sourceOrderFilter,
                [
                    '$set' => ['status' => $status],
                    '$addToSet' => ['targets' => $addTargets]
                ]
            );
        }
    }

    public function updateDisabledOrder($allDisabledOrders): void
    {
        $orderStatus = $this->di->getObjectManager()->get('App\Connector\Components\Order\OrderStatus');
        $mongo = $this->di->getObjectManager()->create(\App\Core\Models\BaseMongo::class);
        $collection = $mongo->getCollectionForTable('order_container');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

        $targets = [];
        foreach ($allDisabledOrders as $disabledOrder) {
            $sourceOrder = $collection->findOne(
                [
                    'user_id' => $this->di->getUser()->id,
                    'object_type' => 'source_order',
                    'cif_order_id' => $disabledOrder['cif_order_id'],
                    'targets.shop_id' => $disabledOrder['shop_id']
                ],
                $options
            );
            if (isset($sourceOrder['targets'])) {
                continue;
                //todo: can addToSet error -> settings disabled
            }

            $targets = [
                'shop_id' => $disabledOrder['shop_id'],
                'marketplace' => $disabledOrder['marketplace'],
                'errors' => [$disabledOrder['message']],
                'status' => $orderStatus::DISABLED
            ];
            $collection->updateOne(
                [
                    'user_id' => $this->di->getUser()->id,
                    'object_type' => 'source_order',
                    'cif_order_id' => $disabledOrder['cif_order_id'],
                ],
                ['$addToSet' => ['targets' => $targets]]
            );
        }
    }


    public function createSavedOrder($data)
    {

        $type = "sources";
        if (isset($data['savedData'])) {
            $sourceOrderShopId = $data['savedData']['marketplace_shop_id'];
            $connectorData = $data['savedData'];
        } else {
            $cifOrderId = $data['cif_order_id'];
            $connectorOrderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class);
            $params = [];
            $sourceOrderShopId = $data['target_shop_id'];
            $params['marketplace_shop_id'] = $data['target_shop_id'];
            $params['marketplace'] = $data['target_marketplace'];
            $params['cif_order_id'] = $data['cif_order_id'];
            $getOrder = $connectorOrderService->get($params);
            if ($getOrder['success']) {
                $connectorData = $getOrder['data'];
            } else {
                return ['success' => 'false', 'message' => 'Order not found'];
            }
        }

        if (isset($data['source']) && isset($data['source']['shopId'])) {
            $connectorData['manual_source_shop_id'] = $data['source']['shopId'];
            //  var_dump($connectorData['manual_source_shop_id']);die;
        }

        return $this->manageOrder(['data' => $connectorData], $sourceOrderShopId, $type);

    }

    // public function addSyncLogs($data, $msg = "")
    // {
    //     $div = '----------------------------------------------------------------';
    //     $file = 'order/target-order-sync/' . $this->di->getUser()->id . '/' . date('Y-m-d') . '.log';
    //     $time = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
    //     $this->di->getLog()->logContent($div . PHP_EOL . $time . PHP_EOL . $msg . PHP_EOL . '  ' . print_r($data, true), 'info', $file);
    // }

    public function addLog($data, $file, $msg = "", $priorityLevel = null): void
    {
        $orderLogConfig = $this->di->getConfig()->path('log_handler.order_create');
        $priorityEnabled = $orderLogConfig['priority'][$priorityLevel] ?? true;
        $isLogEnabled = $orderLogConfig['enabled'] ?? true;
        $orderLogEnabled = ($priorityEnabled && $isLogEnabled);
        if ($orderLogEnabled) {
            $this->di->getLog()->logContent($msg . ' ' . print_r($data, true), 'info', $file);
        }
    }

    public function createWebhook($webhookData)
    {
        $response = $this->verifyOrCreateTargetOrder($webhookData);
        return $response;
    }

    public function verifyOrCreateTargetOrder($webhookData)
    {
        $logFile = 'order/target-order-sync/' . $this->di->getUser()->id . '/' . date('Y-m-d') . '.log';
        if (empty($webhookData['marketplace'])) {
            return [
                'success' => false,
                'message' => 'marketplace not found in webhook data'
            ];
        }

        $targetOrderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class, [], $webhookData['marketplace']);
        $isTargetMissing = $targetOrderService->isTargetMissing($webhookData);
        if (!$isTargetMissing['success']) {
            return $isTargetMissing;
        }

        $getSourceOrderIdResponse = $targetOrderService->getSourceOrderIdFromAttributes($webhookData['data']);
        if (!$getSourceOrderIdResponse['success']) {
            return $getSourceOrderIdResponse;
        }

        $sourceOrderId = $getSourceOrderIdResponse['data'];
        $sourceOrderQuery['user_id'] = $this->di->getUser()->id;
        $sourceOrderQuery['object_type'] = 'source_order';
        $sourceOrderQuery['marketplace_reference_id'] = $sourceOrderId;

        $connectorOrderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class);
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollection($connectorOrderService::ORDER_CONTAINER);
        $sourceOrder = $mongo->findOne($sourceOrderQuery, $options);
        if (empty($sourceOrder)) {
            return [
                'success' => false,
                'message' => 'Source Order not found in DB'
            ];
        }

        $this->addLog($sourceOrderId, 'order/target-order-sync/' . $logFile, "Source Order Id: ", self::LOG_PRIORITY_LEVEL_IMPORTANT);
        $this->addLog(json_encode($sourceOrder), 'order/target-order-sync/' . $logFile, "Source Order Data: ", self::LOG_PRIORITY_LEVEL_IMPORTANT);
        $this->addLog(json_encode($webhookData), 'order/target-order-sync/' . $logFile,  "Webhook Data: ", self::LOG_PRIORITY_LEVEL_IMPORTANT);
        $targetOrderRawData = [];
        $targetOrderRawData['marketplace'] = $webhookData['marketplace'];
        if (isset($webhookData['shop_id'])) {
            $targetOrderRawData['shop_id'] = $webhookData['shop_id'];
        }

        $targetOrderRawData['order'] = $webhookData['data'];
        $targetOrderRawData['user_id'] = $this->di->getUser()->id;

        $sourceOrderItemMappings = $this->getItemLinkIdMappingsForWebhookProcessing($sourceOrder, $targetOrderRawData);

        $prepareForDbResponse = $targetOrderService->prepareForDb($targetOrderRawData);
        if (!$prepareForDbResponse['success']) {
            return ['success' => false, 'message' => 'prepareForDb returned false'];
        }

        $preparedTargetOrder = $prepareForDbResponse['data'];

        $preparedTargetOrder = $this->applyUniqueKeyMappings($preparedTargetOrder, $sourceOrderItemMappings);

        $preparedTargetOrder['cif_order_id'] = $sourceOrder['cif_order_id'];
        $preparedTargetOrder['schema_version'] = $connectorOrderService::SCHEMA_VERSION;
        $preparedTargetOrder['targets'][] = [
            'shop_id' => $sourceOrder['marketplace_shop_id'],
            'marketplace' => $sourceOrder['marketplace'],
            'order_id' => $sourceOrder['marketplace_reference_id'],
            'status' => $sourceOrder['marketplace_status']
        ];
        $connectorOrderService->setSchemaObject('target_order');
        $createTargetResponse = $connectorOrderService->create($preparedTargetOrder);
        if (!$createTargetResponse['success']) {
            return ['success' => false, 'message' => 'target_order not created due to some reason'];
        }

        $sourceOrderFilter = $sourceOrderQuery;
        $sourceOrderUnset = [
            '$pull' => [
                'targets' =>
                    [
                        'shop_id' => $preparedTargetOrder['marketplace_shop_id']
                    ]
            ]
        ];
        $dbResponse = $mongo->updateOne($sourceOrderFilter, $sourceOrderUnset);

        if ($dbResponse->getModifiedCount() < 1) {
            // TODO:
        }

        $this->addLog($dbResponse, $logFile,  "source_order update response: ", self::LOG_PRIORITY_LEVEL_IMPORTANT);
        $sourceOrderUpdateData = [];
        $sourceOrderUpdateData['shop_id'] = (string) $preparedTargetOrder['marketplace_shop_id'];
        $sourceOrderUpdateData['marketplace'] = $preparedTargetOrder['marketplace'];
        $sourceOrderUpdateData['order_id'] = (string) $preparedTargetOrder['marketplace_reference_id'];
        $this->updateSuccessOrder($preparedTargetOrder['cif_order_id'], $sourceOrderUpdateData);
        return ['success' => true, 'message' => 'target_order created.'];
    }

    private function getItemLinkIdMappingsForWebhookProcessing(array $sourceOrder, array $targetOrderRawData): array
    {
        $validateForCreateConfig = [
            'order_for_product_not_existing' => [
                'value' => true
            ]
        ];

        $targetMarketplaceService = $this->di->getObjectManager()
            ->get(\App\Connector\Contracts\Sales\OrderInterface::class, [], $targetOrderRawData['marketplace']);

        $validationPayload = [
            'shop_id' => $targetOrderRawData['shop_id'],
            'marketplace' => $targetOrderRawData['marketplace'],
            'target_shop_id' => $sourceOrder['shop_id'],
            'order' => $sourceOrder,
            'order_settings' => $validateForCreateConfig,
        ];

        $validationResult = $targetMarketplaceService->validateForCreate($validationPayload);

        if (empty($validationResult['success'])) {
            return [];
        }

        $sourceOrder['items'] = $validationResult['data'];

        $sourceOrderForLinkIdMapping = [
            $targetOrderRawData['shop_id'] => ['order' => $sourceOrder],
        ];

        return $this->mapItemsWithUniqueKey($sourceOrderForLinkIdMapping);
    }

    public function settingEnabledForMailSend($sourceShopId, $targetShopId, $key)
    {
        $objectManager = $this->di->getObjectManager();
        $configObj = $objectManager->get(\App\Core\Models\Config\Config::class);
        $configObj->setGroupCode("email");
        $configObj->setAppTag(null);
        $configObj->setUserId($this->di->getUser()->id);

        $emailSettings = $configObj->getConfig('send_email_notifications');
        $settingsExists = false;
        $val = false;
        if (!empty($emailSettings)) {
            $emailSettingEnable = false;
            foreach ($emailSettings as $setting) {
                if ($setting['key'] == 'send_email_notifications' && $setting['value']) {
                    $emailSettingEnable = true;
                }
            }

            if ($emailSettingEnable) {
                $configObj->setSourceShopId($sourceShopId);
                $configObj->setTargetShopId($targetShopId);
                $configObj->setAppTag(null);
                $configObj->setUserId($this->di->getUser()->id);
                $failedOrderMailSettings = $configObj->getConfig($key);
                if (!empty($failedOrderMailSettings)) {
                    $settingsExists = true;
                    if (isset($failedOrderMailSettings[0]['value'])) {
                        $val = $failedOrderMailSettings[0]['value'];
                    }
                } else {
                    $val = false;
                }
            } else {
                $val = false;
            }
        }

        if ($settingsExists == false) {
            $orderSettingsResponse = $this->checkOrderSyncEnable($sourceShopId, $targetShopId);
            if (isset($orderSettingsResponse['data']['email_on_failed_order']['value'])) {
                $val = $orderSettingsResponse['data']['email_on_failed_order']['value'];
            } else {
                $val = false;
            }
        }

        return $val;
    }


    /**
     * Sets the FBO order customer name in the connector response array.
     *
     * @param array &$connectorResponse The connector response to modify.
     * @param array $sourceSetting The source setting array.
     * @throws None
     */
    public function setFboOrderCustomerName(&$connectorResponse, $sourceSetting): void
    {
        $firstName = $sourceSetting['fbo_order_first_name']['value'] ?? 'Customer';
        $lastName = $sourceSetting['fbo_order_last_name']['value'] ?? 'Customer';
        $fullName = $firstName . ' ' . $lastName;
        if (empty($connectorResponse['data']['customer']['name'])) {
            $connectorResponse['data']['customer']['name'] = $fullName;
        }

        array_walk($connectorResponse['data']['shipping_address'], function (array &$shippingAddress) use ($fullName): void {
            if (empty ($shippingAddress['name'])) {
                $shippingAddress['name'] = $fullName;
            }
        });
    }

    public function getUnsubscribeLink($data)
    {
        if (!empty($this->di->getConfig()->get('unsubscribe_url_route'))) {
            $frontendUrl = $this->di->getConfig()->get('unsubscribe_url_route');
            $tokenData = [
                'user_id' => $data['user_id'],
                'setting_name' => $data['setting_name'],
                'app_tag' => $data['app_tag'],
                'only_token' => true,
                'action' => 'email_unsubscribe',
                'source_shop_id' => $data['source_shop_id'],
                'target_shop_id' => $data['target_shop_id'],
                'source' => $data['source'],
                'target' => $data['target']
            ];
            $this->addLog("", $this->logOrderFile, 'Token url data: ' . json_encode($tokenData), self::LOG_PRIORITY_LEVEL_STANDARD);
            $token = $this->di->getObjectManager()->get('App\Connector\Components\Emails\Email')->generateTokenForEmailSubscription($tokenData);
            // $this->addLog(json_encode($token), 'token.log');
            if ($token['success'] && isset($token['token'])) {
                $unsubscribeLink = $frontendUrl . '?token=' . $token['token'] . '&mail_type=Failed Order Mails';
                return [
                    'success' => true,
                    'data' => $unsubscribeLink
                ];
            }

            return $token;
        }
        return [
            'success' => false,
            'message' => 'No url to unsubscription!'
        ];
    }

    /**
     * Determines if retry is allowed for an existing order
     *
     * @param array $savedOrder The existing order from database
     * @return array ['allowed' => bool, 'reason' => string]
     */
    private function isRetryAllowed(array $savedOrder): array
    {
        $orderStatus = $savedOrder['status'] ?? '';
        $targets = $savedOrder['targets'] ?? [];
        $createdAt = $savedOrder['created_at'] ?? '';

        // Only allow retry if:
        // 1. Order status is "Pending"
        // 2. No targets exist
        // 3. Order was created at least 5 minutes ago

        if ($orderStatus !== 'Pending') {
            return [
                'allowed' => false,
                'reason' => "Order status is '{$orderStatus}', retry only allowed for Pending orders"
            ];
        }

        if (!empty($targets)) {
            return [
                'allowed' => false,
                'reason' => 'Order has targets, retry not allowed to prevent duplicates'
            ];
        }

        if (empty($createdAt)) {
            return [
                'allowed' => false,
                'reason' => 'Order creation time not found, retry not allowed'
            ];
        }

        // Check if order is at least 5 minutes old
        $createdTime = strtotime($createdAt);
        $currentTime = time();
        $timeDifferenceMinutes = ($currentTime - $createdTime) / 60;

        if ($timeDifferenceMinutes < 5) {
            return [
                'allowed' => false,
                'reason' => sprintf(
                    'Order is only %.1f minutes old, retry allowed after 5 minutes',
                    $timeDifferenceMinutes
                )
            ];
        }

        return [
            'allowed' => true,
            'reason' => sprintf(
                'Order is %d minutes old, has Pending status with no targets - retry allowed',
                (int)$timeDifferenceMinutes
            )
        ];
    }

    private function scheduleRetry(array $connectorResponse, $orderRetryHandler): bool
    {
        if (!is_object($orderRetryHandler)) {
            return false;
        }

        if (!isset($connectorResponse['data']['user_id'], $connectorResponse['data']['marketplace_shop_id'], $connectorResponse['data']['marketplace'], $connectorResponse['data']['marketplace_reference_id'])) {
            return false;
        }

        $orderRetryHandler->scheduleRetry(
            $connectorResponse['data']['user_id'],
            $connectorResponse['data']['marketplace_shop_id'],
            [
                'marketplace' => $connectorResponse['data']['marketplace'],
                'marketplace_shop_id' => $connectorResponse['data']['marketplace_shop_id'],
                'marketplace_reference_id' => $connectorResponse['data']['marketplace_reference_id']
            ]
        );

        return true;
    }
}
