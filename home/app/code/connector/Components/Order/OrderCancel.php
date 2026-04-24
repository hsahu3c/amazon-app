<?php

namespace App\Connector\Components\Order;

use Exception;

class OrderCancel extends \App\Core\Components\Base
{
    public $sourceOrderType = null;

    public $errors = [];

    public $targets = [];

    public $globalStatus = null;

    public $cif_order_id = null;

    public $sourceOrderObjectType =  null;

    public $logFile = 'order/order-cancel/order-cancel-log.log';

    /**
     * to get the data in required format from marketplace
     *
     * @param array $data
     */
    public function initiate($data = []): array
    {
        if (!isset($data['orders'])) {
            if (isset($data['marketplace'])) {
                $module = $this->validateAndGetTargetModuleObject($data['marketplace']);
                if ($module['success']) {
                    $moduleObj = $module['data'];
                    return $moduleObj->getFormattedData($data);
                }

                return $module;
            }

            return [
                'success' => false,
                'message' => 'Marketplace not found in cancel data'
            ];
        }

        return $data;
    }

    /**
     * to perform cancellation
     *
     * @return void
     */
    public function cancel(array $data)
    {
        $this->errors = [];
        $this->targets = [];
        $this->addLog("Received data: ".json_encode($data, true), 'order/order-cancel/received_cancel_data/' . $this->di->getUser()->id . '/' . date('d-m-y') . '/data.log');

        if (isset($data['process']) && $data['process'] == 'manual') {
            return $this->manualCancel($data);
        }

        $errors = [];
        $this->logFile = 'order/order-cancel/' . $this->di->getUser()->id . '/' . date('d-m-y') . '/automatic-cancel.log';
        //intializing log file
        $this->addLog(' --------------------------------- Automatic Cancel Start --------------------------------- ', $this->logFile);
        $data = $this->initiate($data);
        if (isset($data['success']) && ($data['success'] == false)) {
            $errors[] = $data['message'];
        }

        if (!isset($data['orders']) || empty($data['orders'])) {
            $errors[] = 'Orders not found';
        }

        if (!empty($errors)) {
            $this->addLog('Cancellation failed!! Errors >> ' . json_encode($errors), $this->logFile);
            $this->addLog(' --------------------------------- Automatic Cancel End --------------------------------- ', $this->logFile);
            return [
                'success' => false,
                'errors' => $errors
            ];
        }

        return $this->automaticCancel($data);
    }

