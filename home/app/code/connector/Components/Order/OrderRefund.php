<?php

namespace App\Connector\Components\Order;

use phpDocumentor\Reflection\Types\Self_;

use function Aws\filter;

class OrderRefund extends \App\Core\Components\Base
{
    public const ORDER_CONTAINER = 'order_container';

    public const FEED_CONTAINER = 'feed_container';

    public $targets = null;

    public $orderId = null;

    public $errors = array();

    public $sources = null;

    public $initiatorsTargets = array();

    public function refund($order)
    {
        if (empty($order['data'])) {
            $this->saveInLog($order, 'errors/data-empty.log');
            return [
                'success' => false,
                'message' => 'No data provided for refund.'
            ];
        }

        $this->resetClassProperties();

        $this->tempLog($order, 'starting.log', 'refund_webhook_data');

        $functionName = isset($order['process']) &&
            $order['process'] == 'manual' ? 'manualProcess' : 'automaticProcess';

        $createSourceDataResponse = $this->$functionName($order);

        $return = $createSourceDataResponse;

        if (!empty($this->errors)) {
            $return['errors'] = $this->errors;
            if ($functionName == "manualProcess") {
                //As frontend only handles errors in string format
                $return['message'] = $this->errors[0]['error'];
                unset($return['errors']);
            }

            $this->saveInLog($this->errors, 'errors/all-errors.log');
        }

        return $return;
    }

    public function automaticProcess($order)
    {
        //preparing refund data according to cif using the source module
        $preparedDataResponse = $this->prepareRefundData($order);

        $this->tempLog($preparedDataResponse, 'automatic.log', 'prepareResponse');
        if (!$preparedDataResponse['success']) {
            if (stripos($preparedDataResponse['message'], 'target_order not found') === false) {
                $this->saveInLog(
                    $preparedDataResponse,
                    'errors/prepare-data.log'
                );
            }

            return $preparedDataResponse;
        }

        $preparedData = $preparedDataResponse['data'];

        $this->orderId = $preparedData[0]['marketplace_reference_id'];
        $this->orderWiseLogs($order, 'received data');
        $this->orderWiseLogs($preparedData, 'target preparedData');

        //creating target_refund
        $createTargetDataResponse = $this->createRefundData($preparedData);
        $this->tempLog($createTargetDataResponse, 'automatic.log', 'createTargetRefundResponse');
        $this->orderWiseLogs($createTargetDataResponse, 'createTargetRefundResponse');

        if (empty($createTargetDataResponse)) {
            $this->saveInLog(
                $this->errors + $preparedData,
                'errors/create-data.log'
            );
            return [
                'success' => false,
                'message' => $this->errors
            ];
        }

        //updating target_order & target_order_items
        $updateTargetsResponse = $this->updateTargetsAndItems($createTargetDataResponse);
        $sourceOrdersTarget = $updateTargetsResponse['data'];

        //updating targets in source_order (later get response in a var to check if operation was successful)
        $this->updateSourceOrdersTarget($sourceOrdersTarget);

        //removing refund without items
        $this->removeRefundsWithoutItems($preparedData);

        if (empty($preparedData)) {
            return [
                'success' => false,
                'message' => 'refund without items not supported'
            ];
        }

        $segregatedDataResponses = $this->getSegregatedData($createTargetDataResponse);
        $this->tempLog($segregatedDataResponses, 'automatic.log', 'segregateResponse');
        $this->orderWiseLogs($segregatedDataResponses, 'segregateResponse');

        if (empty($segregatedDataResponses)) {
            $this->saveInLog($this->errors, 'errors/segregate-data.log');
            return [
                'success' => false,
                'message' => $this->errors
            ];
        }

        //checking source module settings for refund
        $allowedSegregatedData = $this->checkSourceSettings($segregatedDataResponses);
        $this->tempLog($allowedSegregatedData, 'automatic.log', 'allowedSegregatedData');
        $this->orderWiseLogs($allowedSegregatedData, 'allowedSegregatedData');

        if (empty($allowedSegregatedData)) {
            $this->saveInLog('order_sync or order-refund setting disabled', 'info/disabled-order-refund.log');
            //success = true because it is the user intended setting
            return [
                'success' => true,
                'message' => 'order_sync or order-refund setting disabled'
            ];
        }

        //-----------------------------
        $notPreviousManualRefunds = $this->removePreviouslyManualRefunds($allowedSegregatedData);
        $this->orderWiseLogs($notPreviousManualRefunds, 'notPreviousManualRefunds');
        if (empty($notPreviousManualRefunds)) {
            $this->saveInLog($allowedSegregatedData, 'info/previously-manual-refund.log');
            return [
                'success' => false,
                'message' => 'manually refunded previously.'
            ];
        }

        $allowedSegregatedData = $notPreviousManualRefunds;


        $notUnmatchedShippingResponse = $this->notUnmatchedShipping($allowedSegregatedData);
        if (!$notUnmatchedShippingResponse['success']) {
            $matchedData = $this->removeUnmatchedShippingsData($allowedSegregatedData);
            if (empty($matchedData)) {
                $this->saveInLog($allowedSegregatedData, 'errors/incorrect-shipping.log');
                return [
                    'success' => false,
                    'message' => 'The refund amount has shipping amount greater than original'
                ];
            }

            $allowedSegregatedData = $matchedData;
        } else {
            $allowedSegregatedData = $notUnmatchedShippingResponse['data'];
        }

        //-----------------------------

        //executing refund on sources
        $processRefundResponse = $this->processRefundOnSource($allowedSegregatedData);
        $this->tempLog($processRefundResponse, 'automatic.log', 'processOnSourceResponse');
        $this->orderWiseLogs($processRefundResponse, 'processOnSourceResponse');

        //updating refundResponse data according to required cif format and values
        $updatedRefundResponse = $this->updateRefundDataWithSourceRefundResponse($processRefundResponse);
        $this->orderWiseLogs($updatedRefundResponse, 'updatedRefundResponse');

        //creating source_refund
        //passing true signifies that the data is already formatted in cif format
        $createSourceDataResponse = $this->createRefundData($updatedRefundResponse, true);
        $this->tempLog($createSourceDataResponse, 'automatic.log', 'createSourceRefundResponse');
        $this->orderWiseLogs($createSourceDataResponse, 'createSourceRefundResponse');

        if (empty($createSourceDataResponse)) {
            return [
                'success' => false,
                'message' => $this->errors
            ];
        }

        //updating source_order & source_order_items
        $updateSourceResponse = $this->updateSourceAndItems($createSourceDataResponse);
        $targetOrdersTarget = $updateSourceResponse['targetOrdersTarget'];

        //updating targets in target_order
        //this is for when process is not feed based(work in progress)
        //later get response in a var to check if operation was successful
        $this->updateTargetOrdersTarget($targetOrdersTarget);

        //updating targets in target_refund
        //later get response in a var to check if operation was successful
        $this->updateTargetsInInitiator($createSourceDataResponse);

        //returning this only for test purposes.
        //later get response in a var to check if operation was successful
        return $createSourceDataResponse;
    }

