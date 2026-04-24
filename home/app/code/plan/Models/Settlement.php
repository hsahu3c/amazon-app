<?php

namespace App\Plan\Models;

use App\Plan\Components\Method\ShopifyPayment;
use App\Plan\Components\Transaction;
use App\Plan\Models\Plan as PlanModel;
use DateTime;
use Exception;
use SellingPartnerApi\Seller\FeedsV20210630\Requests\CancelFeed;

/**
 * Class Settlement
 * @package App\Plan\Models
 */
#[\AllowDynamicProperties]
class Settlement extends PlanModel
{
    public $commonObj = null;
    public $planModel = null;
    public $baseMongo = null;
    public $paymentCollection = null;
    public $logFile = null;

    /**
     * The main logic to execute when the command is run
     * steps to perform charge deduction:
     * get the pending capped settlement_invoices
     * deduct the usage charges by setting the user in di getting the balance and as per that perforimng the deduction
     * once deduction at remote is done successfuly update the status of the settlement_invoice to on_hold if it is within the current month's last payment date
     * if the date is exceeded then close the settlement by approving it and manage the usage in monthly_usage
     * 
     * steps to renew the postpaid credits:
     * get the service with postpaid.capped_amount, has total_used_credits or previous_used_credits > 0, and the current date is greater than or euqal to the subscription_billing_date
     * for all such services get any pending or on_hold settlement_invoice
     * for pending invoice deduct the charges
     * for on_hold check if there is any to deduct
     * after checking, validating and clearing the charges
     * approve the settlement_invoice finally
     * and the reset the postpaid credits and update the subscription_billing_date either by increasing a month or getting the dae from the API
     */
    public function resetAndApprovePostpaidCredits()
    {
        $conditionFilterData = [
            'type' => PlanModel::PAYMENT_TYPE_USER_SERVICE,
            'source_marketplace' => PlanModel::SOURCE_MARKETPLACE,
            'target_marketplace' => PlanModel::TARGET_MARKETPLACE,
            'marketplace' => PlanModel::TARGET_MARKETPLACE,
            'service_type' => PlanModel::SERVICE_TYPE_ORDER_SYNC,
            '$or' => [
                ['postpaid.previous_used_credits' => ['$gt' => 0]],
                ['postpaid.total_used_credits' => ['$gt' => 0]],
                ['subscription_billing_date' => ['$lt' => date('Y-m-d')]]
            ],
            'postpaid.is_capped' => ['$exists' => true],
            'postpaid.is_capped' => true,
        ];
        $logFile = 'plan/capped/'.date('Y-m-d').'.log';
        $this->commonObj->addLog('  --- Initiated the reset cron for postpaid deduction --- ', $logFile);
        $cappedServices = $this->paymentCollection->find($conditionFilterData, PlanModel::TYPEMAP_OPTIONS)->toArray();
        $this->commonObj->addLog('Total services found to reset:  '.count($cappedServices), $logFile);
        if (!empty($cappedServices)) {
            foreach($cappedServices as $cappedService) {
                $userId = $cappedService['user_id'];
                $this->commonObj->addLog('Processing for user:  '.json_encode($userId), $logFile);
                $res = $this->commonObj->setDiRequesterAndTag($userId);
                if (!$res['success']) {
                    $this->commonObj->addLog('Di set failure!'.json_encode($userId), $logFile);
                    //need to handle the cases of failure of this case
                    continue;
                }
                $res = $this->checkSettlementAndProcess($userId, $cappedService);
                $this->commonObj->addLog('checkSettlementAndProcess response: '.json_encode($res), $logFile);
                // if (!empty($res) && (!$res['success'] || (isset($res['data'])) && empty($res['data']))) {
                //     $this->commonObj->addLog('calling renewHoldSettlements!', $logFile);
                //     $this->renewHoldSettlements($userId, $cappedService);
                // }
            }
        }
    }