    /**
     * to perform automatic cancellation
     *
     * @return array
     */
    public function automaticCancel(array $data)
    {
        $this->errors = [];

        $cancelSuccess = [];
        $orders = $data['orders'];
        foreach ($orders as $index => $order) {
            $this->addLog('>>> Index ' . $index . ' >>> ', $this->logFile);

            //Step 1: get source marketplace
            $sourceMarketplace = $this->getMarketplace($order, $orders);
            if (empty(trim($sourceMarketplace))) {
                $this->addLog("marketplace/shop_id not found or user is invalid", $this->logFile);
                $this->errors[] = [
                    'success' => false,
                    'message' => 'marketplace/shop_id not found or user is invalid'
                ];
                continue;
            }

            $this->addLog('Source Marketplace: ' . $sourceMarketplace, $this->logFile);

            //Step 2: Prepare source cancel data
            $perparedSourceCancelOrder = $this->prepareSourceCancel($order, $sourceMarketplace);
            if (!$perparedSourceCancelOrder['success']) {
                $this->addLog('Error in preparing source cancel data: ' . json_encode($perparedSourceCancelOrder), $this->logFile);
                $this->errors[] = $perparedSourceCancelOrder;
                continue;
            }

            $preparedCancelData = $perparedSourceCancelOrder['data'];
            $this->addLog('Source Order Cancel Prepared Data Success!!'.PHP_EOL.'marketplace_reference_id for cancellation: '.$preparedCancelData['marketplace_reference_id'], $this->logFile);

            //Step 3:  get parent orders
            $parentOrders = $this->getSourceAndTargetOrders($preparedCancelData);
            if (!$parentOrders['success']) {
                $this->addLog('Error in getting parent orders: '. json_encode($parentOrders), $this->logFile);
                $this->errors[] = $parentOrders;
                continue;
            }

            //parent orders
            $parentSourceOrder = $parentOrders['data']['source_orders'];
            $parentTargetOrders = $parentOrders['data']['target_orders'];

            //setting cif_order_id and object_type
            $preparedCancelData['cif_order_id'] = $this->cif_order_id;
            $orderCancelService = $this->getCancelService();
            $sourceCancelObjectType = $orderCancelService->setCancellationObjectType($this->sourceOrderObjectType);
            $this->addLog('CIF ORDER ID AND Object_type: ' . json_encode(['cif_order_id' => $this->cif_order_id, 'object_type' =>  $sourceCancelObjectType]), $this->logFile);

            //Step 4: check duplicacy
            $duplicacy = $this->checkDuplicacy($preparedCancelData, $sourceCancelObjectType, $parentSourceOrder);
            if ($duplicacy['success'] == false) {
                $duplicacy['marketplace_reference_id'] = $preparedCancelData['marketplace_reference_id'];
                $this->addLog('Duplicacy Error: ' . json_encode($duplicacy), $this->logFile);
                $this->errors[] = $duplicacy;
                continue;
            }

            if ($duplicacy['success'] == true && isset($duplicacy['data'])) {
                $this->addLog('Partial Duplicacy Exist: ' . json_encode($duplicacy), $this->logFile);
                $preparedCancelData = $duplicacy['data'];
            }
            else {
                $this->addLog(json_encode($duplicacy), $this->logFile);
            }

            //Step 5: get source cancel status
            $this->addLog( 'Step 5  : get source cancel status', $this->logFile);
            if(empty($preparedCancelData['items'])) {
                $this->addLog('No items found for processing. Data: ' . json_encode($preparedCancelData), $this->logFile);
                $this->errors[] = ['No items found for cancellation.'];
                continue;
            }

            $this->globalStatus = $this->getStatusOfCancellation($preparedCancelData, $parentSourceOrder);

            $preparedCancelData['status'] = $this->getStatus($this->globalStatus);
            $preparedCancelData = $this->updateLinkItemIds($preparedCancelData, $parentSourceOrder);
            $this->addLog('Source order status: ' . $preparedCancelData['status'], $this->logFile);

            //Step 6: save source cancel
            $sourceCancelDataResponse = $orderCancelService->create($preparedCancelData);
            if (!$sourceCancelDataResponse['success']) {
                $this->addLog('Unable to save cancel order. ' . $sourceCancelDataResponse['message'], $this->logFile);
                $this->errors[] =  'Unable to save cancel order. ' . $sourceCancelDataResponse['message'];
                continue;
            }

            $sourceCancelData = $sourceCancelDataResponse['data'];

            //Step 7: Update parent source
            $this->addLog('Step 7   : parent source updates', $this->logFile);
            $updatedResponse = $this->performParentUpdations($parentSourceOrder, $sourceCancelData, $this->globalStatus);
            if (!$updatedResponse['success']) {
                $this->addLog(json_encode(['Updation failed at parent order: ' => $updatedResponse['message']]), $this->logFile);
                $this->errors[] = ['Updation failed at source order: ' => $updatedResponse['message']];
            }

            $updatedParentSourceOrder = $updatedResponse['data'];
            //$updatedParentSourceOrder = $orderCancelService->findFromDb(['_id' => $parentSourceOrder['_id']]);

            //Step 8: search targets
            //Case: Targets not exist
            if (empty($parentTargetOrders)) {
                //if target not exist, perform important updates and finishing the process
                $this->addLog('Targets not found!!', $this->logFile);
                $response = $this->handleEmptyTargets($updatedParentSourceOrder, $sourceCancelData);
                if (!$response['success']) {
                    $this->addLog('Target updates and finishing the process for the order is incomplete: ' . $response['message'], $this->logFile);
                } else {
                    $this->addLog('End: Performing target updates and finishing the process for the order', $this->logFile);
                }

                $this->addLog( '-----------------------------------------------', $this->logFile);
                $this->errors[] =  [
                    'success' => false,
                    'message' => 'Targets not found in database!!'
                ];
            } else {
                $segregatedData = $this->checkPartialAndDataSeggregation($parentTargetOrders, $sourceCancelData, $updatedParentSourceOrder); //parentSourceOrder is not used for now but can be used in future

                //order cancellation for each cancel data at target
                //segregation start
                $updatedParentTargets = [];
                if ($segregatedData['success']) {
                    $this->addLog('Segregated Data Received after seggregation: '. json_encode($segregatedData), $this->logFile);

                    //target process start
                    foreach ($segregatedData['data'] as $marketplace => $data) {

                        if(empty($data['items'])) {
                            $this->errors[] = 'Items not exist for cancellation for shop_id: '.$marketplace;
                            $this->addLog('Items not exist for cancellation for shop_id: '.$marketplace, $this->logFile);
                            continue;
                        }

                        //Step 10: get target cancel status
                        $status = $data['status'];
                        //$status = $data['isPartial'] ? 'partial' : 'full';
                        $this->addLog('Cancel status for Shop id: ' . $marketplace . ' is =>' . $status, $this->logFile);

                        //Step 11: check settings enabled or not
                        //if settings are disabled we can't proceed as user don't want that
                        if (isset($data['settings']) && ($data['settings'] == false)) {
                            $data['status'] = $this->getStatus('Disabled');
                            array_push($this->targets, $this->createTargetData($data, 'Settings disabled'));
                            $orderCancelService->update(
                                [
                                    '_id' => $data['_id']
                                ],
                                [
                                    'cancellation_status' => 'Disabled'
                                ]
                            );
                            array_push($this->targets, $this->createTargetData($data, 'Settings disabled'));
                            $this->errors[] = ['success' => false, 'message' => 'Settings disabled'];
                            $this->addLog('Cancellation settings disabled! can\'t proceed!', $this->logFile);
                            continue;
                        }

                        //Step 12: If any error occurs generally in case of partial order or unable to map reasons
                        $this->addLog('Step 12  : Check for errors', $this->logFile);
                        if (isset($data['errors'])) {
                            $this->addLog('Errors exist in target data. Errors are: '. json_encode($data['errors']), $this->logFile);
                            $failureResponse = $this->handleFailure($data, $sourceCancelData, $sourceCancelObjectType, $updatedParentSourceOrder);
                            if($failureResponse['success']) {
                                $this->targets = $failureResponse['data'];
                            } else {
                                $this->targets = $failureResponse['data'];
                                $this->errors[] = $failureResponse['errors'];
                            }

                            continue;
                        }

                        //Step 13: cancel data at target
                        $targetMarketplaceCancelService = $this->getCancelService($data['marketplace']);
                        $orderCancelResponse = $targetMarketplaceCancelService->cancel($data);

                        if (!$orderCancelResponse['success'] || empty($orderCancelResponse['orders'])) {
                            //if unable to cancel data at target
                            $cancelSuccess[$data['marketplace']] = false;
                            isset($orderCancelResponse['message']) && $data['errors'][] = $orderCancelResponse['message'];
                            $this->addLog('Unable to cancel order at target or orders not found' . json_encode($data['errors']), $this->logFile);
                            $failureResponse = $this->handleFailure($data, $sourceCancelData, $sourceCancelObjectType, $updatedParentSourceOrder);
                            if($failureResponse['success']) {
                                $this->targets = $failureResponse['data'];
                            } else {
                                $this->targets = $failureResponse['data'];
                                $this->errors[] = $failureResponse['errors'];
                            }

                            $this->errors[] = ['Cancellation failed at ' . ucfirst($data['marketplace']) . ' whose shop id is ' . $data['marketplace_shop_id']];
                            continue;
                        }

                        $cancelSuccess[$data['marketplace']] = true;
                        $this->addLog('Cancellation done successfully at target', $this->logFile);

                        //Step 14: moulding data at target
                        $this->addLog('Step 14  : Prepare data at target', $this->logFile);
                        $targetPreparedData = $this->prepareSourceCancel($orderCancelResponse['orders'], $data['marketplace']);
                        //$targetPreparedData = $targetMarketplaceCancelService->prepareForDb($orderCancelResponse['orders']);
                        if (!$targetPreparedData['success']) {
                            array_push($this->targets, $this->createTargetData($data, 'Moulding of data fail'));
                            $this->addLog('Moulding of data fail at target. Unable to process further'. $targetPreparedData['message'], $this->logFile);
                            $this->errors[] = [$data['marketplace'] => 'Data preparation failure for target\'s cancellation'];
                            continue;
                        }

                        //Step 15: Save data in database
                        $targetCancelObjectType = $orderCancelService->toggleCancellationObjectType($sourceCancelObjectType);
                        $targetData = $targetPreparedData['data'];
                        $targetData['cif_order_id'] = $this->cif_order_id;
                        $this->addLog('Object type and cif_order_id for target' . json_encode(['cif_order_id' => $this->cif_order_id, 'object_type' => $targetCancelObjectType]), $this->logFile);

                        //adding status key
                        $targetData['status'] = $this->getStatus($status);
                        $targetData = $this->updateLinkItemIds($targetData, $data);
                        $targetCancelOrder = $orderCancelService->create($targetData);
                        if (!$targetCancelOrder['success']) {
                            $this->addLog('Unable to save data for target cancel in db: '.$targetCancelOrder['message'],$this->logFile);
                            $this->addLog( 'Data sent for save: '. json_encode($targetData), $this->logFile);
                            $this->errors[] = [$data['marketplace'] => 'Save failure for target_cancellation'];
                            array_push($this->targets, $this->createTargetData($data, 'Unable to save data in database.'));
                            continue;
                        }

                        //Step 16: getting source cancel data targets to save in target cancel order
                        $currentTargets = $this->createTargetData($sourceCancelData);

                        //parent target updations
                        //Step 17: updating target parent order
                        foreach ($parentTargetOrders as $target) {
                            if (($target['marketplace_shop_id'] == $data['marketplace_shop_id']) && ($target['marketplace_reference_id'] == $data['marketplace_reference_id'])) {
                                $targetStatusResponse = $this->performParentUpdations($target, $targetCancelOrder['data'], $status);
                                if (!$targetStatusResponse['success']) {
                                    $this->addLog('Some updations failed at target: '.$targetStatusResponse['message'], $this->logFile);
                                    $this->errors[] = [$target['marketplace'] => 'Updation failed at ' . $target['marketplace'] . '\'s order_id ' . $target['marketplace_reference_id']];
                                } else {
                                    $updatedParentTargets[] = $targetStatusResponse['data'];
                                }

                                //in case if there exist any cancel_failed_reason key
                                $orderCancelService->unsetData(['_id' => $target['_id']], ['cancel_failed_reason' => 1]);
                            }
                        }

                        //parent target updations end

                        //Step 18: updating targets and reference_id in cancel data
                        if(!isset($currentTargets[0])) {
                            $currentTargets = array($currentTargets);
                        }

                        $targetCancelUpdate = $orderCancelService->update(['_id' => $targetCancelOrder['data']['_id']], ['targets' => $currentTargets, 'reference_id' => (string)$sourceCancelData['_id']]);

                        if (!$targetCancelUpdate['success']) {
                            $this->addLog('Updations failed at target cancel data when updating targets and reference_id: ' . $targetCancelUpdate['message'], $this->logFile);
                            $this->errors[] = [$target['marketplace'] => 'Updation failed at Target cancel order ' . $target['marketplace'] . '\'s order_id ' . $target['marketplace_reference_id']];
                            array_push($this->targets, $this->createTargetData($targetCancelOrder['data']));
                        } else {
                            $this->addLog('Update successful', $this->logFile);
                            array_push($this->targets, $this->createTargetData($targetCancelUpdate['data']));
                        }

                        //Step 19: Unsetting the inprogress key to finish target cancel process
                        $orderCancelService->unsetData(['_id' => $targetCancelOrder['data']['_id']], ['process_inprogess' => $targetCancelOrder['data']['process_inprogess']]);
                    }

                    $this->addLog(' -------------------- Target process end -----------------', $this->logFile);
                    //end of target process
                } else {
                    $this->addLog('Cancellable data not found: '. json_encode($segregatedData), $this->logFile);
                    $this->errors[] = $segregatedData;
                    foreach ($parentTargetOrders as $targets) {
                        array_push($this->targets, $this->createTargetData($targets, 'Cancel data not found!'));
                    }
                }

                //Step 20: updating targets
                if (!empty($this->targets)) {
                    if(!isset($this->targets[0])) {
                        $this->targets = array($this->targets);
                    }

                    $updatedSourceCancellationReponse = $orderCancelService->update(['_id' => $sourceCancelData['_id']], ['targets' => $this->targets]);
                    if (!$updatedSourceCancellationReponse['success']) {
                        $this->addLog('Updation of targets at source cancel failed', $this->logFile);
                        $this->errors[] = [$sourceCancelData['marketplace'] => "Updation failed at source cancel order's targets"];
                    }
                }

                $this->updateParentOrderTargetsCancellationStatus($this->cif_order_id);


                //Step 21: unsetting in_progress key
                if (isset($sourceCancelData['process_inprogess'])) {
                    $updatedData = $orderCancelService->unsetData(['_id' => $sourceCancelData['_id']], ['process_inprogess' => $sourceCancelData['process_inprogess']]);
                    $this->addLog('Key unsetted successfully', $this->logFile);
                }

                //segregation end
            }

            $this->addLog('>>> Finish at index ' . $index . ' >>> ', $this->logFile);
        }

        //out of loop

        if (!empty($this->errors)) {
            $this->addLog('Errors: '. json_encode($this->errors), $this->logFile);
            if (!empty($cancelSuccess) && (count(array_unique($cancelSuccess)) == 1) && ($cancelSuccess[array_key_first($cancelSuccess)] == true)) {
                $this->addLog(' ********************************* Automatic Cancel Done********************************', $this->logFile);
                return [
                    'success' => true,
                    'message' => $this->di->getLocale()->_('cancellation_with_conflicts')
                ];
            }

            $this->addLog('Cancellation Failed!', $this->logFile);
            $this->addLog(' ********************************* Automatic Cancel Done ********************************', $this->logFile);
            return [
                'success' => false,
                'message' => 'Cancellation Failed! Please check logs!',
                'errors' => $this->errors
            ];
        }

        $this->addLog('Cancellation done successfully!', $this->logFile);
        $this->addLog(' ********************************* Automatic Cancel Done ********************************', $this->logFile);
        return [
            'success' => true,
            'message' => 'Success!! Order Cancellation Done'
        ];
    }