    public function manualProcess($order)
    {
        $order = isset($order[0]) ? $order : [$order];

        $this->orderId = $order[0]['data']['marketplace_reference_id'];
        $this->orderWiseLogs($order, 'received data (manual)');

        $segregatedDataResponses = $this->getSegregatedData($order);
        $this->tempLog($segregatedDataResponses, 'manual.log', 'segregateResponse');
        $this->orderWiseLogs($segregatedDataResponses, 'segregateResponse (manual)');
        if (empty($segregatedDataResponses)) {
            $this->saveInLog($this->errors, 'errors/segregate-data.log');
            return ['success' => false, 'message' => $this->errors];
        }

        //-------------------------------------------
        $notUnmatchedShippingResponse = $this->notUnmatchedShipping($segregatedDataResponses);
        if (!$notUnmatchedShippingResponse['success']) {
            return ['success' => false, 'message' => 'Some amount is more than Source order'];
        }

        $segregatedDataResponses = $notUnmatchedShippingResponse['data'];

        //adding process = manual key to override settings
        foreach ($segregatedDataResponses as $index => $response) {
            $segregatedDataResponses[$index]['process'] = 'manual';
        }

        //executing refund on sources
        $processRefundResponse = $this->processRefundOnSource($segregatedDataResponses);
        $this->tempLog($processRefundResponse, 'manual.log', 'processOnSource');
        $this->orderWiseLogs($processRefundResponse, 'processOnSourceResponse (manual)');
        foreach ($processRefundResponse as $key => $response) {
            if (!$response['success']) {
                unset($processRefundResponse[$key]);
                //Todo: $processRefundResponse[$key] is undefined as key is index where as
                //in segregatedDataResponses[key] is marketplace_shopId
                $this->errors[] = $this->prepareErrorData($segregatedDataResponses[$key]['data'], $response);
                $this->saveInLog($response, 'errors/refund-process.log');
            }
        }

        if (empty($processRefundResponse)) {
            return ['success' => false, 'message' => $this->errors];
        }

        //updating refundResponse data according to required cif format
        $updatedRefundResponse = $this->updateRefundDataWithSourceRefundResponse($processRefundResponse);
        $this->orderWiseLogs($updatedRefundResponse, 'updatedRefundResponse (manual)');

        //fetching target_order
        $targetsResponse = $this->getTargetOrder($order[0]['data']);
        if (!$targetsResponse['success']) {
            return $targetsResponse;
        }

        $this->targets = $targetsResponse['data'];


        $connectorManualRefund = $this->di->getObjectManager()
            ->get(\App\Connector\Contracts\Sales\Order\RefundInterface::class, [], 'manual');

        //preparing data according to cif_source_refund
        $cifSource = $connectorManualRefund->prepareCifSource($order[0]['data']);

        $this->orderWiseLogs($cifSource, 'cifSource (manual)');

        //creating cif_source_refund
        $createTargetDataResponse = $this->createRefundData($cifSource, true);

        $this->tempLog($createTargetDataResponse, 'manual.log', 'createCifSource');
        $this->orderWiseLogs($createTargetDataResponse, 'createCifSource (manual)');
        if (empty($createTargetDataResponse)) {
            return ['success' => false, 'message' => $this->errors];
        }

        //preparing cif_target_refund
        $cifTargets = array_map(function ($updatedRefund) use ($connectorManualRefund, $createTargetDataResponse) {
            return $connectorManualRefund->prepareCifTarget($updatedRefund, $createTargetDataResponse[0]['data']);
        }, $updatedRefundResponse);

        $this->orderWiseLogs($cifTargets, 'cifTargets (manual)');

        //creating cif_target_refund
        $createSourceDataResponse = $this->createRefundData($cifTargets, true);
        $this->tempLog($createSourceDataResponse, 'manual.log', 'createCifTarget');
        $this->orderWiseLogs($createTargetDataResponse, 'createCifTarget (manual)');


        //updating source_order & source_order_items
        $updateSourceResponse = $this->updateSourceAndItems($createSourceDataResponse);
        $targetOrdersTarget = $updateSourceResponse['targetOrdersTarget'];
        $updatedSourceOrder = $updateSourceResponse['sourceOrder'];

        if (stripos($updatedSourceOrder['refund_status'], 'Failed') === false) {
            $this->unsetRefundFailedReason($order[0]['data']);
        }

        //updating targetOrder's target
        $this->updateTargetOrdersTarget($targetOrdersTarget);

        //updating targets in target_refund
        $targetsUpdateResponse = $this->updateTargetsInInitiator($createSourceDataResponse);
        if (!$targetsUpdateResponse['success']) {
            $this->errors[] = $targetsUpdateResponse['message'];
        }

        foreach ($createSourceDataResponse as $index => $response) {
            if (!$response['success']) {
                $this->errors[] = $this->prepareErrorData(
                    $createSourceDataResponse[$index]['data'],
                    $response
                );
                unset($createSourceDataResponse[$index]['data']);
            }
        }

        if (empty($createSourceDataResponse)) {
            $this->tempLog($order, 'manual.log', 'refundInitiatedButDataNotSaved');
            return [
                'success' => false,
                'message' => 'Refund initiated, but some error in saving data', 'errors' => $this->errors
            ];
        }

        $return = ['success' => true, 'message' => 'Order refund process initiated.'];
        if (!empty($this->errors)) {
            $return['errors'] = $this->errors;
        }

        return $return;
    }

    /**
     * prepareRefundData method calls the source marketplace's (i.e. from where the data has come) prepareForDb
     * to prepare the data in CIF format.
     * @param array $order
     * @return array
     */
    public function prepareRefundData($order)
    {
        if (!isset($order['marketplace']) || !isset($order['shop_id'])) {
            return [
                'success' => false,
                'message' => 'Required parameters marketplace or shop_id missing.'
            ];
        }

        $targetMarketplace = $order['marketplace'];
        $preparedRefundData = [];

        $targetRefundService = $this->di->getObjectManager()
            ->get(\App\Connector\Contracts\Sales\Order\RefundInterface::class, [], $targetMarketplace);

        !isset($order['data'][0]) && $order['data'] = [$order['data']];

        foreach ($order['data'] as $data) {
            $data['shop_id'] = $order['shop_id'];
            $data['marketplace'] = $targetMarketplace;
            $preparedDataResponse = $targetRefundService->prepareForDb($data);
            if (!$preparedDataResponse['success']) {
                return $preparedDataResponse;
            }

            $preparedData = $preparedDataResponse['data'];
            $validateResponse = $this->validatePreparedData($preparedData, $targetMarketplace);
            if (!$validateResponse['success']) {
                return $validateResponse;
            }

            $updateResponse = $this->updatePreparedData($preparedData);
            if (!$updateResponse['success']) {
                return $updateResponse;
            }

            $preparedData = $updateResponse['data'];
            array_push($preparedRefundData, $preparedData);
        }

        return [
            'success' => true,
            'data' => $preparedRefundData
        ];
    }

    /**
     * createRefundData method calls OrderRefund service on connector to save
     * data in database
     * @param array $refundsData
     * @return array
     */
    public function createRefundData($refundsData, $isPrepared = false)
    {
        $prepareDataResponses = [];
        $connectorRefundService = $this->getConnectorRefundService();

        foreach ($refundsData as $refundData) {
            if (!isset($refundData['isPrepared'])) {
                $refundData['isPrepared'] = $isPrepared;
            }

            $prepareDataResponse = $connectorRefundService->create($refundData);
            if (!$prepareDataResponse['success']) {
                $this->errors[] = $this->prepareErrorData($refundData, $prepareDataResponse);
                continue;
            }

            array_push($prepareDataResponses, $prepareDataResponse);
        }

        return $prepareDataResponses;
    }


    public function updateTargetsAndItems($createdData)
    {
        $connectorRefundService = $this->getConnectorRefundService();

        foreach ($createdData as $data) {
            foreach ($this->targets as $target) {
                if ($target['marketplace_reference_id'] != $data['data']['marketplace_reference_id']) {
                    continue;
                }

                $response = $connectorRefundService->updateTargetsAndItems($target, $data['data']);
                if (!$response['success']) {
                    $this->errors[] = $this->prepareErrorData($target, $response);
                    continue;
                }

                $sourceOrdersTarget[] = $this->prepareDataForUpdatingParentOrder($response);
            }
        }

        return ['success' => true, 'data' => $sourceOrdersTarget];
    }

    public function getSegregatedData($createdData)
    {
        $segregatedDataResponses = [];
        foreach ($createdData as $responseData) {
            $segregatedDataResponse = $this->segregateData($responseData['data']);
            array_push($segregatedDataResponses, $segregatedDataResponse);
        }

        return $this->validateAndModifySegregatedData($segregatedDataResponses);
    }