    /**
     * to close a settlement doc
     */
    public function approveCappedSettlementInfo($holdSettlement)
    {
        $currentDate = date('Y-m-d');
        $this->paymentCollection->updateOne(
            ['_id' => $holdSettlement['_id'], 'user_id' => $holdSettlement['user_id'], 'type' => self::SETTLEMENT_INVOICE],
            [
                '$set' =>
                [
                    'status' => self::PAYMENT_STATUS_APPROVED,
                    'updated_at' => $currentDate
                ]
            ]
        );
        $this->paymentCollection->updateOne(
            ['_id' => $holdSettlement['quote_id'], 'user_id' => $holdSettlement['user_id'], 'type' => self::PAYMENT_TYPE_QUOTE],
            [
                '$set' =>
                [
                    'status' => self::PAYMENT_STATUS_APPROVED,
                    'updated_at' => $currentDate
                ]
            ]
        );
        $paymentDetails = $this->paymentCollection->findOneAndUpdate(
            ['quote_id' => $holdSettlement['quote_id'], 'user_id' => $holdSettlement['user_id'], 'type' => self::PAYMENT_TYPE_PAYMENT],
            [
                '$set' =>
                [
                    'status' => self::PAYMENT_STATUS_APPROVED,
                    'updated_at' => $currentDate
                ]
            ]
        );
        $transactionData['user_id'] = $holdSettlement['user_id'];
        $transactionData['payment_info'] = [
            'id' => $paymentDetails['_id'] ?? null,
            'charge_id' => $paymentDetails['marketplace_data']['id'] ?? null,
            'usage_records' => $paymentDetails['marketplace_data']['usage_records'] ?? [],
            'total_amount_paid' => $paymentDetails['marketplace_data']['usage_records']['total_amount_paid'] ?? null,
            'usage_ids' => $paymentDetails['marketplace_data']['usage_ids'] ?? [],
            'paid_on' => date('c'),
            'status' => self::PAYMENT_STATUS_APPROVED
        ];
        $transactionData['quote_id'] = $holdSettlement['quote_id'];
        $transactionData['status'] = self::PAYMENT_STATUS_APPROVED;
        $this->di->getObjectManager()->get(Transaction::class)->settlementPaid($transactionData);
    }

    public function getSettlementOnHold($userId)
    {
        $filter = [
            'type' => PlanModel::SETTLEMENT_INVOICE,
            'is_capped' => true,
            "status" =>  PlanModel::PAYMENT_STATUS_ON_HOLD,
            'user_id' => $userId
        ];
        return $this->paymentCollection->findOne($filter, self::TYPEMAP_OPTIONS);
    }

    public function holdSettlement($usageChargeData, $settlementInvoice)
    {
        $usageChargeData = $usageChargeData['data'] ?? $usageChargeData;
        $paymentData = $this->getPaymentDetailsByQuoteId($settlementInvoice['quote_id']);
        //update usage records to manitain the records or payment being done
        if (!empty($paymentData)) {
            $usageRecord = isset($paymentData['marketplace_data']['usage_records']) ? $paymentData['marketplace_data']['usage_records'] : [];
            if (!empty($usageRecord) && isset($usageRecord['usage_ids'], $usageRecord['total_amount_paid'])) {
                array_push($usageRecord['usage_ids'], $usageChargeData['id']);
                $usageRecord['total_amount_paid'] = (float)$usageRecord['total_amount_paid'] + (float)$usageChargeData['price'];
            } else {
                $usageRecord['usage_ids'][] = $usageChargeData['id'];
                $usageRecord['total_amount_paid'] = (float)$usageChargeData['price'];
            }
            $usageChargeData['usage_records'] = $usageRecord;
        } else {
            $usageRecord['usage_ids'][] = $usageChargeData['id'];
            $usageRecord['total_amount_paid'] = (float)$usageChargeData['price'];
            $usageChargeData['usage_records'] = $usageRecord;
        }
        //set the payment details data
        $paymentId = $this->setPaymentDetails($settlementInvoice['user_id'], PlanModel::PAYMENT_STATUS_ON_HOLD, $settlementInvoice['quote_id'], $usageChargeData);
        $currentDate = date('Y-m-d H:i:s');
        //update status in settlement invoice
        $this->paymentCollection->updateOne(
            ['_id' => $settlementInvoice['_id']],
            [
                '$set' =>
                [
                    'status' => PlanModel::PAYMENT_STATUS_ON_HOLD,
                    'updated_at' => $currentDate,
                    'payment_date' => $currentDate,
                    'payment_id' => $paymentId
                ]
            ]
        );
        $settlementInvoice['status'] = PlanModel::PAYMENT_STATUS_ON_HOLD;
        $settlementInvoice['updated_at'] = $currentDate;
        $settlementInvoice['payment_date'] = $currentDate;
        $settlementInvoice['payment_id'] = $paymentId;
        //update quote with updated data of settlement invoice and make it active
        $this->paymentCollection->updateOne(
            ['_id' => $settlementInvoice['quote_id']],
            [
                '$set' =>
                [
                    'status' => PlanModel::QUOTE_STATUS_ACTIVE,
                    'updated_at' => $currentDate,
                    'payment_id' => $paymentId,
                    'settlement_details' => $settlementInvoice
                ]
            ]
        );
        $this->di->getObjectManager()->get(Transaction::class)->updateSettlementStatus($settlementInvoice);
    }