    /**
     * to perform manual cancellation
     *
     * @param array $data
     * @return array
     */
    public function manualCancel($data)
    {
        $this->logFile = 'order/order-cancel/' . $this->di->getUser()->id . '/' . date('d-m-y') . '/manual-cancel.log';//intializing log file
        $this->addLog(' **************** Manual Cancel Start *****************', $this->logFile);

        $userId = $this->di->getUser()->id;
        $source_shop_id = $this->di->getRequester()->getSourceId();
        $target_shop_id = $this->di->getRequester()->getTargetId();
        $source = $this->di->getRequester()->getSourceName();
        $target = $this->di->getRequester()->getTargetName();
        $appTag = $this->di->getAppCode()->getAppTag();

        $shop = $this->getShopData($source_shop_id);
        if (empty($shop)) {
            $this->addLog('Shop not found!!', $this->logFile);
            $this->addLog(' **************** Manual Cancel End *****************', $this->logFile);
            return [
                'success' => false,
                'message' => 'Shop not found for existing user'
            ];
        }

        $response = $this->validateManualData($data);
        if(!$response['success']) {
            $this->addLog("validateManualData:  ".json_encode($response), $this->logFile);
            $this->addLog(' **************** Manual Cancel Done *****************', $this->logFile);
            return $response;
        }

        $this->addLog('Manual data received: '. json_encode($data), $this->logFile);
        $targetMarketplace = !empty($source) ? $source : $shop['marketplace'];
        $this->addLog('Cif target marketplace found:    '. $targetMarketplace, $this->logFile);
        $orderCancelService = $this->getCancelService();
        $status = $this->getStatusOfMarketplace($targetMarketplace);
        $this->addLog('Status of cancellation: '.$status, $this->logFile);

        $order_object_id = (string)$data['_id']['$oid'];
        $syncEnable = $this->checkSyncEnable($source_shop_id, $target_shop_id);
        if (isset($syncEnable['success'], $syncEnable['data']) && $syncEnable['success']) {
            $data['settings_data'] = $syncEnable['data'];
        }
        $targetMarketplaceCancelService = $this->getCancelService($targetMarketplace);
        $orderCancelResponse = $targetMarketplaceCancelService->cancel($data);

        if (!$orderCancelResponse['success'] || !isset($orderCancelResponse['orders'])) {
            if(!empty($data['cancel_failed_reason'])) {
                array_unshift($data['cancel_failed_reason'], $orderCancelResponse['message']);
                $orderCancelService->update(['_id' => new \MongoDB\BSON\ObjectId($order_object_id)], ['cancel_failed_reason' => $data['cancel_failed_reason']]);
            }

            $this->addLog('Cancellation failed!!', $this->logFile);
            $this->addLog('Remote response: '. json_encode($orderCancelResponse), $this->logFile);
            $this->addLog(' **************** Manual Cancel Done *****************', $this->logFile);
            return [
                "success" => false,
                "message" => $orderCancelResponse['message']
            ];
        }

        $this->addLog('Cancel done at target!', $this->logFile);

        $manualCancelService = $this->getCancelService("manual");

        $preparedTargetData = $targetMarketplaceCancelService->prepareForDb($orderCancelResponse['orders']);

        if ($preparedTargetData['success']) {
            //generating cif target cancellation
            $cifTargetOrder = $preparedTargetData['data'];
            $manualCancelService->setSchemaObject("cif_target_cancellation"); //manual service
            $manualCancelService->setStatus($this->getStatus($status));
            $cifTargetOrder = $manualCancelService->setReferenceKeys($order_object_id, $data['marketplace_shop_id'], $cifTargetOrder);
            $cifTargetOrder['cif_order_id'] = $data['cif_order_id'];
            $this->cif_order_id = $data['cif_order_id'];

            $parentOrder = $orderCancelService->findOneFromDb(['_id' => new \MongoDB\BSON\ObjectId($order_object_id)]);
            $cifTargetOrder = $this->updateLinkItemIds($cifTargetOrder, $parentOrder);

            $cifTargetOrderRes = $manualCancelService->create($cifTargetOrder); //manual service

            if ($cifTargetOrderRes['success']) {
                $this->addLog(' cif_target_cancellation data creation: successful ', $this->logFile);
                $cifTargetTargets = $this->createTargetData($cifTargetOrderRes['data']);
                $updatedTarget = $this->performParentUpdations($parentOrder, $cifTargetOrderRes['data'], $status);
                if(!$updatedTarget['success']) {
                    $this->addLog('Updation failed at parent. Response:  '. json_encode($updatedTarget), $this->logFile);
                }

                $orderCancelService->unsetData(['_id' => $parentOrder['_id']], ['cancel_failed_reason' => 1]);

                //generating cif source cancellation
                $cifSource = $data;
                $cifSource = $this->unsetUnwantedKeys($cifSource, ['_id', 'object_type', 'shop_id', 'targets', 'process', 'status']);

                //to unset marketplace_cancel_reason keys as they are not part of schema
                foreach ($cifSource['items'] as $key => $value) {
                    if (isset($value['marketplace_cancel_reason'])) {
                        unset($data['items'][$key]['marketplace_cancel_reason']);
                    }
                }

                $manualCancelService->setSchemaObject('cif_source_cancellation');
                $manualCancelService->setStatus($this->getStatus($status));
                $cifTargetOrder = $manualCancelService->setReferenceKeys((string)$parentOrder['_id'], $data['marketplace_shop_id'], $cifSource);
                $manualCancelService->setSchemaObject("cif_source_cancellation"); //manual service
                $cifSourceOrderRes = $manualCancelService->create($cifSource); //manual service

                if (!$cifSourceOrderRes['success']) {
                    $this->addLog('cif_source_cancellation data creation: fail '. json_encode($$cifSourceOrderRes), $this->logFile);
                    array_push($this->errors, ['unable to create source cif order']);
                } else {
                    //updating target orders
                    $cifSourceTargets = $this->createTargetData($cifSourceOrderRes['data']);
                    $orderCancelService->update(['_id' => $cifTargetOrderRes['data']['_id']], ['targets' => [$cifSourceTargets]]);
                    $orderCancelService->update(['_id' => $cifSourceOrderRes['data']['_id']], ['targets' => [$cifTargetTargets]]);
                    $this->updateParentOrderTargetsCancellationStatus($this->cif_order_id);
                    $orderCancelService->unsetData(['_id' => $cifTargetOrderRes['data']['_id']], ['process_inprogess' => 1]);
                    $orderCancelService->unsetData(['_id' => $cifSourceOrderRes['data']['_id']], ['process_inprogess' => 1]);
                }
            } else {
                $this->addLog('cif_target_cancellation data creation: failed ', $this->logFile);
                array_push($this->errors, ['Unable to create cif target order']);
            }
        } else {
            $this->addLog('Data prepartion at target: fail. Response:  '. json_encode($preparedTargetData), $this->logFile);
            array_push($this->errors, ['Preparation failed due to some reasons']);
        }

        if (!empty($this->errors)) {
            $this->addLog('Errors exist: '. json_encode($this->errors), $this->logFile);
            $this->addLog(' **************** Manual Cancel Done *****************', $this->logFile);
            return [
                "sucess" => true,
                "messages" => "Cancellation done!"
            ];
        }

        $this->addLog(' **************** Manual Cancel Done *****************', $this->logFile);
        return [
            "success" => true,
            "message" => "Cancellation done successfully"
        ];
    }

    /**
     * to check some required keys
     *
     * @param array $data
     * @return array
     */
    public function validateManualData($data)
    {
        $errors = "";
        if (!isset($data['process']) || ($data['process'] !== "manual")) {
            $errors .= 'This is not manual cancel data or process key is missing. ';
        }

        if (!isset($data['cancel_reason'])) {
            $errors .= 'cancel_reason required!! ';
        }

        if (!isset($data['_id']['$oid'])) {
            $errors .= "Data is invalid as id is not found. ";
        }

        if (!isset($data['marketplace']) || !isset($data['marketplace_reference_id']) || !isset($data['marketplace_shop_id'])) {
            $errors .= 'Insufficient data! marketplace/marketplace_shop_id/marketplace_reference_id not found! ';
        }

        if($errors !== "") {
            return [
                'success' => false,
                'message' => $errors
            ];
        }

        return [
            "success" => true,
            "message" => "Data is valid"
        ];
    }

    /**
     * to retrive marketplace, all possible sources to find marketplace
     *
     * @param array $order
     * @param array $data
     * @return string
     */
    public function getMarketplace($order, $orders)
    {
        if (isset($orders['marketplace'])) {
            return  $orders['marketplace'];
        }

        if (isset($order['marketplace'])) {
            return $order['marketplace'];
        }

        if ((isset($order['shop_id']) && ($shop_id = $order['shop_id'])) || (isset($orders['shop_id']) && ($shop_id = $orders['shop_id']))) {
            $shop = $this->getShopData($shop_id);
            return $shop['marketplace'];
        }

        return "";
    }

    /**
     * to find status of cancellation
     *
     * @param array $sourceCancelData
     * @param array $parentSourceData
     * @return string
     */
    public function getStatusOfCancellation($sourceCancelData, $parentSourceData)
    {
        $isPartial = false;
        if (isset($sourceCancelData['items']) && isset($parentSourceData['items'])) {
            if (count($sourceCancelData['items']) !== count($parentSourceData['items'])) {
                $isPartial = true;
            } else {
                foreach ($sourceCancelData['items'] as $cancelItem) {
                    foreach ($parentSourceData['items'] as $sourceItem) {
                        //sku matching has been removed with marketplace_item_id
                       // if ($sourceItem['sku'] == $cancelItem['sku']) {
                        if ((string)$sourceItem['marketplace_item_id'] == (string)$cancelItem['marketplace_item_id']) {
                            $qty =  isset($sourceItem['cancelled_qty']) ? (int)$sourceItem['qty'] - (int)$sourceItem['cancelled_qty'] : $sourceItem['qty'];
                            if ((int)$qty !== (int)$cancelItem['qty']) {
                                $isPartial = true;
                            }
                        }
                    }
                }
            }
        }

        if ($isPartial == true) {
            $status = 'partial';
        } else {
            $status = 'full';
        }

        return $status;
    }