    public function removePreviouslyManualRefunds($segregatedData)
    {
        $mongo = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        $connectorRefundService = $this->getConnectorRefundService();

        foreach ($segregatedData as $index => $data) {
            $query = [
                'user_id' => $this->di->getUser()->id,
                '$or' => [
                    ['object_type' => 'cif_target_refund'],
                    ['object_type' => 'cif_source_refund']
                ],
                'marketplace' => 'cif',
                'marketplace_reference_id' => 'cif_' . $data['marketplace_reference_id'],
            ];
            $previousManualRefund = $mongo->findOne($query);
            if (empty($previousManualRefund)) {
                continue;
            }

            $filter = [
                'user_id' => $this->di->getUser()->id,
                '$or' => [
                    ['object_type' => 'target_order'],
                    ['object_type' => 'source_order']
                ],
                'marketplace' => $data['marketplace'],
                'marketplace_shop_id' => $data['marketplace_shop_id'],
                'marketplace_reference_id' => $data['marketplace_reference_id'],
            ];
            $updateData = [
                'refund_status' => 'Failed',
                'refund_failed_reason' => 'Refund failed to sync: This order has already been manually refunded through the app. Future refunds for this order will not be automatically synced to prevent discrepancies.',
                'source_updated_at' =>  $data['source_updated_at'] ?? date('c')
            ];
            //later get response in a var to check if operation was successful
            $connectorRefundService->updateDataInDb($filter, $updateData);
            unset($segregatedData[$index]);
        }

        return $segregatedData;
    }

    public function removeRefundsWithoutItems(&$refundData): void
    {
        foreach ($refundData as $index => $data) {
            if (empty($data['items'])) {
                //later get response in a var to check if operation was successful
                $this->handleRefundWithoutItems($data);
                unset($refundData[$index]);
            }
        }
    }

    public function handleRefundWithoutItems($refundData)
    {
        $sourceOrderId = '';
        foreach ($this->targets as $target) {
            if ($target['marketplace_reference_id'] != $refundData['marketplace_reference_id']) {
                continue;
            }

            $sourceOrderId = $target['targets'][0]['order_id'] ?? '';

            if (count($target['targets']) > 1) {
                //Todo : implement getSourceIdFromAttributes() using array_map()
                foreach ($target['attributes'] as $attribute) {
                    if ($attribute['key'] == 'Source Order Id' || strpos($attribute['key'], ' Order Id') !== false) {
                        $sourceOrderId = $attribute['value'];
                    }
                }
            }
        }

        $targetIndex = array_search($sourceOrderId, array_column($target['targets'], 'order_id'));
        $sourceOrderDetails = $target['targets'][$targetIndex];

        $filter = [
            'user_id' => $this->di->getUser()->id,
            '$or' => [
                ['object_type' => 'target_order'],
                ['object_type' => 'source_order']
            ],
            'marketplace' => $sourceOrderDetails['marketplace'],
            'marketplace_shop_id' => $sourceOrderDetails['shop_id'],
            'marketplace_reference_id' => $sourceOrderId,
        ];

        $updateData = [
            'refund_status' => 'Failed',
            'refund_failed_reason' => 'Items required for Refund',
            'source_updated_at' =>  $refundData['source_updated_at'] ?? date('c')
        ];
        $connectorRefundService = $this->getConnectorRefundService();
        //later get response in a var to check if operation was successful
        $connectorRefundService->updateDataInDb($filter, $updateData);
        $this->errors[] = $this->prepareErrorData($refundData, ['message' => 'Refund without items.']);
        return [
            'success' => false,
            'message' => 'refund without items not supported'
        ];
    }


    /**
     * validatePreparedData method validates the date prepared by the marketplace
     * to ensure it contains the minimum required keys
     * @param array $preparedData
     * @param array $targetMarketplace
     * @return array
     */
    public function validatePreparedData($preparedData, $targetMarketplace)
    {
        if (!isset($preparedData['marketplace_reference_id'])) {
            $messages[] = 'Required param marketplace_reference_id missing in prepared data from ' . $targetMarketplace;
        }

        if (!isset($preparedData['marketplace_shop_id'])) {
            $messages[] = 'Required param marketplace_shop_id missing in prepared data from ' . $targetMarketplace;
        }

        if (!isset($preparedData['marketplace'])) {
            $messages[] = 'Required param marketplace missing in prepared data from ' . $targetMarketplace;
        }

        if (!empty($messages)) {
            return [
                'success' => false,
                'message' => $messages
            ];
        }

        return [
            'success' => true,
            'data' => 'prepared data is valid.'
        ];
    }

    /**
     * updatePreparedData method modifies the prepared data to add additional required parameters
     * @param array $preparedData
     * @return array
     */
    public function updatePreparedData($preparedData)
    {
        $targetsResponse = $this->getTargetOrder($preparedData);
        if (!$targetsResponse['success']) {
            return $targetsResponse;
        }

        $this->targets = $targetsResponse['data'];

        $targetOrderHasSource = $this->targetOrderHasSource();
        if (!$targetOrderHasSource['success']) {
            return $targetOrderHasSource;
        }

        $preparedData = $this->updateRefundIfTaxIsIncluded($preparedData);
        $preparedData = $this->setObjectTypeAndOrderId($preparedData);
        if (!isset($preparedData['marketplace_status'])) {
            $marketplaceStatusResponse = $this->getMarketplaceStatus($preparedData);
            if (!$marketplaceStatusResponse['success']) {
                return $marketplaceStatusResponse;
            }

            $preparedData['marketplace_status'] = $marketplaceStatusResponse['data'];
        }

        $status = $this->getCifStatus($preparedData);
        $preparedData['status'] = $status;

        $preparedData = $this->addItemMetadata($preparedData,$this->targets);

        return  [
            'success' => true,
            'data' => $preparedData
        ];
    }

    private function addItemMetadata($preparedData, $targets)
    {
        if (empty($preparedData['items'])) {
            return $preparedData;
        }

        foreach ($preparedData['items'] as $index => $item) {
            if (empty($item['marketplace_item_id'])) {
                continue;
            }

            foreach ($targets as $target) {
                if ($target['marketplace_reference_id'] != $preparedData['marketplace_reference_id']) {
                    continue;
                }

                foreach ($target['items'] as $targetItem) {
                    if ($targetItem['marketplace_item_id'] == $item['marketplace_item_id']) {
                        $preparedData['items'][$index]['item_link_id'] = $targetItem['item_link_id'] ?? '';
                        $preparedData['items'][$index]['total_qty'] = $targetItem['qty'] ?? 0;
                        $preparedData['items'][$index]['total_refunded_qty'] = $targetItem['refund_qty'] ?? 0;
                        if (!empty($targetItem['is_component'])) {
                            $preparedData['items'][$index]['is_component'] = $targetItem['is_component'];
                        }
                        //todo - if is_component - true and total_refunded_qty > 0, then needs to get previous refunds amount
                        break 2;
                    }
                }
            }
        }

        return $preparedData;
    }

    /**
     * getTargetOrder method fetches the originating document for a particular order
     * on that corresponding marketplace
     * @param array $preparedData
     * @return array
     */
    public function getTargetOrder($preparedData)
    {
        $query = [
            'user_id' => $this->di->getUser()->id,
            '$or' => [
                ['object_type' => 'target_order'],
                ['object_type' => 'source_order']
            ],
            'marketplace' => $preparedData['marketplace'],
            'marketplace_shop_id' => (string)$preparedData['marketplace_shop_id'],
            'marketplace_reference_id' => (string)$preparedData['marketplace_reference_id'],
        ];
        $targets = $this->getDataFromDb($query);
        if (count($targets) < 1) {
            $this->saveInLog($query, 'notice/target-order-not-found.log');
            return [
                'success' => false,
                'message' => 'target_order not found in database.
                    [order_id: ' . $preparedData['marketplace_reference_id'] . ']'
            ];
        }

        return [
            'success' => true,
            'data' => $targets
        ];
    }

    /**
     * getSourceOrder method fetches source_order or target_order document
     * corresponding to the originating document (response document of the originating document)
     * @param array $order
     * @return array
     */
    public function getSourceOrder($order)
    {
        $objectType = 'source_order';
        if (strpos($order['object_type'], 'source') !== false) {
            $objectType = 'target_order';
        }

        $cifOrderId = $order['cif_order_id'];

        /*
        marketplace and marketplace_shop_id cannot be added in this query because
        in case of source actually being a target, there can be multiple target orders
        having different marketplaces and shop_ids
        */
        $query = [
            'user_id' => $this->di->getUser()->id,
            'object_type' => $objectType,
            'cif_order_id' => $cifOrderId,
        ];

        $sources = $this->getDataFromDb($query);
        if (count($sources) < 1) {
            $this->saveInLog($query, 'errors/source-order-not-found.log');
            return [
                'success' => false,
                'message' => $objectType . ' not found in database.'
            ];
        }

        $this->sources = $sources;
        return [
            'success' => true,
            'data' => $sources
        ];
    }