    /**
     * for pending settlements
     */
    public function checkSettlementAndProcess($userId, $orderService)
    {
        $logFile = 'plan/capped/'.date('Y-m-d').'.log';
        $settlementInvoice = $this->getPendingSettlementInvoice($userId);
        if (!empty($settlementInvoice)) {
            if (isset($settlementInvoice['is_capped']) && $settlementInvoice['is_capped']) {
                $this->commonObj->addLog('processing capped settlement for user: '.json_encode($userId), $logFile);
                $canDeduct = $this->canDeductSettlement($userId, $settlementInvoice);
                $this->commonObj->addLog('canDeduct: '.json_encode($canDeduct), $logFile);
                if ($canDeduct['success']) {
                    $chargeData = $canDeduct['data'];
                    $balanceUsed = (float)$chargeData['balance_used'];
                    //checking for postpaid renewal and deduction of settlement
                    //if we need to renew the srevice
                    $totalAvailableCredit = ($orderService['prepaid']['available_credits'] ?? 0)
                            + ($orderService['postpaid']['available_credits'] ?? 0)
                            + ($orderService['add_on']['available_credits'] ?? 0);
                    if (isset($chargeData['balance_used']) && isset($orderService['subscription_billing_date'], $chargeData['current_period_end'])) {
                        // $this->commonObj->addLog('balance used is 0 --- ', $logFile);
                        $newBillingDate = $chargeData['current_period_end'];
                        $hasUsage = $orderService['postpaid']['previous_used_credits'] ?? $orderService['postpaid']['total_used_credits'] ?? 0;
                        $this->commonObj->addLog('newBillingDate: '.json_encode($newBillingDate), $logFile);
                        $this->commonObj->addLog('currentBillingDate: '.json_encode($orderService['subscription_billing_date']), $logFile);
                        if (!is_null($newBillingDate) && ($newBillingDate > $orderService['subscription_billing_date'])) {
                            $this->commonObj->addLog('billing date is greater than the current one so need to renew the postpaid!', $logFile);
                            //need to renew credits before deduction
                            // $this->commonObj->addLog('Renewing credits! charge data: '.json_encode($chargeData), $logFile);
                            $updatedCappedService['postpaid.shopify_capped_amount'] = $chargeData['capped_amount'];
                            $updatedCappedService['postpaid.capped_amount'] = $orderService['postpaid']['capped_amount'] ?? 0;
                            $updatedCappedService['postpaid.balance_used'] = $chargeData['balance_used'];
                            $balanceRemaining = ($orderService['postpaid']['capped_amount'] - $balanceUsed);
                            $perUnitUsage = (float)($orderService['postpaid']['per_unit_usage_price'] ?? 0);
                            $creditsAvailable =  ($balanceRemaining > 0) ? (int)floor($balanceRemaining / $perUnitUsage) : 0;
                            $updatedCappedService['postpaid.available_credits'] = $creditsAvailable;
                            $updatedCappedService['postpaid.total_used_credits'] = 0;
                            $cappedCredit = (int)floor(($orderService['postpaid']['capped_amount'] ?? 0) / $perUnitUsage);
                            $updatedCappedService['postpaid.capped_credit'] = $cappedCredit;
                            // unset($cappedService['previous_used_credits']);
                            $updatedCappedService['subscription_billing_date'] = $newBillingDate;
                            $updatedCappedService['updated_at'] = date('c');
                            $this->commonObj->addLog('updatedCappedService: '.json_encode($updatedCappedService), $logFile);
                            $this->paymentCollection->updateOne(
                                ['_id' => $orderService['_id']],
                                [
                                    '$set' => $updatedCappedService,
                                    '$unset' => ['postpaid.previous_used_credits' => 1]
                                ]
                            );
                            $this->commonObj->addLog('service updated!', $logFile);
                            //reset email configs if required
                            //activate sync
                            $shopData = [];
                            if (!empty($userDetails) && $userDetails['shops']) {
                                foreach ($userDetails['shops'] as $shop) {
                                    if (isset($shop['marketplace'])  && $shop['marketplace'] == self::SOURCE_MARKETPLACE) {
                                        $shopData = $shop;
                                    }
                                }
                            }
                            $this->reUpdateEntry($userId, $shopData);
                            $this->commonObj->addLog('dynamo entry activated!', $logFile);
                            $this->commonObj->addLog('deducting charges!', $logFile);
                            //deduct settlement
                            $usageChargeRes = $this->deductSettlement($settlementInvoice, $chargeData);
                            $this->commonObj->addLog('usageChargeRes: '.json_encode($usageChargeRes), $logFile);
                            if (isset($usageChargeRes['success']) && $usageChargeRes['success']) {
                                $usageChargeRes['data']['charge_type'] = 'usage_based';
                                //approve
                                $this->commonObj->addLog('holdSettlement -- approveCappedSettlementInfo -- resetCappedUsedLimit', $logFile);
                                $this->holdSettlement($usageChargeRes, $settlementInvoice);
                                $this->approveCappedSettlementInfo($settlementInvoice);
                                $this->resetCappedUsedLimit($orderService, $usageChargeRes['data']);
                                $this->commonObj->addLog('process completed!', $logFile);
                                return [
                                    'success' => true,
                                    'message' => 'Settlement usage deducted!'
                                ];
                            } else {
                                return [
                                    'success' => false,
                                    'message' => 'Deduction of auto settlement payment fail. Please try after sometime!'
                                ];
                            }
                        } else {
                           $this->commonObj->addLog('processing to deduct usage --- ', $logFile);
                           //simply deduct the charges and put the settlement on hold
                           $usageChargeRes = $this->deductSettlement($settlementInvoice, $chargeData);
                           $this->commonObj->addLog('usageChargeRes: '.json_encode($usageChargeRes), $logFile);
                           if (isset($usageChargeRes['success']) && $usageChargeRes['success']) {
                               $usageChargeRes['data']['charge_type'] = 'usage_based';
                               //approve
                               $this->commonObj->addLog('holdSettlement -- resetCappedUsedLimit ', $logFile);
                               $this->holdSettlement($usageChargeRes, $settlementInvoice);
                               $this->resetCappedUsedLimit($orderService, $usageChargeRes['data']);
                               if ($totalAvailableCredit <= 0) {
                                    $this->commonObj->addLog('approveCappedSettlementInfo since no more credits are available!', $logFile);
                                    $this->approveCappedSettlementInfo($settlementInvoice);
                                }
                               //send email for deduction
                               return [
                                   'success' => true,
                                   'message' => 'Settlement usage deducted!'
                               ];
                           } else {
                               return [
                                   'success' => false,
                                   'message' => 'Deduction of auto settlement payment fail. Please try after sometime!'
                               ];
                           }
                       }
                    } else {
                        // $this->commonObj->addLog('balance used is more than 0 --- ', $logFile);
                        // //simply deduct the charges and put the settlement on hold
                        // $usageChargeRes = $this->deductSettlement($settlementInvoice, $chargeData);
                        // $this->commonObj->addLog('usageChargeRes: '.json_encode($usageChargeRes), $logFile);

                        // if (isset($usageChargeRes['success']) && $usageChargeRes['success']) {
                        //     $usageChargeRes['data']['charge_type'] = 'usage_based';
                        //     //approve
                        //     $this->commonObj->addLog('holdSettlement -- resetCappedUsedLimit -- ', $logFile);
                        //     $this->holdSettlement($usageChargeRes, $settlementInvoice);
                        //     $this->resetCappedUsedLimit($orderService, $usageChargeRes['data']);
                        //     if ($totalAvailableCredit <= 0) {
                        //         $this->commonObj->addLog('approveCappedSettlementInfo since no more credita are available!', $logFile);
                        //         $this->approveCappedSettlementInfo($settlementInvoice);
                        //     }

                        //     //send email for deduction
                        //     return [
                        //         'success' => true,
                        //         'message' => 'Settlement usage deducted!'
                        //     ];
                        // } else {
                        //     return [
                        //         'success' => false,
                        //         'message' => 'Deduction of auto settlement payment fail. Please try after sometime!'
                        //     ];
                        // }
                    }
                } else {
                    return $canDeduct;
                }
            } else {
                return [
                    'success' => false,
                    'message' => 'Need to pay settlement before proceeding further!'
                ];
            }
        } else {
            if (isset($orderService['postpaid']['is_capped'], $orderService['subscription_billing_date']) && ($orderService['subscription_billing_date'] <= date('Y-m-d'))) {
                $this->commonObj->addLog('settlement not pending so checking for renewal case: '.$userId, $logFile);
                $chargeData = $this->getRemoteChargeData($userId);
                if (!empty($chargeData) && isset($chargeData['current_period_end'])) {
                    $newBillingDate = $chargeData['current_period_end'];
                    if ($newBillingDate > $orderService['subscription_billing_date']) {
                        $this->commonObj->addLog('billing date changed: newBillingDate => '.$newBillingDate, $logFile);
                        $holdSettlement = $this->getSettlementOnHold($userId);
                        if (!empty($holdSettlement)) {
                            $this->commonObj->addLog('Has old settlement on hold', $logFile);
                            $this->renewHoldSettlements($userId, $orderService);
                        } else {
                            $this->commonObj->addLog('no settlement on hold so renewing data', $logFile);
                            $updatedCappedService['postpaid.shopify_capped_amount'] = $chargeData['capped_amount'];
                            $updatedCappedService['postpaid.capped_amount'] = $orderService['postpaid']['capped_amount'] ?? 0;
                            $updatedCappedService['postpaid.balance_used'] = $chargeData['balance_used'];
                            $balanceRemaining = ($orderService['postpaid']['capped_amount'] - $chargeData['balance_used']);
                            $perUnitUsage = (float)($orderService['postpaid']['per_unit_usage_price'] ?? 0);
                            $creditsAvailable =  ($balanceRemaining > 0) ? (int)floor($balanceRemaining / $perUnitUsage) : 0;
                            $updatedCappedService['postpaid.available_credits'] = $creditsAvailable;
                            $updatedCappedService['postpaid.total_used_credits'] = 0;
                            $cappedCredit = (int)floor(($orderService['postpaid']['capped_amount'] ?? 0) / $perUnitUsage);
                            $updatedCappedService['postpaid.capped_credit'] = $cappedCredit;
                            // unset($cappedService['previous_used_credits']);
                            $updatedCappedService['subscription_billing_date'] = $chargeData['current_period_end'];
                            $updatedCappedService['updated_at'] = date('c');
                            // $this->commonObj->addLog('updatedCappedService: '.json_encode($updatedCappedService), $logFile);
                            $this->paymentCollection->updateOne(
                                ['_id' => $orderService['_id']],
                                [
                                    '$set' => $updatedCappedService,
                                    '$unset' => ['postpaid.previous_used_credits' => 1]
                                ]
                            );
                            $shopData = [];
                            $shops = $this->di->getUser()->shops;
                            if (!empty($shops)) {
                                foreach ($shops as $shop) {
                                    if (isset($shop['marketplace'])  && $shop['marketplace'] == self::SOURCE_MARKETPLACE) {
                                        $shopData = $shop;
                                    }
                                }
                            }
                            $this->reUpdateEntry($userId, $shopData);
                        }
                    }
                }
            }
            return [
                'success' => true,
                'message' => 'No settlement invoice pending!'
            ];
        }
    }