    /**
     * to update taregts in case tragets are not available
     *
     * @param array $parentOrder
     * @param array $sourceCancelData
     * @return array
     */
    public function handleEmptyTargets($parentOrder, $sourceCancelData)
    {
        $orderCancelService = $this->getCancelService();
        $failureTargets = [];
        if (isset($parentOrder['targets'])) {
            foreach ($parentOrder['targets'] as $target) {
                $targetDataForFailure = [];
                isset($target['shop_id']) && $targetDataForFailure['shop_id'] = $target['shop_id'];
                isset($target['marketplace']) && $targetDataForFailure['marketplace'] = $target['marketplace'];
                $targetDataForFailure['status'] = 'Failed';
                $targetDataForFailure['error'] = ['Targets not found in database'];
                $failureTargets[] = $targetDataForFailure;
            }
        } else {
            $targetDataForFailure = [];
            $targetDataForFailure['status'] = 'Failed';
            $targetDataForFailure['error'] = ['Targets not found in database'];
            $failureTargets = $targetDataForFailure;
        }

        $response = $orderCancelService->update(['_id' => $sourceCancelData['_id']], ['targets' => $failureTargets]);

        if (isset($sourceCancelData['process_inprogess'])) {
            $orderCancelService->unsetData(['_id' => $sourceCancelData['_id']], ['process_inprogess' => $sourceCancelData['process_inprogess']]);
        }

        if ($response['success']) {
            return [
                'success' => true,
                'message' => 'updates done'
            ];
        }

        return $response;
    }