    public function targetOrderHasSource()
    {
        $targetOrderHasSource = true;
        foreach ($this->targets as $targetOrder) {
            $targets = $targetOrder['targets'] ?? [];
            if (empty($targets)) {
                $targetOrderHasSource = false;
                break;
            }
        }

        if (!$targetOrderHasSource) {
            return [
                'success' => false,
                'message' => 'Source order has been removed. "targets" not found in target_order'
            ];
        }

        return ['success' => true];
    }

    public function updateRefundIfTaxIsIncluded($preparedData)
    {
        $isTaxIncluded = false;
        foreach ($this->targets as $target) {
            //Todo: can implement array_filter here to only get actual target
            if ($target['marketplace_reference_id'] != $preparedData['marketplace_reference_id']) {
                continue;
            }

            if (isset($target['taxes_included']) && $target['taxes_included']) {
                $isTaxIncluded = true;
            }
        }

        if ($isTaxIncluded) {
            foreach ($preparedData['items'] as $index => $item) {
                if (!isset($item['taxes'])) {
                    continue;
                }

                foreach ($item['taxes'] as $tax) {
                    if (stripos($tax['code'], 'Item') !== false) {
                        if(isset($preparedData['items'][$index]['refund_amount']) && !is_array($preparedData['items'][$index]['refund_amount'])) {
                            $preparedData['items'][$index]['refund_amount'] = ['price' => $preparedData['items'][$index]['refund_amount']];
                        }
                        $preparedData['items'][$index]['refund_amount']['price'] -= $tax['price'];
                        break;
                    }
                }
            }
        }

        //Todo: also need to check same of shippingTax as it can be enabled and disabled as well
        return $preparedData;
    }

    /**
     * setObjectTypeAndOrderId method adds object_type & cif_order_id key
     * in the preparedData
     * @param array $preparedData
     * @return array
     */
    public function setObjectTypeAndOrderId($preparedData)
    {
        foreach ($this->targets as $target) {
            if ($preparedData['marketplace_reference_id'] != $target['marketplace_reference_id']) {
                continue;
            }

            $preparedData['cif_order_id'] = $target['cif_order_id'];
            $preparedData['object_type'] = (strpos($target['object_type'], 'source') !== false)
                ? 'source_refund'
                : 'target_refund';
        }

        return $preparedData;
    }

    /**
     * getMarketplaceStatus method gets called if marketplace_status is not found in the preparedData
     * It computes whether the refund was partial or complete and returns it accordingly
     * @param array $preparedData
     * @return array
     */
    public function getMarketplaceStatus($preparedData)
    {
        if (empty($preparedData['items'])) {
            return $this->getMarketplaceStatusForEmptyRefundItems($preparedData);
        }

        $marketplaceStatus = 'partially_refunded';
        $target = [];
        foreach ($this->targets as $targetOrder) {
            if ($targetOrder['marketplace_reference_id'] == $preparedData['marketplace_reference_id']) {
                $target = $targetOrder;
                break;
            }
        }

        $refundedItemCount = 0;
        $isPartial = false;
        foreach ($target['items'] as $targetItem) {
            //validating if the item was previously refunded (partially or complete)
            if (isset($targetItem['refund_qty'])) {
                //validating if previously refund completely, if yes then comparison not required
                if ($targetItem['refund_qty'] == $targetItem['qty']) {
                    $refundedItemCount += 1;
                    continue;
                }

                $targetItem['qty'] = $targetItem['qty'] - $targetItem['refund_qty'];
            }

            foreach ($preparedData['items'] as $preparedItem) {
                if (
                    (!isset($preparedItem['sku'])
                        &&
                        !isset($preparedItem['marketplace_item_id'])
                    )
                    ||
                    empty($preparedItem['qty'])
                ) {
                    return [
                        'success' => false,
                        'message' => 'Required param sku/marketplace_item_id or qty missing.'
                    ];
                }

                if (
                    $targetItem['marketplace_item_id'] == $preparedItem['marketplace_item_id']
                    ||
                    $targetItem['sku'] == $preparedItem['sku']
                ) {
                    $refundedItemCount += 1;
                    if ($targetItem['qty'] != $preparedItem['qty']) {
                        $isPartial = true;
                    }
                }
            }
        }

        if ($refundedItemCount != count($target['items'])) {
            $isPartial = true;
        }

        if (!$isPartial) {
            $marketplaceStatus = 'refunded';
        }

        return ['success' => true, 'data' => $marketplaceStatus];
    }

    public function getMarketplaceStatusForEmptyRefundItems($refundData)
    {
        foreach ($this->targets as $target) {
            if ($target['marketplace_reference_id'] == $refundData['marketplace_reference_id']) {
                if (isset($target['refund_status'])) {
                    return ['success' => true, 'data' => $target['refund_status']];
                }

                return ['success' => true, 'data' => 'partially_refunded'];
            }
        }

        return ['success' => false, 'message' => 'Targets not found'];
    }

    /**
     * getCifStatus method decides the status (cif schema status) based on the marketplace_status
     * @param array $preparedData
     * @return array
     */
    public function getCifStatus($preparedData)
    {
        $status = 'Refund';
        if (strpos($preparedData['marketplace_status'], 'partial') !== false) {
            $status = 'Partially Refund';
        }

        return $status;
    }