    public function renewHoldSettlements($userId, $orderService)
    {
        $logFile = 'plan/capped/'.date('Y-m-d').'.log';
        $this->commonObj->addLog('in renewHoldSettlements: ', $logFile);
        $holdSettlement = $this->getSettlementOnHold($userId);
        if (!empty($holdSettlement)) {
            //get charge data
            $chargeData = $this->getRemoteChargeData($userId);
            if (!empty($chargeData)) {
                $shopData = [];
                $shops = $this->di->getUser()->shops;
                if (!empty($shops)) {
                    foreach ($shops as $shop) {
                        if (isset($shop['marketplace'])  && $shop['marketplace'] == self::SOURCE_MARKETPLACE) {
                            $shopData = $shop;
                        }
                    }
                }
                $balanceUsed = (float)$chargeData['balance_used'] ?? 0;
                //checking for postpaid renewal and deduction of settlement
                //if we need to renew the srevice
                $newBillingDate = $chargeData['current_period_end'] ?? null;
                $this->commonObj->addLog('newBillingDate: '.json_encode($newBillingDate), $logFile);
                $this->commonObj->addLog('oldBillingDate: '.json_encode($orderService['subscription_billing_date'] ?? null), $logFile);
                if (!is_null($newBillingDate) && isset($orderService['subscription_billing_date']) && ($newBillingDate > $orderService['subscription_billing_date'])) {
                        //need to renew credits before deduction
                        $this->commonObj->addLog('Renewing credits! charge data: '.json_encode($chargeData), $logFile);
                        $updatedCappedService['postpaid.shopify_capped_amount'] = $chargeData['capped_amount'];
                        $updatedCappedService['postpaid.capped_amount'] = $orderService['postpaid']['capped_amount'] ?? 0;
                        $updatedCappedService['postpaid.balance_used'] = $chargeData['balance_used'];
                        $balanceRemaining = ($orderService['postpaid']['capped_amount'] - $balanceUsed);
                        $perUnitUsage = (float)($orderService['postpaid']['per_unit_usage_price'] ?? 0);
                        $creditsAvailable =  ($balanceRemaining > 0) ? (int)floor($balanceRemaining / $perUnitUsage) : 0;
                        $updatedCappedService['postpaid.available_credits'] = $creditsAvailable;
                        $updatedCappedService['postpaid.total_used_credits'] = 0;
                        $cappedCredit = (int)floor(($orderService['postpaid']['capped_amount'] ?? 0) / $perUnitUsage);
                        $updatedCappedService['postpaid.capped_credit'] = $cappedCredit;
                        // unset($cappedService['previous_used_credits']);
                        $updatedCappedService['subscription_billing_date'] = $newBillingDate;
                        $updatedCappedService['updated_at'] = date('c');
                        $this->commonObj->addLog('updatedCappedService: '.json_encode($updatedCappedService), $logFile);
                        $this->paymentCollection->updateOne(
                            ['_id' => $orderService['_id']],
                            [
                                '$set' => $updatedCappedService,
                                '$unset' => ['postpaid.previous_used_credits' => 1]
                            ]
                        );

                        //reset email configs if required
                        //activate sync
                        $this->reUpdateEntry($userId, $shopData);
                        $this->approveCappedSettlementInfo($holdSettlement);
                        $this->commonObj->addLog('entry updated at dynamo and approved capped settlement info!'.json_encode($chargeData), $logFile);
                }
            } else {
                $this->commonObj->addLog('charge data not found!', $logFile);
                return [
                    'success' => false,
                    'message' => 'charge data not found!'
                ];
            }
        } else {
            $this->commonObj->addLog('no hold settlement found!', $logFile);
        }
    }