    /**
     * to get service object
     *
     * @param string $marketplace
     * @return object
     */
    public function getCancelService($marketplace = null)
    {
        if (is_null($marketplace)) {
            $orderCancelService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\CancelInterface::class);
        } else {
            $orderCancelService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\CancelInterface::class, [], $marketplace);
        }

        return $orderCancelService;
    }

    /**
     * to prepare the source cancel data
     *
     * @param array $order
     * @param string $marketplace
     * @return array
     */
    public function prepareSourceCancel($order, $marketplace)
    {
        $marketplaceCancelService = $this->getCancelService($marketplace);
        $preparedDataResponse = $marketplaceCancelService->prepareForDb($order);
        if ($preparedDataResponse['success'] === false) {
            return $preparedDataResponse;
        }

        if (!isset($preparedDataResponse['data']['marketplace_reference_id'])) {
            return [
                'success' => false,
                'message' => 'marketplace_reference_id not found in prepared data from ' . $marketplace
            ];
        }

        return [
            'success' => true,
            'data' => $preparedDataResponse['data']
        ];
    }

    // public function alreadyCancelled($cancelData, $parentData)
    // {
    //     if (isset($parentData['status'])) {
    //         switch ($parentData['status']) {
    //             case 'Cancelled':
    //                 $status = 'Cancelled';
    //                 break;
    //             case 'Partially Cancelled':
    //                 $cancelItems = $cancelData['items'];
    //                 $parentItems = $parentData['items'];
    //                 $itemCount = 0;
    //                 foreach ($parentItems as $parentItem) {
    //                     $qtyEqual = false;
    //                     foreach ($cancelItems as $cancelItem) {
    //                         if (($cancelItem['sku'] == $parentItem['sku']) && isset($parentItem['cancelled_qty']) && !empty($parentItem['cancelled_qty'])) {
    //                             if ((int)$cancelItem['qty'] == (int)($parentItem['qty'] - $parentItem['cancelled_qty'])) {
    //                                 $qtyEqual = true;
    //                             }
    //                         }
    //                         if ($qtyEqual) {
    //                             $itemCount++;
    //                         }
    //                     }
    //                 }
    //                 if (count($cancelItems) !== $itemCount) {
    //                     $status = 'Complete';
    //                 } else {
    //                     $status = 'Partial';
    //                 }
    //                 break;
    //             default:
    //                 $status = 'No';
    //                 break;
    //         }
    //         return $status;
    //     }
    //     return 'No';
    // }

    /**
     * to get the updated status for particular targets in order
     *
     * @param array $source//that needs to be updated
     * @param array $targets//the updated data
     * @param array $updatedData
     * @return void
     */
    public function getUpdatedTarget($source, $targets, $updatedData)
    {
        if (!is_array($targets)) {
            $targets = array($targets);
        }

        foreach ($source['targets'] as $key1 => $value1) {
            foreach ($targets as $target) {
                if (isset($value1['order_id']) && ($value1['order_id'] == $target['marketplace_reference_id'])) {
                    isset($target['status']) && $updatedData['targets.' . $key1 . '.status'] = $target['status'];
                } else if (($value1['shop_id'] == $target['marketplace_shop_id']) && ($value1['marketplace'] == $target['marketplace'])) {
                    isset($target['status']) && $updatedData['targets.' . $key1 . '.status'] = $target['status'];
                }
            }
        }

        return $updatedData;
    }

    /**
     * to get all source and target orders
     *
     * @return array
     */
    public function getSourceAndTargetOrders(array $data)
    {
        $orderCancelService = $this->getCancelService();
        $params = [
                "user_id" => $this->di->getUser()->id,
                '$or' => [
                    [
                        'object_type' => 'source_order'
                    ],
                    [
                        'object_type' => 'target_order'
                    ]
                ],
                'marketplace_reference_id' => $data['marketplace_reference_id'],
                'marketplace_shop_id' => $data['marketplace_shop_id']
        ];
        $sourceOrders = $orderCancelService->findFromDb($params);
        if (empty($sourceOrders)) {
            return [
                'success' => false,
                'message' => 'Marketplace Order Not Found In Database!!'
            ];
        }

        if (count($sourceOrders) > 1) {
            return [
                'success' => false,
                'message' => 'Multiple orders found in database, order duplicacy exists can\'t proceed!!'
            ];
        }

        $requiredData = $this->getObjectTypeAndCifId($sourceOrders);
        if ($requiredData === false) {
            return [
                'success' => false,
                'message' => 'object_type/cif_order_id not found in parent order data'
            ];
        }

        $query = ["user_id" => $this->di->getUser()->id, 'object_type' => $this->alterObjectType($this->sourceOrderObjectType), 'cif_order_id' => $this->cif_order_id];
        $targetOrders = $orderCancelService->findFromDb($query);
        if (empty($targetOrders)) {
            $this->addLog('Targets not found in Database!!', $this->logFile);
            return [
                'success' => true,
                'data' => ['source_orders' => $sourceOrders[0], 'target_orders' => []]
            ];
        }

        return [
            'success' => true,
            'data' => ['source_orders' => $sourceOrders[0], 'target_orders' => $targetOrders]
        ];
    }

    /**
     * to store the object type and cif order id
     *
     * @param array $orderData
     */
    public function getObjectTypeAndCifId($orderData): bool
    {
        foreach ($orderData as $value) {
            if (!isset($value['object_type']) || !isset($value['cif_order_id'])) {
                return false;
            }

            $this->sourceOrderObjectType =  $value['object_type'];
            $this->cif_order_id =  $value['cif_order_id'];
        }

        return true;
    }

    /**
     * to alter object_type
     */
    public function alterObjectType(string $object_type): string
    {
        $type = "";
        switch ($object_type) {
            case "source_order":
                $type = "target_order";
                break;
            case "target_order":
                $type = "source_order";
                break;
        }

        return $type;
    }

    /**
     * to check duplicacy of cancel orders in order_container
     *
     * @param  array $cancelOrderData
     * @param string $object_type
     * @return array
     */
    public function checkDuplicacy($cancelOrderData, $object_type, $parentSourceOrder)
    {
        $orderCancelService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\CancelInterface::class);
        $duplicateOrders = $orderCancelService->findFromDb(["user_id" => $this->di->getUser()->id, "object_type" => $object_type, "marketplace_reference_id" => $cancelOrderData['marketplace_reference_id']]);
        if (isset($parentSourceOrder['cancellation_status']) && ($parentSourceOrder['cancellation_status'] == 'full')) {
            return [
                'success' => false,
                'message' => 'Order is already cancelled'
            ];
        }

        if (empty($duplicateOrders)) {
            return [
                'success' => true,
                'message' => 'No duplicacy'
            ];
        }

        if (count($duplicateOrders) > 0) {
            $duplicate = false;
            $duplicateItemIds = [];
            foreach ($duplicateOrders as $order) {
                $count = 0;
                $item_ids = [];
                $duplicateOrderItems = $order['items'];
                $cancelItems = $cancelOrderData['items'];
                foreach ($duplicateOrderItems as $item) {
                    foreach ($cancelItems as $cancelItem) {
                        //sku matching has been removed with marketplace_item_id
                        //if (($item["sku"] == $cancelItem["sku"]) && ((int)$item["qty"] == (int)$cancelItem["qty"])) {
                        if (((string)$item["marketplace_item_id"] ==(string) $cancelItem["marketplace_item_id"]) && ((int)$item["qty"] == (int)$cancelItem["qty"])) {
                            $count += 1;
                            array_push($item_ids, $cancelItem["marketplace_item_id"]);
                        }
                    }
                }

                if (count($cancelItems) == $count) {
                    $duplicate = true;
                } elseif($count > 0) {
                    array_push($duplicateItemIds, $item_ids);
                }
            }

            if ($duplicate) {
                return [
                    'success' => false,
                    'message' => 'Duplicate Cancellation'
                ];
            }

            if (!empty($duplicateItemIds)) {
                $cancelOrderData = $this->removeDuplicateData($duplicateItemIds, $cancelOrderData);
                if(!empty($parentSourceOrder['items'])) {
                    foreach($parentSourceOrder['items'] as $parentItem) {
                        foreach($cancelOrderData['items'] as $k => $cancelItem) {
                            //sku matching has been removed with marketplace_item_id
                            //if(($parentItem['sku'] == $cancelItem['sku']) && ($parentItem['qty'] < $cancelItem['qty'])) {
                            if(((string)$parentItem['marketplace_item_id'] == (string)$cancelItem['marketplace_item_id']) && ($parentItem['qty'] < $cancelItem['qty'])) {
                                unset($cancelOrderData['items'][$k]);
                            }

                            if(((string)$parentItem['marketplace_item_id'] == (string)$cancelItem['marketplace_item_id']) && isset($parentItem['cancelled_qty']) && (((int)$parentItem['qty'] - (int)$parentItem['cancelled_qty']) < $cancelItem['qty'])) {
                                unset($cancelOrderData['items'][$k]);
                            }
                        }
                    }

                    $cancelOrderData['items'] = array_values($cancelOrderData['items']);
                }

                return [
                    'success' => true,
                    'message' => 'Some duplicacy exist',
                    'data'=> $cancelOrderData
                ];
            }
            return [
                'success' => true,
                'message' => 'No Duplicacy'
            ];
        }
    }

    public function removeDuplicateData($duplicateItemIds, $cancelOrderData)
    {
        $ids = [];
        foreach($duplicateItemIds as $duplicateItemId) {
            foreach($duplicateItemId as $id) {
                $ids[] = $id;
            }
        }

        foreach($cancelOrderData['items'] as $k => $item) {
            foreach($ids as $id) {
                // if($id == $item['sku']) {
                //     unset($cancelOrderData['items'][$k]);
                // }
                if((string)$id == (string)$item['marketplace_item_id']) {
                    unset($cancelOrderData['items'][$k]);
                }
            }
        }

        $cancelOrderData['items'] = array_values($cancelOrderData['items']);
        return $cancelOrderData;
    }

    /**
     * to check settings, and compare and update target orders items that need to be cancel and perform seggregation
     *
     * @param array $targets
     * @param array $source_cancellation
     * @return array
     */
    public function checkPartialAndDataSeggregation($targets, $source_cancellation, $source)
    {
        $cancellableTargetData = [];
        foreach ($targets as $target) {
            $targetModuleCancelData = $target;
            $errors = [];
            $targetReasons = [];
            $isPartial = false;
            $itemCount = 0;

            //checking if service exist at module or not
            $module = $this->validateAndGetTargetModuleObject($target['marketplace']);
            if ($module['success'] === false) {
                $this->addLog('Service not found for marketplace: ' . $target['marketplace'], $this->logFile);
                continue;
            }

            $targetModuleObject = $module['data'];

            //getting order settings
            $syncEnable = $this->checkSyncEnable($target['marketplace_shop_id'], $source_cancellation['marketplace_shop_id']);
            if (!$syncEnable['success']) {
                $this->addLog('Order Settings disabled for marketplace: ' . $target['marketplace'], $this->logFile);
                $targetModuleCancelData['settings'] = false;
            }
            $orderSettings = $syncEnable['data'];

            $targetModuleCancelData['settings'] = $syncEnable['data'];
            $defaultReasonMap = isset($orderSettings['default_cancel_reason_enabled']['value']) ? $orderSettings['default_cancel_reason_enabled']['value'] : false;
            //validateForCancellation is a must function where the service needs to check for the particular cancellation settings and return back the data by setting a key ['settings'] either true or false depending upon the settings
            $targetModuleCancelData = $targetModuleObject->validateForCancellation($targetModuleCancelData);
            foreach ($targetModuleCancelData['items'] as $index => $item) {
                $itemExists = false;
                $itemQtyEqual = false;
                $matchedItem = []; //to store the matching item
                //matching a single item with all items of source_cancel
                foreach ($source_cancellation['items'] as $cancelItem) {
                    if (isset($item['item_link_id'], $cancelItem['item_link_id']) && ($item['item_link_id'] == $cancelItem['item_link_id'])) {
                        $itemExists = true;
                        $matchedItem = $cancelItem;
                    } elseif ($item['sku'] == $cancelItem['sku']) {
                        $itemExists = true;
                        $matchedItem = $cancelItem;
                    } else {
                        $product = $this->getProductSku($source_cancellation, $target, $cancelItem['sku']);
                        if ($product['success']) {
                            $productData = $product['data'];
                            if ($productData['sku'] == $item['sku']) {
                                $itemExists = true;
                                $matchedItem = $cancelItem;
                            }
                        }
                    }

                    //a temporary solution for the  items having null skus or no skus
                    if(empty($item['sku']) || empty($cancelItem['sku']) && !$itemExists) {
                        if(isset($item['title']) && isset($cancelItem['title']) && ($cancelItem['title'] == $item['title'])) {
                            $itemExists = true;
                            $matchedItem = $cancelItem;
                        }
                    }
                }

                if (!empty($matchedItem)) {                                                
                    //to set cancel_reason item wise
                    if (isset($matchedItem['cancel_reason'])) {
                        // getCancelReason is a  function where the service needs to map the reason according to their marketplace reasons
                        $itemReason = $targetModuleObject->getCancelReason($matchedItem['cancel_reason'], $source_cancellation['marketplace']);
                        if (!$itemReason['success']) {
                            if ($defaultReasonMap && !empty($orderSettings['default_cancel_reason']['value'])) {
                                $targetModuleCancelData['items'][$index]['cancel_reason'] = $orderSettings['default_cancel_reason']['value'];
                                $targetReasons[] = $orderSettings['default_cancel_reason']['value'];
                            } else {
                                $errors[] = $itemReason['message'];
                            }
                        } else {
                            $targetModuleCancelData['items'][$index]['cancel_reason'] = $itemReason['data'];
                            $targetReasons[] = $itemReason['data'];
                        }
                    }

                    if ((int)$item['qty'] == (int)$matchedItem['qty']) {
                        $itemQtyEqual = true;
                    }

                    $targetModuleCancelData['items'][$index]['qty'] = (int)$matchedItem['qty'];
                }

                if ($itemExists === false) {
                    unset($targetModuleCancelData['items'][$index]);
                    continue;
                }

                if ($itemQtyEqual === false) {
                    $isPartial = true;
                }

                $itemCount += 1;
            }

            //to check if we have any items to cancel for a particular target marketplace or not
            if(!empty($targetModuleCancelData['items'])) {
                $targetModuleCancelData['items'] = array_values($targetModuleCancelData['items']);
                $targetModuleCancelData = $this->updateCancellationAmount($targetModuleCancelData);
            } else {
                $cancellableTargetData[$target['marketplace_shop_id']] = $targetModuleCancelData;
                continue;
            }

            //to set global cancel_reason if available
            if (isset($source_cancellation['cancel_reason'])) {
                $reason = $targetModuleObject->getCancelReason($source_cancellation['cancel_reason'], $source_cancellation['marketplace']);
                if (!$reason['success']) {
                    if ($defaultReasonMap && !empty($orderSettings['default_cancel_reason']['value'])) {
                        $targetModuleCancelData['cancel_reason'] = $orderSettings['default_cancel_reason']['value'];
                    } else {
                        $errors[] = $reason['message'];
                    }
                } else {
                    $targetModuleCancelData['cancel_reason'] = $reason['data'];
                }
            } else {
                //if we don't have any global reason but we have item reasons that are same then setting global_reason
                if (!empty($targetReasons) && (count(array_unique($targetReasons)) == 1)) {
                    $targetModuleCancelData['cancel_reason'] = $targetReasons[0];
                }

                // else {
                //     $targetModuleCancelData['cancel_reason'] = 'Multiple reasons';
                // }//if we want to make cancel_reason mandatory
            }

            //if our order is partially cancellable
            if ((count($targetModuleCancelData['items']) !== 0 && (count($target['items']) !== count($targetModuleCancelData['items']))) || $isPartial) {
                $isPartial = true;
                if ($targetModuleObject->isPartialCancelAllowed() === false) {
                    //this is done to give this reason the priority in failure reasons
                    array_unshift($errors, 'Partial Cancellation not supported by ' . ucfirst($target['marketplace']));
                }
            }

            $targetModuleCancelData['status'] = $this->getStatusOfCancellation($targetModuleCancelData, $target);
            $targetModuleCancelData['settings_data'] = $orderSettings;

            //if errors exist like cancel reason not mapped and order is partial, then sending an email to user to notify that cancellation failed
            if (!empty($errors) && isset($targetModuleCancelData['settings']) && ($targetModuleCancelData['settings'] === true)) {
                $targetModuleCancelData['errors'] = array_values(array_unique($errors));
                $emailData = [
                    'marketplace_shop_id' => $targetModuleCancelData['marketplace_shop_id'],
                    'target_marketplace' => ucfirst($targetModuleCancelData['marketplace']),
                    'target_order_id' => $targetModuleCancelData['marketplace_reference_id'],
                    'source_marketplace' => ucfirst($source_cancellation['marketplace']),
                    'source_order_id' => $source_cancellation['marketplace_reference_id'],
                    'reasons' => $targetModuleCancelData['errors'],
                    'target_shop_id' => $source_cancellation['marketplace_shop_id'],
                    'source_shop_id' => $targetModuleCancelData['marketplace_shop_id'],
                ];
                $notifyResponse = $this->notifyMerchantForFailure($emailData);
                $this->errors = [$target['marketplace'] . '_' . $target['marketplace_shop_id'] => $targetModuleCancelData['errors']];
            }

            $targetModuleCancelData['isPartial'] = $isPartial;
            $cancellableTargetData[$target['marketplace_shop_id']] = $targetModuleCancelData;
        }

        if (!empty($cancellableTargetData)) {
            return [
                'success' => true,
                'data' => $cancellableTargetData
            ];
        }

        return [
            'success' => false,
            'message' => 'No data for cancellation'
        ];
    }

    /**
     * to get items and their quantities that are left for cancellation
     *
     * @param array $target
     * @param array $source
     * @return array
     */
    // public function getUncancelledItems($target, $source)
    // {
    //     $targetItems = $target['items'];
    //     $sourceItems = $source['items'];
    //     $uncancelledSourceItems = $sourceItems;
    //     $uncancelledTargetItems = $targetItems;
    //     foreach ($sourceItems as $key1 => $sourceItem) {
    //         $itemExist = false;
    //         foreach ($targetItems as $key2 => $targetItem) {
    //             if ($targetItem['sku'] == $sourceItem['sku']) {
    //                 $itemExist = true;
    //                 if (isset($targetItem['cancelled_qty'])) {
    //                     $uncancelledTargetItems[$key2]['left_qty'] = ((int)$targetItem['qty'] - (int)$targetItem['cancelled_qty']);
    //                 }
    //                 if (isset($sourceItem['cancelled_qty'])) {
    //                     $uncancelledSourceItems[$key1]['left_qty'] = ((int)$sourceItem['qty'] - (int)$sourceItem['cancelled_qty']);
    //                 }
    //             } else {
    //                 $product = $this->getProductSku($source, $target, $sourceItem['sku']);
    //                 if ($product['success']) {
    //                     $productData = $product['data'];
    //                     if ($productData['sku'] == $targetItem['sku']) {
    //                         $itemExist = true;
    //                         if (isset($targetItem['cancelled_qty'])) {
    //                             $uncancelledTargetItems[$key2]['left_qty'] = ((int)$targetItem['qty'] - (int)$targetItem['cancelled_qty']);
    //                         }
    //                         if (isset($sourceItem['cancelled_qty'])) {
    //                             $uncancelledSourceItems[$key1]['left_qty'] = ((int)$sourceItem['qty'] - (int)$sourceItem['cancelled_qty']);
    //                         }
    //                     }
    //                 }
    //             }
    //         }
    //         if ($itemExist == false) {
    //             unset($uncancelledSourceItems[$key1]);
    //         }
    //     }
    //     return ['uncancelledSourceItems' => $uncancelledSourceItems, 'uncancelledTargetItems' => $uncancelledTargetItems];
    // }

    // public function getMatch($leftItems, $sourceCancelData)
    // {
    //     $uncancelledSourceItems = $leftItems['uncancelledSourceItems'];
    //     $cancelItems = $sourceCancelData['items'];
    //     $count = 0;
    //     foreach ($uncancelledSourceItems as $uncancelledItem) {
    //         foreach ($cancelItems as $cancelItem) {
    //             if (($cancelItem['sku'] == $uncancelledItem['sku'])) {
    //                 if (isset($uncancelledItem['left_qty']) && ($cancelItem['qty'] == $uncancelledItem['left_qty'])) {
    //                     $count++;
    //                 }
    //             }
    //         }
    //     }
    //     if ($count) {
    //         if (count($cancelItems) == $count) {
    //             $status = 'full';
    //         } else {
    //             $status = 'partial';
    //         }
    //     } else {
    //         $status = 'none';
    //     }
    //     return $status;
    // }

    /**
     * to get sku in case of different source and target skus
     *
     * @param string $source
     * @param string $target
     * @param string $sku
     * @return void
     */
    public function getProductSku($source, $target, $sku)
    {
        $productBySkuObj = $this->di->getObjectManager()->create(\App\Connector\Models\Product\Marketplace::class);
        $skudata = [
            "sku" => $sku,
            "source_shop_id" => $source['marketplace_shop_id'],
            "target_shop_id" => $target['marketplace_shop_id'],
        ];
        $product = $productBySkuObj->getProductBySku($skudata);
        if (empty($product)) {
            return [
                'success' => false,
                'data' => ['sku'=>$sku]
            ];
        }

        return [
            'success' => true,
            'data' => $product
        ];
    }

    public function notifyMerchantForFailure($data)
    {
        $helperObj  = $this->di->getObjectManager()->get('App\Connector\Components\Order\Helper');
        $canSendMail = $helperObj->settingEnabledForMailSend($data['source_shop_id'], $data['target_shop_id'], 'email_on_failed_cancellation');
        if($canSendMail) {
            $shop = $this->getShopData($data['marketplace_shop_id']);
            $user_details = $this->di->getUser()->getConfig();
            $path = 'connector' . DS . 'views' . DS . 'email' . DS . 'Cancel-order.volt';
            $data['email'] = $user_details['source_email'] ?? ($user_details['email'] ?? "");
            $bccs[] = !empty($user_details['alternate_email']) ? $user_details['alternate_email'] : "";
            $data['bccs'] = $bccs;
            $data['username'] = isset($shop['name']) ? $shop['name'] : "there";
            $data['name'] = isset($user_details['name']) ? $user_details['name'] : "there";
            $data['path'] = $path;
            $data['app_name'] = $this->di->getConfig()->app_name;
            $data['subject'] = 'Cancel Order Sync Failure - ' . $data['app_name'];
            $data['has_unsubscribe_link'] = false;
            $data['user_id'] = $this->di->getUser()->id;
            $linkdata = [
                'user_id' => $this->di->getUser()->id,
                'app_tag' => $this->di->getAppCode()->getAppTag() ?? 'default',
                'source_shop_id' => $data['source_shop_id'],
                'setting_name' => 'email_on_failed_cancellation',
                'target_shop_id' => $data['target_shop_id'],
                'source' => strtolower($data['target_marketplace']),
                'target' => strtolower($data['source_marketplace'])
            ];
            $linkResponse = $helperObj->getUnsubscribeLink($linkdata);
            $gridlinkRes = $helperObj->getGridLink($data, $this->di->getAppCode()->getAppTag() ?? 'default', 'cancel');
            $data['has_order_grid_link'] = false;
            if($gridlinkRes) {
                $data['order_grid_link'] = $gridlinkRes;
                $data['has_order_grid_link'] = true;
            }

            if($linkResponse['success']) {
                $data['has_unsubscribe_link'] = true;
                $data['unsubscribe_link'] = $linkResponse['data'];
            }

            try {
                $mailRes = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($data);
                $eventsManager = $this->di->getEventsManager();
                $eventsManager->fire('application:orderCancelFail', $this, $data);
                return $mailRes;
            } catch (Exception $e) {
                $err = $e->getMessage();
                $this->errors[] = 'Email sent error! => '. json_encode($err, true);
            }

            return false;
        }
    }


    /**
     * to get the shop data
     *
     * @param string $shop_id
     * @return object
     */
    public function getShopData($shop_id)
    {
        $userShops = $this->di->getUser()->shops;
        if (empty($userShops)) {
            return [];
        }

        foreach ($userShops as $shop) {
            if ($shop['_id'] == $shop_id) {
                return $shop;
            }
        }

        return [];
    }


    /**
     * To perform updates at source
     *
     * @param array $parentSourceOrder
     * @param array $sourceCancelData
     * @return array
     */
    public function performParentUpdations($parentData, $cancelData, $status)
    {
        $errors = [];
        $orderCancelService = $this->getCancelService();
        $orderStatus = $this->di->getObjectManager()->get('\App\Connector\Components\Order\OrderStatus');
        $updatedKeys = [];
        isset($cancelData["marketplace_status"]) && $updatedKeys["marketplace_status"] = $cancelData["marketplace_status"];
        isset($cancelData["cancel_reason"]) && $updatedKeys["cancel_reason"] = $cancelData["cancel_reason"];
        isset($cancelData["source_updated_at"])  && $updatedKeys['source_updated_at'] = $cancelData['source_updated_at'];

        if (!empty($cancelData['cancellation_inprogress'])) {
            $updatedKeys['cancellation_inprogress'] = $cancelData['cancellation_inprogress'];
        }

        $statusResponse = $orderCancelService->updateCancellationStatusAndQty($parentData, $cancelData, $status, $updatedKeys);
        if (!$statusResponse['success']) {
            $errors[] = 'Updation failed at parent order. ' . $statusResponse['message'];
        } else {
            $updatedParent = $statusResponse['data'];
            $parentStatus = $this->getUpdatedParentStatus($updatedParent);
            $updatedKeys = [];
            if (isset($updatedParent['status'])) {
                //we cannot update parent order's status normally, first we need to validate is it possible to change status or not for parent order
                if ($orderStatus->validateStatus($updatedParent['status'], $this->getStatus($parentStatus))) {
                    $updatedKeys['status'] = $this->getStatus($parentStatus);
                }
            } else {
                $updatedKeys['status'] = $this->getStatus($parentStatus);
            }

            $updatedKeys['cancellation_status'] = $parentStatus;
            $statusUpdateResponse = $orderCancelService->update(['_id' => $updatedParent['_id']], $updatedKeys);
            if (!$statusUpdateResponse['success']) {
                $errors[] = 'Updation failed at parent order. ' . $statusUpdateResponse['message'];
            } else {
                $updatedParent = $statusUpdateResponse['data'];
            }

            //updating at opposite order's targets
            if (isset($updatedParent['targets'])) {
                foreach ($updatedParent['targets'] as $target) {
                    if (isset($target['order_id'])) {
                        $params = ["user_id" => $this->di->getUser()->id, 'object_type' => $this->alterObjectType($updatedParent['object_type']), 'marketplace_reference_id' => $target['order_id']];
                        $parentTarget = $orderCancelService->findOneFromDb($params);
                        if (!empty($parentTarget)) {
                            $query = [];
                            if (isset($parentTarget['targets'])) {
                                foreach ($parentTarget['targets'] as $k => $sourceTarget) {
                                    if (isset($sourceTarget['order_id']) && ($sourceTarget['order_id'] == $updatedParent['marketplace_reference_id'])) {
                                        $query = ['targets.' . $k . '.status' => $updatedParent['status']];
                                    }
                                }
                            }

                            if (!empty($query)) {
                                $orderCancelService->update(['_id' => $parentTarget['_id']], $query);
                            } else {
                                $errors[] = 'Unable to update opposite order\'s targets';
                            }
                        } else {
                            $errors[] = 'Alter order not found to update';
                        }
                    }
                }
            }
        }

        $updatedSourceData = $orderCancelService->findOneFromDb(['_id' => $parentData['_id']]);
        if (!empty($errors)) {
            return [
                'success' => false,
                'message' => $errors,
                'data' => $updatedSourceData
            ];
        }

        return [
            'success' => true,
            'data' => $updatedSourceData
        ];
    }

    public function getUpdatedParentStatus($order)
    {
        $type = 'full';
        if(isset($order['items'])) {
            $total_qty = 0;
            $total_cancel_qty = 0;
            foreach($order['items'] as $item) {
                $total_qty += (int)$item['qty'];
                $total_cancel_qty += (int)$item['cancelled_qty'] ?? 0;
            }
        }

        if($total_qty == $total_cancel_qty) {
            $type = 'full';
        }

        if($total_qty > $total_cancel_qty) {
            $type = 'partial';
        }

        return $type;
    }

    /**
     * to get the global status for source order
     *
     * @param array $segregatedData
     * @return string
     */
    public function setGlobalStatus($segregatedData, $sourceCancelData, $parentSourceOrder)
    {
        $this->globalStatus = "full";
        foreach ($segregatedData as $data) {
            if ($data['isPartial']) {
                $this->globalStatus = 'partial';
            }
        }

        //$this->globalStatus = $this->calcQtyStatus($parentSourceOrder, $sourceCancelData);

        // foreach($sourceCancelData['items'] as $cancelItem) {
        //     foreach($parentSourceOrder['items'] as $sourceItem) {
        //         if(($sourceItem['sku'] == $cancelItem['sku']) && isset($sourceItem['cancelled_qty'])) {

        //         }
        //     }
        // }
        // if(isset($parentSourceOrder['status'])) {
        //     switch ($parentSourceOrder['status']) 
        //     {
        //         case 'Cancelled' :  $this->globalStatus = "none";
        //                             break;
        //         case 'Partially Cancelled' : if(!empty($uncancelledItems)) {
        //             $status = $this->getMatch($uncancelledItems, $parentSourceOrder);
        //             switch ($status)
        //             {
        //                 case 'partial': $this->globalStatus = "partial";
        //                             break;
        //                 case 'full': $this->globalStatus = "full";
        //                             break;
        //             }
        //         }
        //         break;
        //     }
        // }
        return $this->globalStatus;
    }

    /**
     * to get data for target
     *
     * @param array $data
     * @return array
     */
    public function getTargetData($data)
    {
        $dummyData['marketplace'] = $data['marketplace'];
        $dummyData['marketplace_reference_id'] = $data['marketplace_reference_id'];
        $dummyData['marketplace_shop_id'] = $data['marketplace_shop_id'];
        return $dummyData;
    }

    /**
     * for unsetting unwanted keys from an array
     *
     * @param array $data
     * @return array
     */
    public function unsetUnwantedKeys($data, $keys)
    {
        foreach ($keys as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    /**
     * Function to create target cancelled order in case of failure
     *
     * @param array $data
     * @param array $sourceCancelData
     * @param string $sourceCancelObjectType
     * @return array
     */
    public function handleFailure($data, $sourceCancelData, $sourceCancelObjectType, $updatedParentSourceOrder)
    {
        $errors = [];
        $targets = [];

        $orderCancelService = $this->getCancelService();
        $data['marketplace_currency'] = isset($data['currency']) ?  $data['currency'] : 'USD'; //to stop conversion of price
        $data = $this->unsetUnwantedKeys($data, [
            '_id',
            'object_type',
            'status',
            'isPartial',
            'source_shop_id',
            'target_shop_id',
            'cancel_reason',
            'currency',
            'source_marketplace',
            'cancellation_status',
            'cancel_failed_reason',
            'settings',
            'shop_id'
        ]); //these are written to unset keys if any exist, that ar not part of schema

        $orderCancelService->toggleCancellationObjectType($sourceCancelObjectType);

        $data['status'] = $this->getStatus('failed');
        $data['reference_id'] = (string)$sourceCancelData['_id'];
        $data['targets'] = array($this->createTargetData($sourceCancelData));

        $response = $orderCancelService->create($data);
        if (!$response['success']) {
            $errors[] = $response['message'];
        } else {
            $updatedData = $orderCancelService->unsetData(['_id' => $response['data']['_id']], ['process_inprogess' => $response['data']['process_inprogess']]);
        }

        //finding target order to update cancellation status and cancel fail reason
        $params = [
            "user_id" => $this->di->getUser()->id,
            '$or' => [
                ['object_type' => 'source_order'],
                ['object_type' => 'target_order']
            ],
            'marketplace_reference_id' => $data['marketplace_reference_id']
        ];
        $updatedKeys = [];
        //discussion required for this key - can be change in future versions

        //to store targets for source cancel data
        if (isset($data['errors'])) {
            if (!is_array($data['errors'])) {
                $data['errors'][0] = $data['errors'];
            }

            array_push($targets, $this->createTargetData($data, $data['errors']));
        } else {
            array_push($targets, $this->createTargetData($data));
        }

        $updatedKeys['cancellation_status'] = 'failed';
        $updatedKeys['cancel_failed_reason'] = isset($data['errors']) ?  $data['errors'] : ['Cancel Fail'];

        //updating targets and reasons
        $targetStatusRes = $orderCancelService->update($params, $updatedKeys);
        if (!$targetStatusRes['success']) {
            $errors[] = 'Update of cancellation_status and cancel_failed_reason fails at target: '. json_encode($targetStatusRes);
        }

        if(!empty($errors)) {
            return [
                'success' => false,
                'data' => $targets,
                'errors' => $errors
            ];
        }

        return [
            'success' => true,
            'data' => $targets
        ];
    }

    /**
     * to modify status
     *
     * @param string $status
     * @return string
     */
    public function getStatus($status)
    {
        switch ($status) {
            case 'partial':
                $updatedStatus = 'Partially Cancelled';
                break;
            case 'full':
                $updatedStatus = 'Cancelled';
                break;
            case 'failed':
                $updatedStatus = 'Failed';
                break;
            case 'Disabled':
                $updatedStatus = 'Disabled';
                break;
            default:
                $updatedStatus = "";
        }

        return $updatedStatus;
    }

    // public function calcQtyStatus($parentData, $currentData)
    // {
    //     $status = 'full';
    //     $cancelled_qty = 0;
    //     $qty = 0;
    //     $cancel_qty = 0;

    //     foreach ($parentData['items'] as $item) {
    //         if (isset($item['cancelled_qty'])) {
    //             $cancelled_qty = $cancelled_qty + $item['cancelled_qty'];
    //         }
    //         if (isset($item['qty'])) {
    //             $qty = $qty + $item['qty'];
    //         }
    //     }
    //     foreach ($currentData['items'] as $cancelItem) {
    //         if (isset($cancelItem['qty'])) {
    //             $cancel_qty += $cancelItem['qty'];
    //         }
    //     }

    //     if ((int)($qty - $cancelled_qty) === (int)$cancel_qty) {
    //         $status = 'full';
    //     } else {
    //         $status = 'partial';
    //     }
    //     return $status;
    // }

    /**
     * Function to create targets data
     *
     * @param  array $data
     * @param string $errors
     * @return array
     */
    public function createTargetData($data, $errors = null)
    {
        $targets = [];
        $targets['marketplace'] = isset($data['marketplace']) ? $data['marketplace'] : "";
        $targets['shop_id'] = isset($data['marketplace_shop_id']) ? $data['marketplace_shop_id'] : "";
        $targets['order_id'] = isset($data['marketplace_reference_id']) ? $data['marketplace_reference_id'] : "";
        $targets['warehouse_id'] = isset($data['marketplace_warehouse_id']) ? $data['marketplace_warehouse_id'] : "";
        $targets['status'] = isset($data['status']) ? $data['status'] : "";
        if (!is_null($errors)) {
            if (is_array($errors)) {
                $targets['error'] = $errors;
            } else {
                $targets['error'] = array($errors);
            }
        }

        return $targets;
    }


    public function updateCancellationAmount($targetModuleCancelData)
    {
        $tax = 0;
        $marketplace_tax = 0;
        $price = 0;
        $marketplace_price = 0;
        $discount = 0;
        $marketplace_discount = 0;
        $shipping = 0;
        $marketplace_shipping = 0;
        foreach ($targetModuleCancelData['items'] as $item) {
            if (isset($item['tax'])) {
                $tax += (float)$item['tax']['price'];
                $marketplace_tax += (float)$item['tax']['marketplace_price'];
            }

            if (isset($item['discount'])) {
                $discount += (float)$item['discount']['price'];
                $marketplace_discount += (float)$item['discount']['marketplace_price'];
            }

            if (isset($item['shipping_charge'])) {
                $shipping += (float)$item['shipping_charge']['price'];
                $marketplace_shipping += (float)$item['shipping_charge']['marketplace_price'];
            }

            if (isset($item['price'])) {
                if (is_array($item['price']) && isset($item['price']['price'])) {
                    $price += (float)$item['price']['price'];
                    $marketplace_price += (float)$item['price']['marketplace_price'];
                } elseif (isset($item['price']) && isset($item['marketplace_price'])) {
                    $price += (float)$item['price'];
                    $marketplace_price += (float)$item['marketplace_price'];
                }
            }
        }

        $cancel_amount = ($price + $tax + $shipping) - $discount;
        $cancel_marketplace_amount = ($marketplace_tax + $marketplace_price + $marketplace_shipping) - $marketplace_discount;
        $targetModuleCancelData['cancellation_amount']['price'] = $cancel_amount;
        $targetModuleCancelData['cancellation_amount']['marketplace_price'] = $cancel_marketplace_amount;
        return $targetModuleCancelData;
    }

    /**
     * to get the status info for a specific marketplace
     *
     * @param string $marketplace
     * @return string
     */
    public function getStatusOfMarketplace($marketplace)
    {
        $status = "";
        switch ($marketplace) {
            case 'shopify':
                $status = 'full';
                break;
        }

        return $status;
    }

    /**
     * to get all the failed orders
     *
     * @param [type] $params
     * @return void
     */
    public function getFailedOrders($params)
    {
        $orderService =  $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\CancelInterface::class);
        return $orderService->getAll($params);
    }

    public function getOrder($params)
    {
        $filter = [];
        $result = [];
        $userId = $this->di->getUser()->id;
        if(isset($params['marketplace_reference_id'])) {
            $filter = [
                'user_id' => $userId,
                '$or' => [
                        ['object_type' => 'source_order'],
                        ['object_type' => 'target_order']
                ],
                'marketplace_reference_id' => $params['marketplace_reference_id']
            ];
            isset($params['marketplace']) && $filter['marketplace'] = $params['marketplace'];
            isset($params['marketplace_shop_id']) && $filter['marketplace_shop_id'] = $params['marketplace_shop_id'];
        } elseif (isset($params['id'])) {
            $filter = ['_id' => new \MongoDB\BSON\ObjectId($params['id'])];
        } else {
            return [
                'success' => false,
                'message' => "Parameters required"
            ];
        }

        $orderCancelService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\CancelInterface::class);
        $targetOrder = $orderCancelService->findOneFromDb($filter);
        if(empty($targetOrder)) {
            $result = [
                'success' => false,
                'message' => "Order data not found!"
            ];
        } else {
            $sourceCancellationData = $orderCancelService->findOneFromDb(
            [
                "user_id" => $userId,
                "object_type" => "source_cancellation",
                'cif_order_id' => $targetOrder['cif_order_id']
            ]);
            if (empty($sourceCancellationData)) {
                $result = [
                    'success' => false,
                    'message' => 'Source Cancellation data not found'
                ];
            } else {
                $data = $targetOrder;
                foreach ($targetOrder['items'] as $key => $item) {
                    foreach ($sourceCancellationData['items'] as $sourceItem) {
                        if ($sourceItem['sku'] == $item['sku'] && isset($sourceItem['cancel_reason'])) {
                            $item['marketplace_cancel_reason'] = $sourceItem['cancel_reason'];
                            $data['items'][$key] = $item;
                        }
                    }
                }

                $result = [
                    'success' => true,
                    'data' => array($data)
                ];
            }
        }

        return $result;
    }

    /**
     * to check if a file exists and return its object
     *
     * @param string $marketplace
     * @param string $folder
     * @param string $file
     * @return array
     */
    public function validateAndGetTargetModuleObject($marketplace, $folder = 'Service', $file = 'OrderCancel')
    {
        $moduleHome = ucfirst($marketplace);
        $moduleClass = '\App\\' . $moduleHome . '\\' . $folder . '\\' . $file;
        if (!class_exists($moduleClass)) {
            $moduleHome = ucfirst($marketplace) . 'home';
            $moduleClass = '\App\\' . $moduleHome . '\\' . $folder . '\\' . $file;
            if (!class_exists($moduleClass)) {
                return [
                    'success' => false,
                    'message' => $moduleClass . ' not found'
                ];
            }
        }

        $sourceModuleObject = $this->di->getObjectManager()->get($moduleClass);
        return [
            'success' => true,
            'data' => $sourceModuleObject
        ];
    }


    /**
     * to create logs
     *
     * @param array $data
     * @param string $file
     * @param string $msg
     */
    public function saveInLog($data, $file, $msg = ""): void
    {
        $time = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
        $this->di->getLog()->logContent($time . PHP_EOL . $msg . PHP_EOL . '  ' . print_r($data, true), 'info', $file);
    }

    public function addLog($data, $file): void
    {
        $this->di->getLog()->logContent($data, 'info', $file);
    }

    /**
     *  to check if order settings are enabled or not
     *
     * @param string $sourceShopId
     * @param string $targetShopId
     * @return array
     */
    public function checkSyncEnable($sourceShopId, $targetShopId)
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
                        $orderSync =  true;
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

    /**
     * to resync the cancellation for those orders whose targets was not available but later created
     *
     * @param array $source_order_ids
     * @return void
     */
    public function resyncCancellation($source_order_ids)
    {
        //will not for multiple targets
        $error = [];
        foreach($source_order_ids as $source_order_id) {
            $orderCancelService = $this->getCancelService();
            $source_order = $orderCancelService->findOneFromDb([
                'user_id' => $this->di->getUser()->id,
                '$or' => [
                        ['object_type' => 'source_order'],
                        ['object_type' => 'target_order']
                ],
                'marketplace_reference_id' => $source_order_id
                ]);
            if(empty($source_order)) {
                $error[] = 'Order not found in db';
                continue;
            }

            $cancel_object_type = $orderCancelService->setCancellationObjectType($source_order['object_type']);
            $cancel_orders = $orderCancelService->findFromDb(["user_id" => $this->di->getUser()->id, 'object_type'=> $cancel_object_type, 'marketplace_reference_id' => $source_order_id]);
            if(empty($cancel_orders)) {
                $error[] = 'Cancel not available to sync!';
                continue;
            }

            //deleting all cancel objects
            foreach($cancel_orders as $cancel_order) {
                if(isset($cancel_order['targets']) && (count($cancel_order['targets']) > 1)) {
                    $error[] = 'mutiple targets';
                    continue;
                }

                $orderCancelService->delete(['_id' => $cancel_order['_id']]);
                $orderCancelService->deleteAll(['order_id' => (string)$cancel_order['_id']]);
            }

            $query = [];
            //unsetting all keys
            foreach($source_order['items'] as $k => $item) {
                if(isset($item['cancelled_qty'])) {
                    $query['items.'.$k.'.cancelled_qty'] =1;
                    $id = $orderCancelService->getMongoObjectId($item['id']);
                    $orderCancelService->unsetData(['_id' => $id], ['cancelled_qty'=>1]);
                }
            }

            $query['cancellation_status'] = 1;
            $query['cancel_reason']=1;
            $orderCancelService->unsetData(['_id' => $source_order['_id']], $query);
            $amazonData = [
                'AmazonOrderId' => $source_order['marketplace_reference_id'],
                'SellerSKU' => true
            ];
            $amazonOrderNotifications = $this->di->getObjectManager()->get('\App\Amazon\Components\Order\OrderNotifications');
            $amazonOrderNotifications->proceedCancelProcess(array($amazonData));
        }

        if(!empty($error)) {
            return [
                'success' => false,
                'message' => [
                    'errors'=> $error
                ]
                ];
        }

        return [
            'success' => true,
            'message' => 'Done'
        ];
    }

    public function updateParentOrderTargetsCancellationStatus($cifOrderId): void
    {
        $orderCancelService = $this->getCancelService();
        $params = [
            'user_id' => $this->di->getUser()->id,
            '$or' => [
                [
                    'object_type' => 'source_order'
                ],
                [
                    'object_type' => 'target_order'
                ]
            ],
            'cif_order_id' => $cifOrderId,
        ];
        $sourceOrders = [];
        $targetOrders = [];
        $sourceOrderInfo = [];
        $targetOrderInfo = [];
        $parentOrders = $orderCancelService->findFromDb($params);
        if(!empty($parentOrders)) {
            foreach($parentOrders as $parentOrder) {
                if($parentOrder['object_type'] == 'source_order') {
                    $sourceOrders[] = $parentOrder;
                    isset($parentOrder['cancellation_status']) && $sourceOrderInfo[$parentOrder['marketplace_reference_id']]['status'] = $parentOrder['cancellation_status'];
                    isset($parentOrder['cancel_failed_reason']) && $sourceOrderInfo[$parentOrder['marketplace_reference_id']]['reason'] = $parentOrder['cancel_failed_reason'];
                    isset($parentOrder['cancel_reason']) && $sourceOrderInfo[$parentOrder['marketplace_reference_id']]['reason'] = $parentOrder['cancel_reason'];
                }

                if($parentOrder['object_type'] == 'target_order') {
                    $targetOrders[] = $parentOrder;
                    isset($parentOrder['cancellation_status']) && $targetOrderInfo[$parentOrder['marketplace_reference_id']]['status'] = $parentOrder['cancellation_status'];
                    isset($parentOrder['cancel_failed_reason']) && $targetOrderInfo[$parentOrder['marketplace_reference_id']]['reason'] = $parentOrder['cancel_failed_reason'];
                    isset($parentOrder['cancel_reason']) && $targetOrderInfo[$parentOrder['marketplace_reference_id']]['reason'] = $parentOrder['cancel_reason'];
                }
            }

           $this->updateCancellationStatusForParentTargets($sourceOrders, $targetOrderInfo);
           $this->updateCancellationStatusForParentTargets($targetOrders, $sourceOrderInfo);
        }
    }

    public function updateCancellationStatusForParentTargets($parentOrders, $cancelStatusArr): void
    {
        $orderCancelService = $this->getCancelService();
        if(!empty($cancelStatusArr)) {
            foreach($parentOrders as $parentOrder) {
                $updated = false;
                if(isset($parentOrder['targets'])) {
                    foreach($parentOrder['targets'] as $k => $target) {
                        foreach($cancelStatusArr as $orderId => $targetInfo) {
                            if(isset($target['order_id']) && $target['order_id'] == $orderId) {
                                $status = $this->getStatus($targetInfo['status']);
                                if(($status == 'failed' || $status == 'Failed') && isset($targetInfo['reason'])) {
                                    $parentOrder['targets'][$k]['cancel_failed_reason'] = $targetInfo['reason'];
                                } elseif(!($status == 'failed' || $status == 'Failed') && isset($parentOrder['targets'][$k]['cancel_failed_reason'])) {
                                    unset($parentOrder['targets'][$k]['cancel_failed_reason']);
                                }

                                $parentOrder['targets'][$k]['cancellation_status'] = $status;
                                $updated = true;
                            }
                        }
                    }
                }

                if($updated) {
                    $orderCancelService->update(
                        [
                            '_id' => $parentOrder['_id']
                        ],
                        [
                            'targets' => $parentOrder['targets']
                        ]
                    );
                }
            }
        }
    }

    public function updateLinkItemIds($preparedData, $parentData)
    {
        if (isset($preparedData['items']) && isset($parentData['items'])) {
            foreach ($preparedData['items'] as $key => $cancelItem) {
                foreach ($parentData['items'] as $parentItem) {
                    if (((string)$parentItem['marketplace_item_id'] == (string)$cancelItem['marketplace_item_id']) && isset($parentItem['item_link_id'])) {
                        $preparedData['items'][$key]['item_link_id'] = $parentItem['item_link_id'];
                    }
                }
            }
        }

        return $preparedData;
    }
}