    /**
     * segregatedData method segregate (or separates) the data to prepare it for each marketplace
     * @param array $data
     * @return array
     */
    public function segregateData($data)
    {
        $segregatedData = [];

        $sourceOrderResponse = $this->getSourceOrder($data);
        if (!$sourceOrderResponse['success']) {
            $this->errors[] = $this->prepareErrorData($data, $sourceOrderResponse);
            return $sourceOrderResponse;
        }

        $data['items'] = $this->groupItemsByItemLinkId($data['items']);

        $hasIncompleteComponent = $this->hasIncompleteComponent($data['items']);

        if ($hasIncompleteComponent) {
            $this->setErrorInSourceForIncompleteComponent($data, $sourceOrderResponse['data']);
            $this->errors[] = $this->prepareErrorData($data, ['message' => 'Oops! Your bundle isn’t complete. Add all items of bundle to proceed with refund']);
            return [
                'success' => false,
                'message' => 'Incomplete component found'
            ];
        }

        foreach ($sourceOrderResponse['data'] as $sourceOrder) {
            $sourceModuleData = $data;
            $isPartial = false;
            $itemExists = false;
            $refundedItemCount = 0;
            $unMatchedShippingAmount = 0;

            $sourceCurrency = $sourceOrder['marketplace_currency'];
            //not required currently but need to be updated in future version to validate that
            //currency of source and target are the same
            $sourceModuleData['marketplace_currency'] = $sourceCurrency;

            if ($sourceModuleData['marketplace_currency'] != $sourceCurrency) {
                $this->saveInLog($sourceModuleData, 'info/different-currency-refund.log');
            }

            $sourceOrder['items'] = $this->groupItemsByItemLinkId($sourceOrder['items'], true);

            // $sourceModuleData['items'] = $sourceOrder['items'];
            $sourceModuleData['items'] = [];

            foreach ($data['items'] as $index => $item) {
                $itemExists = false;
                $sourceItem = null;
                if (isset($sourceOrder['items'][$index])) {
                    $sourceItem = $sourceOrder['items'][$index];
                } else {
                    foreach ($sourceOrder['items'] as $sourceOrderItem) {
                        if ($this->isSameItem($item, $sourceOrderItem, $sourceOrder, $data)) {
                            $sourceItem = $sourceOrderItem;
                            break;
                        }
                    }
                }

                if (!empty($sourceItem)) {
                    $sourceModuleData['items'][$index] = $sourceItem;
                    if (empty($item['is_component'])) {
                        $sourceItem = $item;
                        $sourceModuleData['items'][$index] = $item;
                    }

                    $itemExists = true;
                    $refundedItemCount += 1;

                    //assigning source sku in the segregated data
                    $sourceModuleData['items'][$index]['sku'] = $sourceItem['sku'];

                    if (empty($sourceItem['refund_amount']) && !empty($item['total_refund_amount'])) {
                        $sourceModuleData['items'][$index]['refund_amount'] = $item['total_refund_amount'];
                    }

                    if (empty($sourceItem['customer_note']) && !empty($item['customer_note'])) {
                        $sourceModuleData['items'][$index]['customer_note'] = $item['customer_note'];
                    }

                    /*
                        Below code is to tackle case where the item could have been refunded from the manual process
                        on source but wasn't actually refunded on the target, and if the refund for such item
                        is requested from the target now, then to handle the case of over refunding qty for that item
                        fetching the source_order and checking if it is even available for refund or not
                        */

                    if (isset($sourceItem['refund_qty'])) {
                        if ($sourceItem['refund_qty'] == $sourceItem['qty']) {
                            unset($sourceModuleData['items'][$index]);
                            continue;
                        }

                        $sourceItem['qty'] = $sourceItem['qty'] - $sourceItem['refund_qty'];
                        if ($sourceItem['qty'] < $item['qty']) {
                            $sourceModuleData['items'][$index]['qty'] = $sourceItem['qty'];
                        }
                    }

                    if (isset($item['shipping_charge'])) {
                        if (!isset($sourceItem['shipping_charge'])) {
                            $unMatchedShippingAmount += $item['shipping_charge']['price'];
                        } elseif ($item['shipping_charge']['price'] > $sourceItem['shipping_charge']['price']) {
                            $sourceModuleData['items'][$index]['shipping_charge']['price']
                                = $sourceItem['shipping_charge']['price'];

                            $unMatchedShippingAmount =
                                $unMatchedShippingAmount
                                + $item['shipping_charge']['price']
                                - $sourceItem['shipping_charge']['price'];
                        } elseif (
                            $item['shipping_charge']['price']
                            <
                            $sourceItem['shipping_charge']['price']
                            &&
                            $unMatchedShippingAmount > 0
                        ) {
                            $sourceModuleData['items'][$index]['shipping_charge']['price']
                                = $sourceItem['shipping_charge']['price'];

                            $unMatchedShippingAmount =
                                $unMatchedShippingAmount
                                - ($sourceItem['shipping_charge']['price']
                                    - $item['shipping_charge']['price']
                                );
                        }
                    }

                    // $sourceModuleData['items'][$index]['marketplace_item_id'] = $sourceItem['marketplace_item_id'];
                    //not required currently but need to be updated in future version to validate that
                    //currency of source and target are the same
                    $sourceModuleData['items'][$index]['marketplace_currency'] = $sourceCurrency;
                    $sourceModuleData['items'][$index]['marketplace_item_id'] = $sourceOrder['items'][$index]['marketplace_item_id'];

                    if ($sourceItem['qty'] != $item['qty']) {
                        $isPartial = true;
                    }

                    //unsetting id to avoid conflict
                    unset($sourceModuleData['items'][$index]['id']);
                }

                if ($itemExists !== true) {
                    unset($sourceModuleData['items'][$index]);
                    $sourceModuleData = $this->rectifyPricesForSourceModule($sourceModuleData, $item);
                }
            }

            if (count($sourceOrder['items']) != $refundedItemCount) {
                $isPartial = true;
            }

            //for checking the order_settings in source module
            $sourceModuleData['target_shop_id'] = $data['marketplace_shop_id'];
            $sourceModuleData['target_marketplace'] = $data['marketplace'];
            $sourceModuleData['target_marketplace_shop_id'] = $data['marketplace_shop_id'];

            //for mail purpose in case of failure
            $sourceModuleData['target_marketplace_reference_id'] = $data['marketplace_reference_id'];

            $sourceModuleData['targets'][] = $this->prepareTargetsFromData($data);

            $sourceModuleData = $this->rectifyDataForSourceModule($sourceModuleData, $sourceOrder, $isPartial);
            $sourceModuleData = $this->getSourceSettings($sourceModuleData);

            $sourceModuleData['unMatchedShippingAmount'] = ($unMatchedShippingAmount > 0);

            //as marketplace can be same for two targets in some scenarios, hence concatenating it with shop_id
            $sourceModuleKey = $sourceModuleData['marketplace'] . '_' . $sourceModuleData['marketplace_shop_id'];

            $segregatedData[$sourceModuleKey] = $sourceModuleData;
        }

        if (empty($sourceModuleData['items'])) {
            $errorMessageForNoItems = ($refundedItemCount != 0)
                ? ['message' => 'all items already refunded']
                : ['message' => "SKU(s) didn't match"];

            if ($errorMessageForNoItems['message'] === "SKU(s) didn't match") {
                $this->setErrorInSource($sourceModuleData, $errorMessageForNoItems['message']);
            }

            $this->errors[] = $this->prepareErrorData($sourceModuleData, $errorMessageForNoItems);
            return [
                'success' => false,
                'data' => $segregatedData
            ];
        }

        return [
            'success' => true,
            'data' => $segregatedData
        ];
    }

    private function isSameItem($item, $sourceItem, $sourceOrder, $data)
    {
        if (isset($item['item_link_id'], $sourceItem['item_link_id'])
            && $item['item_link_id'] == $sourceItem['item_link_id']
        ) {
            return true;

        }

        // $sourceItem['sku'] == $this->getSourceSku(
        //     $data['marketplace_shop_id'],
        //     $sourceOrder['marketplace_shop_id'],
        //     $item['sku']
        // );
    }

    public function validateAndModifySegregatedData($segregatedDataResponse)
    {
        $segregatedDataResponses = [];
        foreach ($segregatedDataResponse as $index => $response) {
            if (!$response['success']) {
                unset($segregatedDataResponse[$index]);
                $this->saveInLog(
                    $response,
                    'errors/validate-segregate-data.log'
                );
                continue;
            }

            foreach ($response['data'] as $key => $value) {
                $segregatedDataResponses[$key] = $value;
            }
        }

        return $segregatedDataResponses;
    }

    public function prepareTargetsFromData($data)
    {
        return [
            'marketplace' => $data['marketplace'],
            'order_id' => $data['marketplace_reference_id'],
            'shop_id' => $data['marketplace_shop_id'],
            'status' => $data['status'],
            'refund_status' => $data['status']
        ];
    }

    public  function setErrorInSource($sourceData, $errorMessage): void
    {
        if (isset($sourceData['order_sync']) && !$sourceData['order_sync']) {
            return;
        }

        if (isset($sourceData['order_refund']) && !$sourceData['order_refund']) {
            return;
        }

        $filterQuery = [
            'user_id' => $this->di->getUser()->id,
            'object_type' => 'source_order',
            'marketplace' => $sourceData['marketplace'],
            'marketplace_shop_id' => $sourceData['marketplace_shop_id'],
            'marketplace_reference_id' => $sourceData['marketplace_reference_id']
        ];
        $updateData =    [
            'refund_status' => 'Failed',
            'refund_failed_reason' => $errorMessage,
            'source_updated_at' => $sourceData['source_updated_at'] ?? date('c')
        ];

        $connectorRefundService = $this->getConnectorRefundService();
        $connectorRefundService->updateDataInDb($filterQuery, $updateData);
    }

    public function getSourceSettings($orderData)
    {
        $sourceRefundService = $this->di->getObjectManager()
            ->get(\App\Connector\Contracts\Sales\Order\RefundInterface::class, [], $orderData['marketplace']);
        $settingResponse = $sourceRefundService->getSettings($orderData);
        $orderSync = true;
        $orderRefund = false;
        if (isset($settingResponse['success']) && $settingResponse['success']) {
            $allSettings = $settingResponse['data'];
            foreach ($allSettings as $setting) {
                if ($setting['key'] == 'order_sync') {
                    $orderSync = $setting['value'];
                }

                if ($setting['key'] == 'order_refund') {
                    $orderRefund = $setting['value'];
                }
            }
        }

        $orderData['order_sync'] = $orderSync;
        $orderData['order_refund'] = $orderRefund;
        return $orderData;
    }