    public function canDeductSettlement($userId, $settlementInvoice)
    {
        if (!empty($settlementInvoice) && isset($settlementInvoice['is_capped']) && $settlementInvoice['is_capped']) {
            $paymentDetail = $this->getPaymentInfoForCurrentUser($userId);
            $sourceShopId = $this->di->getRequester()->getSourceId();
            if(empty($sourceShopId)) {
                $sourceShopId = $this->commonObj->getShopifyShopId($userId);
            }
            $shop = $this->commonObj->getShop($sourceShopId, $userId);
            if (!empty($paymentDetail) && isset($paymentDetail['marketplace_data']['id'])) {
                $chargeId = $paymentDetail['marketplace_data']['id'];
                if (!empty($shop) && isset($shop['remote_shop_id'])) {
                    $data['shop_id'] = $shop['remote_shop_id'];
                    $data['id'] = $chargeId;
                    $response = $this->di->getObjectManager()->get(ShopifyPayment::class)->getPaymentData($data, self::BILLING_TYPE_RECURRING);
                    if (!empty($response['data'])) {
                        $chargeData = $response['data'];
                        $balanceRemaining = 0;
                        $balanceRemaining = isset($chargeData['capped_amount'], $chargeData['balance_used']) ? (string)($chargeData['capped_amount'] - $chargeData['balance_used']) : 0;
                        $balanceRemaining = (float)$balanceRemaining;
                        if (($balanceRemaining > 0) && ((float)$settlementInvoice['settlement_amount'] > 0) && ($balanceRemaining >= (float)$settlementInvoice['settlement_amount'])) {
                            return [
                                'success' => true,
                                'data' => $chargeData
                            ];
                        } else {
                            return [
                                'success' => false,
                                'message' => 'We cannot deduct the usage charges as you do not have the sufficient balance left to process payment!'
                            ];
                        }
                    } else {
                        return [
                            'success' => false,
                            'message' => 'Issue in getting payment data'
                        ];
                    }
                } else {
                    return [
                        'success' => false,
                        'message' => 'Shop not found!'
                    ];
                }
            }
            return [
                'success' => false,
                'message' => 'Payment data not found!'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Excess Usage cannot be deducted for no capped settlement'
            ];
        }
        
    }