    public function getOrderConfigs($orderData)
    {
        $connectorConfigModel = $this->di->getObjectManager()->get(\App\Core\Models\Config\Config::class);
        $connectorConfigModel->setGroupCode("order");
        $connectorConfigModel->setSourceShopId($orderData['target_shop_id']);
        $connectorConfigModel->setTargetShopId($orderData['marketplace_shop_id']);
        $connectorConfigModel->setAppTag(null);
        $connectorConfigModel->setUserId($this->di->getUser()->id);

        $orderSetting = $connectorConfigModel->getConfig();
        return json_decode(json_encode($orderSetting), true);
    }

    public function checkSourceSettings($refundData)
    {
        foreach ($refundData as $index => $data) {
            if ($data['order_sync'] !== true) {
                $this->saveInLog($data, 'info/disabled-order-sync.log');

                //unsetting following data as settings for this marketplace is disabled
                unset($refundData[$index]);

                //unsetting process_inprogess key as the setting is disabled for order_sync
                $this->unsetInprogressKeyForTargetRefund($data);
            } elseif ($data['order_refund'] !== true) {
                $this->saveInLog($data, 'info/disabled-order-refund.log');

                //unsetting following data as settings for this marketplace is disabled
                unset($refundData[$index]);

                //unsetting process_inprogess key as the setting is disabled for order_refund
                $this->unsetInprogressKeyForTargetRefund($data);
            } else {
                //unsetting following keys as they aren't required anymore
                unset($refundData[$index]['order_sync']);
                unset($refundData[$index]['order_refund']);
            }
        }

        return $refundData;
    }


    public function processRefundOnSource($refundsData)
    {
        $sourceRefundResponses = [];
        foreach ($refundsData as $refundData) {
            $sourceRefundService = $this->di->getObjectManager()
                ->get(\App\Connector\Contracts\Sales\Order\RefundInterface::class, [], $refundData['marketplace']);

            $sourceRefundResponse = $sourceRefundService->refund($refundData);

            if (!$sourceRefundResponse['success']) {
                $this->errors[] = $this->prepareErrorData($refundData, $sourceRefundResponse);
                $this->saveInLog($sourceRefundResponse, 'errors/refund-process.log');

                //unsetting process_inprogess key as the process failed on source_refund
                $this->unsetInprogressKeyForTargetRefund($refundData);
                $connectorRefundService = $this->getConnectorRefundService();
                $this->saveInLog('sending email', 'info/email-process.log');
                $emailResponse = $connectorRefundService->notifyForFailedRefund($sourceRefundResponse);
                $this->saveInLog($emailResponse, 'info/email-process.log');
                $this->saveInLog('process completed', 'info/email-process.log');
            }

            //Todo: handle below data, assign it all inside one variable
            //keep that variable on connector only and unset it in another function
            unset($sourceRefundResponse['data']['target_marketplace_reference_id']);
            unset($sourceRefundResponse['data']['target_marketplace']);
            unset($sourceRefundResponse['data']['target_shop_id']);
            unset($sourceRefundResponse['data']['target_marketplace_shop_id']);

            //setting isFeedBased key in response if not already set
            if (empty($sourceRefundResponse['isFeedBased'])) {
                $sourceRefundResponse['isFeedBased'] = $sourceRefundService->isFeedBased();
            }

            if ($sourceRefundResponse['isFeedBased'] !== true) {
                //case for marketplace where refund process is executed in live time instead of doing through feeds

                //temporarily to log if non feed-based refund is initiated
                $this->saveInLog($sourceRefundResponse, 'info/non-feed-based-refund.log');

                //prepareForDb for target not available in case of amazon as of yet (as it works on feed based system)
                $sourceRefundResponse['data'] = $sourceRefundService->prepareForDb($sourceRefundResponse['data']);

                //update isPrepared to false for non-feedbased refund process as prices will not be in cif format
                //but marketplace like meta are non-feed based but they also do not provide any data back
                //hence the returned data will already prepared
                $sourceRefundResponse['data']['isPrepared'] = true;
            }

            $batchProcess = $sourceRefundResponse['batch_process'] ?? false; //when processing in batch
            if ($sourceRefundResponse['success'] && $sourceRefundResponse['isFeedBased'] && !$batchProcess) {
                //checking if feed_data is missing in response
                if (!empty($sourceRefundResponse['feed_data'])) {
                    $insertResponse = $this->saveResponseFeed($sourceRefundResponse['feed_data']);
                    //if not inserted successfully in db
                    if (!$insertResponse) {
                        $this->saveInLog($sourceRefundResponse, 'errors/saving-feed-id.log');
                    }
                } else {
                    //saving log and setting error if feed_data is missing
                    $this->saveInLog($sourceRefundResponse, 'errors/feed-data-missing-in-processOnSource-response.log');
                    $this->errors[] = $this->prepareErrorData(
                        $refundData,
                        ['message' => 'feed_data is missing in processOnSource']
                    );
                }
            }

            array_push($sourceRefundResponses, $sourceRefundResponse);
        }

        return $sourceRefundResponses;
    }

    public function updateRefundDataWithSourceRefundResponse($refundResponses)
    {
        $updatedRefundResponses = [];
        foreach ($refundResponses as $refundResponse) {
            $marketplaceStatus = 'Inprogress';
            $status = $refundResponse['data']['status'] . ' Inprogress';
            if (!$refundResponse['success']) {
                $marketplaceStatus = 'Failed';
                $status = 'Failed';
                $refundResponse['data']['error'] = $refundResponse['message'];
                unset($refundResponse['error']);
                unset($refundResponse['message']);
            }

            /*
            below code is to handle cases where refund failed on source for marketplaces like meta
            because previously for such marketplaces, the status was not updated and if the process failed
            then there was conflict between the marketplace_status and the status
            */
            if ($status == 'Failed') {
                $refundResponse['data']['status'] = $status;
            }

            /*
            marketplaces like meta will be problematic here as they are not feedbased
            but also don't return any data. In this case, if the process failed on source_marketplace
            the status won't be updated due to below check
            */
            if ($refundResponse['isFeedBased'] === true) {
                $refundResponse['data']['marketplace_status'] = $marketplaceStatus;
                $refundResponse['data']['status'] = $status;
            }

            $refundData = $refundResponse['data'];
            array_push($updatedRefundResponses, $refundData);
        }

        return $updatedRefundResponses;
    }

    public function updateSourceAndItems($createdData)
    {
        $connectorRefundService = $this->getConnectorRefundService();

        foreach ($createdData as $data) {
            $actualData = $data['data'];

            $target = [
                'marketplace' => $actualData['marketplace'],
                'order_id' => $actualData['marketplace_reference_id'],
                'shop_id' => $actualData['marketplace_shop_id'],
                'status' => $actualData['status'],
                'refund_status' => $actualData['marketplace_status']
            ];

            if (isset($actualData['error'])) {
                $target['refund_failed_reason'] = $actualData['error'];
            }

            $this->initiatorsTargets[] = $target;

            foreach ($this->sources as $source) {
                $response = $connectorRefundService->updateSourceAndItems($source, $data['data']);
                if (!$response['success']) {
                    $this->errors[] = $this->prepareErrorData($source, $response);
                    continue;
                }

                $targetOrdersTarget[] = $this->prepareDataForUpdatingParentOrder($response);
            }
        }

        return [
            'success' => true,
            'targetOrdersTarget' => $targetOrdersTarget,
            'sourceOrder' => $response['data']
        ];
    }

    public function updateSourceOrdersTarget($updateData): void
    {
        $connectorRefundService = $this->getConnectorRefundService();
        foreach ($updateData as $data) {
            $response = $connectorRefundService->updateParentOrdersTarget($data);
            if (!$response['success']) {
                $this->errors[] = $this->prepareErrorData($data['filters'], $response);
                $this->saveInLog($data, 'targets-in-source-order-not-updated.log');
            }
        }
    }

    public function updateTargetOrdersTarget($updateData): void
    {
        $connectorRefundService = $this->getConnectorRefundService();
        foreach ($updateData as $data) {
            if (isset($data['isFeedBased']) && $data['isFeedBased'] !== true) {
                //updating targets in target_order
                $response = $connectorRefundService->updateParentOrdersTarget($updateData);
                if (!$response['success']) {
                    $this->errors[] = $this->prepareErrorData($updateData['filters'], $response);
                }
            }
        }
    }

    public function prepareDataForUpdatingParentOrder($data)
    {
        /*
        reduce the filters not required in connector service
         */
        $preparedData = [
            'filters' => [
                'object_type' => $data['data']['object_type'],
                'cif_order_id' => $data['data']['cif_order_id'],
                'order_id' => $data['data']['marketplace_reference_id'],
                'marketplace' => $data['data']['marketplace'],
                'marketplace_reference_id' => $data['data']['marketplace_reference_id'],
                'marketplace_shop_id' => $data['data']['marketplace_shop_id']
            ],
            'data' => [
                'status' => $data['data']['status'],
                'marketplace_status' => $data['data']['marketplace_status'],
                'refund_status' => $data['data']['refund_status'],
            ]
        ];
        if (isset($data['data']['isFeedBased']) && $data['data']['isFeedBased'] !== true) {
            //TODO: update var here, the process would not work if isFeedBased is true
            $prepareData['isFeedBased'] = $data['data']['isFeedBased'];
        }

        return $preparedData;
    }

    public function updateTargetsInInitiator($targets)
    {
        $connectorRefundService = $this->getConnectorRefundService();
        foreach ($targets as $target) {
            $updateResponse = $connectorRefundService->updateTargetsInInitiator(
                $target['data'],
                $this->initiatorsTargets
            );
        }

        return $updateResponse;
    }

    /**
     * rectifyDataForSourceModule method updates the some keys according to the marketplace
     * @param array $sourceModuleData
     * @param array $sourceOrder
     * @return array
     */
    public function rectifyDataForSourceModule($sourceModuleData, $sourceOrder, $isPartial)
    {
        $sourceModuleData['marketplace_shop_id'] = $sourceOrder['marketplace_shop_id'];
        $sourceModuleData['marketplace_reference_id'] = $sourceOrder['marketplace_reference_id'];
        $sourceModuleData['marketplace'] = $sourceOrder['marketplace'];
        $sourceModuleData['marketplace_warehouse_id'] = $sourceOrder['marketplace_warehouse_id'] ?? null;
        //update required to also set status to Refund as well for cases target is complete refund but source is partial
        //reverse method
        if ($isPartial !== false) {
            $sourceModuleData['status'] = 'Partially Refund';
        }

        $objectType = 'source_refund';
        if ($sourceModuleData['object_type'] == 'source_refund') {
            $objectType = 'target_refund';
        }

        $sourceModuleData['object_type'] = $objectType;

        return $sourceModuleData;
    }

    public function rectifyPricesForSourceModule($sourceModuleData, $item)
    {
        $negateRefundPrice = $item['refund_amount']['price'] ?? 0;
        $negateRefundMarketplacePrice = $item['refund_amount']['marketplace_price'] ?? 0;
        $negateAdjustPrice = $item['adjust_amount']['price'] ?? 0;
        $negateAdjustMarketplacePrice = $item['adjust_amount']['marketplace_price'] ?? 0;

        if (isset($sourceModuleData['refund_amount'])) {
            $sourceModuleData['refund_amount']['price'] -= $negateRefundPrice;
            $sourceModuleData['refund_amount']['marketplace_price'] -= $negateRefundMarketplacePrice;
        }

        if (isset($sourceModuleData['adjust_amount'])) {
            $sourceModuleData['adjust_amount']['price'] -= $negateAdjustPrice;
            $sourceModuleData['adjust_amount']['marketplace_price'] -= $negateAdjustMarketplacePrice;
        }

        return $sourceModuleData;
    }


    public function saveResponseFeed($feedData)
    {
        $mongo = $this->getBaseMongoAndCollection(self::FEED_CONTAINER);

        $response = $mongo->insertOne($feedData);
        if ($response->getInsertedCount() < 1) {
            return false;
        }

        return true;
    }

    //not in work at the moment
    public function initiateFeedSync(): void
    {
        $query = ['cif_type' => 'REFUND_FEED'];
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array'], "limit" => 20, "skip" => 0];
        $mongo = $this->getBaseMongoAndCollection(self::FEED_CONTAINER);
        $feeds = $mongo->find($query, $options)->toArray();
        if (!empty($feeds)) {
            foreach ($feeds as $feed) {
                $response = $this->refundResponse(($feed));
                if ($response['success']) {
                    $mongo->deleteOne(['_id' => $feed['_id']]);
                }
            }
        }
    }


    public function refundResponse($data)
    {
        //need to update
        //assumtion data = ['home_shop_id => $shop_id', 'feed_id' => '213123', 'response'=>[]];
        //$data = ['home_shop_id' => 28, 'feed_id' => '135328019270', 'response' => [], 'marketplace' => 'amazon.com'];

        if (!isset($data['marketplace'])) {
            $userDetails = $this->di->getUser()->getConfig();
            $shops = $userDetails['shops'];
            foreach ($shops as $key => $shop) {
                if ($shop['_id'] == $data['home_shop_id']) {
                    $targetShop = $shops[$key];
                }
            }

            if (empty($targetShop)) {
                return ['success' => false, 'message' => 'shop not found.'];
            }

            $targetMarketplace = $targetShop['marketplace'];
        } else {
            $targetMarketplace = $data['marketplace'];
        }

        $targetRefundService = $this->di->getObjectManager()
            ->get(\App\Connector\Contracts\Sales\Order\RefundInterface::class, [], $targetMarketplace);
        $targetServiceResponse = $targetRefundService->refundResponse($data);

        /* example response = [
                    'success' => true,
                    'response_feed_id' => '135328019270',
                    'message' => 'ItemId or OrderId didn't matched'
                ];
        */

        $failed = false;
        if (!$targetServiceResponse['success']) {
            $failed = true;
            if ($targetServiceResponse['message'] == 'in_process') {
                return ['success' => false, 'message' => $targetServiceResponse['message']];
            }
        }

        $connectorRefundService = $this->getConnectorRefundService();
        return $connectorRefundService->refundResponseUpdate($targetServiceResponse, $failed);
        //can log if not success
    }

    public function unsetInprogressKeyForTargetRefund($data): void
    {
        $filter = [
            'user_id' => $this->di->getUser()->id,
            'object_type' => (strpos($data['object_type'], 'target') !== false) ? 'source_refund' : 'target_refund',
            'cif_order_id' => $data['cif_order_id']
        ];

        if (isset($data['reference_id'])) {
            $filter = ['_id' => new \MongoDB\BSON\ObjectId($data['reference_id']), ...$filter];
        }

        $this->unsetInprogressKey($filter);
    }

    public function unsetInprogressKey($filter)
    {
        $unset = ['process_inprogess' => 1];
        return $this->unsetData($filter, $unset);
    }

    public function unsetRefundFailedReason($data)
    {
        $filter['user_id'] = $this->di->getUser()->id;

        $filter['object_type'] = "source_order";

        if (strpos($data['object_type'], "source") !== false) {
            $filter['object_type'] = "target_order";
        }

        $filter['cif_order_id'] = $data['cif_order_id'];

        $unsetData = ['refund_failed_reason' => 1];

        return $this->unsetData($filter, $unsetData);
    }

    public function unsetData($filter, $data)
    {
        $connectorRefundService = $this->getConnectorRefundService();
        return $connectorRefundService->unsetData($filter, $data);
    }

    public function manualRefund($data): void
    {
        //need to work
        // $userId = $this->di->getUser()->id;
        // $source_shop_id = $this->di->getRequester()->getSourceId();
        // $target_shop_id = $this->di->getRequester()->getTargetId();
        // $source = $this->di->getRequester()->getSourceName();
        // $target = $this->di->getRequester()->getTargetName();
        // $appTag = $this->di->getAppCode()->getAppTag();
        // return $target;
    }

    /**
     * getDataFromDb fetches the data from the db based on passed query
     * @param array $query
     * @return array
     */
    public function getDataFromDb($query, $function = 'find') //can implement aggregation pipeline using $function
    {
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $mongo = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);