    public function deductSettlement($settlementInvoice, $chargeData)
    {
        $sourceShopId = $this->di->getRequester()->getSourceId();
        if(empty($sourceShopId)) {
            $sourceShopId = $this->commonObj->getShopifyShopId($this->di->getUser()->id);
        }
        $shop = $this->commonObj->getShop($sourceShopId, $this->di->getUser()->id);
        $description = 'Usage charge for '.$settlementInvoice['credits_used'].' additional orders';
        $usageData['shop_id'] = $shop['remote_shop_id'];
        $usageData['description'] = $settlementInvoice['description'] ?? $description;
        $usageData['price'] = (float)$settlementInvoice['settlement_amount'];
        $usageData['charge_id'] = $chargeData['id'];
        $usageData['line_item_id'] = $chargeData['lineItemsId'];
        return $this->di->getObjectManager()->get(ShopifyPayment::class)->createUsage($usageData);
    }

    public function resetCappedUsedLimit($orderService, $usageCharge)
    {
        // $userId = !$userId ? $this->di->getUser()->id : $userId;
        $updateData = [];
        $balanceUsed = (float)($usageCharge['balance_used'] ?? 0);
        $cappedAmount = (float)($orderService['postpaid']['capped_amount'] ?? 0);

        $balanceRemaining = ($cappedAmount - $balanceUsed);
        $perUnitUsage = (float)($orderService['postpaid']['per_unit_usage_price'] ?? 0);
        $creditsAvailable =  ($balanceRemaining > 0) ? (int)floor($balanceRemaining / $perUnitUsage) : 0;

        $updateData['updated_at'] = date('c');
        $updateData['postpaid'] = $orderService['postpaid'] ?? [];
        // $this->updatePrepaidIfPostpaidLeft($service);
        $updateData['postpaid']['previous_used_credits'] =  isset($orderService['postpaid']['previous_used_credits']) ? ((int)$orderService['postpaid']['previous_used_credits'] + (int)$orderService['postpaid']['total_used_credits']) : $orderService['postpaid']['total_used_credits'];
        $updateData['postpaid']['total_used_credits'] = 0;
        $updateData['postpaid']['balance_used'] = $balanceUsed;
        $updateData['postpaid']['available_credits'] = $creditsAvailable;

        /**
         * settlement process onetime
         */
        $this->paymentCollection->updateOne(
            ['_id' => $orderService['_id']],
            ['$set' => $updateData]
        );
        return true;
    }