        return $mongo->find($query, $options)->toArray();
    }

    public function createCustomFail($data)
    {
        $filter = [
            'object_type' => 'source_order',
            '$or' => [
                ['marketplace_reference_id' => $data['marketplace_reference_id']],
                ['targets.order_id' => $data['marketplace_reference_id']]
            ]
        ];
        $update = [
            'refund_status' => 'Failed',
            'refund_failed_reason' => 'Test function'
        ];
        $connectorRefundService = $this->getConnectorRefundService();
        return $connectorRefundService->updateDataInDb($filter, $update);
    }

    public function getSourceSku($sourceShopId, $targetShopId, $sku)
    {
        $response = $this->getProductBySku($sourceShopId, $targetShopId, $sku);
        if (!$response['success']) {
            return $sku;
        }

        $product = $response['data'];

        if (!array_key_exists('sku', $product)) {
            return $sku;
        }

        return $product['sku'];
    }

    public function getProductBySku($sourceShopId, $targetShopId, $sku)
    {
        $productBySkuObj = $this->di->getObjectManager()
            ->create(\App\Connector\Models\Product\Marketplace::class);

        $skudata = [
            "sku" => $sku,
            "source_shop_id" => $sourceShopId,
            "target_shop_id" => $targetShopId,
        ];

        $product = $productBySkuObj->getProductBySku($skudata);
        if (empty($product)) {
            return [
                'success' => false,
                'message' => 'Product not found'
            ];
        }

        return [
            'success' => true,
            'data' => $product
        ];
    }

    /**
     * getBaseMongoAndCollection method instantiate an object of BaseMongo and
     * also gets the collection based on the passed param
     * @param string $collection
     */
    public function getBaseMongoAndCollection($collection): object
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        return $mongo->getCollection($collection);
    }

    public function getConnectorRefundService()
    {
        return $this->di->getObjectManager()
            ->get(\App\Connector\Contracts\Sales\Order\RefundInterface::class);
    }

    public function prepareErrorData($data, $response)
    {
        $errorData = [
            'marketplace_reference_id' => $data['marketplace_reference_id'],
            'shop_id' => $data['marketplace_shop_id'],
            'marketplace' => $data['marketplace'],
            'error' => $response['message']
        ];

        $this->saveInLog($errorData, 'errors/inside-prepare-error-data.log');
        return $errorData;
    }

    public function notUnmatchedShipping($allData)
    {
        $notUnmatchedShipping = true;

        foreach ($allData as $index => $data) {
            if ($data['unMatchedShippingAmount']) {
                $notUnmatchedShipping = false;
            } else {
                unset($allData[$index]['unMatchedShippingAmount']);
            }
        }

        //could be updated for bulk handling here
        if (!$notUnmatchedShipping) {
            return [
                'success' => false,
                'message' => 'The refund amount has shipping amount greater than original'
            ];
        }

        return [
            'success' => true,
            'message' => 'Shipping is correct',
            'data' => $allData
        ];
    }

    public function removeUnmatchedShippingsData($allData)
    {
        $connectorRefundService = $this->getConnectorRefundService();

        foreach ($allData as $index => $data) {
            if ($data['unMatchedShippingAmount']) {
                $filter = [
                    'user_id' => $this->di->getUser()->id,
                    '$or' => [
                        ['object_type' => 'target_order'],
                        ['object_type' => 'source_order']
                    ],
                    'marketplace' => $data['marketplace'],
                    'marketplace_shop_id' => $data['marketplace_shop_id'],
                    'marketplace_reference_id' => $data['marketplace_reference_id'],
                ];

                $updateData = [
                    'refund_status' => 'Failed',
                    'refund_failed_reason' => 'Some amount was more than Source order',
                    'source_updated_at' => $data['source_updated_at'] ?? date('c')
                ];

                $connectorRefundService->updateDataInDb($filter, $updateData);
                unset($allData[$index]);
            } else {
                unset($allData[$index]['unMatchedShippingAmount']);
            }
        }

        return $allData;
    }

    private function groupItemsByItemLinkId($items, $skipComponentMerge = false)
    {
        $preparedItems = [];
        foreach ($items as $item) {
            if (empty($item['item_link_id'])) {
                return $items;
            }

            if (!empty($item['is_component']) && !$skipComponentMerge) {
                $preparedItems[$item['item_link_id']]['is_component'] = true;
                $preparedItems[$item['item_link_id']][$item['marketplace_item_id']] = $item;
                $preparedItems[$item['item_link_id']]['total_bundle_qty'] = ($preparedItems[$item['item_link_id']]['total_bundle_qty'] ?? 0) + $item['total_qty'];
                $preparedItems[$item['item_link_id']]['total_bundle_refunded_qty'] = ($preparedItems[$item['item_link_id']]['total_bundle_refunded_qty'] ?? 0) + $item['total_refunded_qty'];
                $preparedItems[$item['item_link_id']]['total_bundle_refund_qty'] = ($preparedItems[$item['item_link_id']]['total_bundle_refund_qty'] ?? 0) + $item['qty'];
                $preparedItems[$item['item_link_id']]['total_refund_amount'] = [
                    'price' => ($preparedItems[$item['item_link_id']]['total_refund_amount']['price'] ?? 0) + $item['refund_amount']['price'],
                    'marketplace_price' => ($preparedItems[$item['item_link_id']]['total_refund_amount']['marketplace_price'] ?? 0) + $item['refund_amount']['marketplace_price']
                ];
                $preparedItems[$item['item_link_id']]['customer_note'] ??= $item['customer_note'] ?? null;
            } else {
                $preparedItems[$item['item_link_id']] = $item;
            }

        }

        return $preparedItems;
    }

    private function hasIncompleteComponent($items)
    {
        foreach ($items as $item) {
            if (!empty($item['is_component']) &&
                isset($item['total_bundle_refunded_qty'], $item['total_bundle_refund_qty'], $item['total_bundle_qty']) &&
                $item['total_bundle_refunded_qty'] + $item['total_bundle_refund_qty'] != $item['total_bundle_qty']) {
                return true;
            }
        }

        return false;
    }

    private function setErrorInSourceForIncompleteComponent($data, $sourceOrderResponse)
    {
        $sourceOrder = $sourceOrderResponse[0];
        $sourceOrder['order_sync'] = true;
        $sourceOrder['order_refund'] = true;
        $this->setErrorInSource($sourceOrder, 'This order contains bundled items. All components of a bundle must be refunded before it can be processed. Partial refunds of bundles are not supported.');
    }

    public function resetClassProperties(): void
    {
        $this->errors = [];
        $this->initiatorsTargets = [];
        $this->orderId = null;
    }

    public function saveInLog($data, $file): void
    {
        $time = $this->getCurrentTime();
        $userId = $this->di->getUser()->id;
        $this->di->getLog()->logContent($time . PHP_EOL . 'message = ' . print_r($data, true), 'info', 'order/order-refund/' . $userId . '/' . date("Y-m-d") . '/' . $file);
    }

    public function tempLog($data, $process = 'automatic.log', $type = 'message'): void
    {
        $time = $this->getCurrentTime();
        $userId = $this->di->getUser()->id;
        $div = $this->getLineForLogs();
        $this->di->getLog()->logContent($div . PHP_EOL . $time . PHP_EOL . $type . ' = ' . print_r($data, true), 'info', 'order/order-refund/temp-all-refunds/'  . $userId . '/' . date("Y-m-d") . '/' . $process);
    }

    public function orderWiseLogs($data, $type): void
    {
        $orderId = $this->orderId;
        $time = $this->getCurrentTime();
        $userId = $this->di->getUser()->id;
        $div = $this->getLineForLogs();
        $this->di->getLog()->logContent($div . PHP_EOL . $time . PHP_EOL . $type . ' = ' . print_r($data, true), 'info', 'order/order-refund/orderWiseLogs/'  . $userId . '/' . date("Y-m-d") . '/' . $orderId . '.log');
    }

    public function log($pathAndData): void
    {
        $this->di->getLog()->logContent($pathAndData);
    }

    public function getCurrentTime()
    {
        return date('Y-m-d H:i:s');
    }

    public function getLineForLogs()
    {
        return str_repeat('-', 80);
    }
}