    /**
     * to activate the capping limits for those whose trial ends
     */
    public function activatePostPaidCapping()
    {
        $conditionFilterData = [
            'type' => PlanModel::PAYMENT_TYPE_USER_SERVICE,
            'source_marketplace' => PlanModel::SOURCE_MARKETPLACE,
            'target_marketplace' => PlanModel::TARGET_MARKETPLACE,
            'marketplace' => PlanModel::TARGET_MARKETPLACE,
            'service_type' => PlanModel::SERVICE_TYPE_ORDER_SYNC,
            'postpaid.active' => false,
            'postpaid.is_capped' => true,
            'trial_end_at' => ['$exists' => true],
            'trial_end_at' => ['$lt' => date('Y-m-d')]
        ];
        $logFile = 'plan/activatecappedpostpaid/'.date('Y-m-d').'.log';
        $this->commonObj->addLog('  --- Initiated the postpaid activation for capped service --- ', $logFile);
        $blockedCappingServices = $this->paymentCollection->find($conditionFilterData, PlanModel::TYPEMAP_OPTIONS)->toArray();
        $this->commonObj->addLog('blockedCappingServices count: '.count($blockedCappingServices), $logFile);
        if (!empty($blockedCappingServices)) {
            foreach($blockedCappingServices as $cappedService) {
                $userId = $cappedService['user_id'];
                $this->commonObj->addLog('processing for user: '.json_encode($userId), $logFile);
                $res = $this->commonObj->setDiRequesterAndTag($userId);
                if (!$res['success']) {
                    $this->commonObj->addLog('di set fail! ', $logFile);
                    //need to handle the cases of failure of this case
                    continue;
                }
                $userDetails = $this->commonObj->getUserDetail($userId);
                $shopData = [];
                if (!empty($userDetails) && $userDetails['shops']) {
                    foreach ($userDetails['shops'] as $shop) {
                        if (isset($shop['marketplace'])  && $shop['marketplace'] == self::SOURCE_MARKETPLACE) {
                            $shopData = $shop;
                        }
                    }
                }
                if (!empty($shopData)) {
                    $remoteData = $this->getRemoteChargeData($userId);
                    if (!empty($remoteData) && isset($remoteData['id'], $remoteData['status'], $remoteData['balance_used'], $cappedService['postpaid']['per_unit_usage_price']) && ($remoteData['status'] == 'active')) {
                        $this->commonObj->addLog('payment is active!', $logFile);
                        $balanceRemaining = ($cappedService['postpaid']['capped_amount'] - $remoteData['balance_used']);
                        // $balanceRemaining = ($cappedPriceForPlan - $balanceUsed);
                        $balanceRemaining = ($balanceRemaining < 0) ? 0 : $balanceRemaining;
                        $perUnitUsage = (float)($cappedService['postpaid']['per_unit_usage_price'] ?? 0);
                        $creditsAvailable =  ($balanceRemaining > 0) ? (int)floor($balanceRemaining / $perUnitUsage) : 0;
                        if ($creditsAvailable > 0) {
                            $filter = [
                                'user_id' => $userId,
                                'type' => PlanModel::PAYMENT_TYPE_USER_SERVICE,
                                'service_type' => PlanModel::SERVICE_TYPE_ORDER_SYNC,
                                'postpaid.active' => false
                            ];
                            $this->paymentCollection->updateOne($filter, [
                                '$set' => [
                                    'postpaid.available_credits' => $creditsAvailable,
                                    'postpaid.total_used_credits' => 0,
                                    'postpaid.balance_used' => (float)$remoteData['balance_used'],
                                    'updated_at' => date('c')
                                ],
                                '$unset' => [
                                        'postpaid.active' => 1,
                                ]
                            ]);
                            $this->commonObj->addLog('updated data: '.json_encode([
                                'postpaid.available_credits' => $creditsAvailable,
                                'postpaid.total_used_credits' => 0,
                                'postpaid.balance_used' => (float)$remoteData['balance_used'],
                                'updated_at' => date('c')
                            ]), $logFile);
                            $this->reUpdateEntry($userId, $shopData);
                            $this->commonObj->addLog('payment is active!', $logFile);
                        }
                    } else {
                        $this->commonObj->addLog('occurs some failure when activating!', $logFile);
                    }
                }
            }
        }
    }
}
