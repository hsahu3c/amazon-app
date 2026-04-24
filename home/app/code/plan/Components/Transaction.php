<?php

namespace App\Plan\Components;

use App\Core\Components\Base;
use App\Plan\Models\Plan;
use App\Plan\Models\Transaction as TransactionModel;
use App\Plan\Models\BaseMongo as BaseMongo;

/**
 * class Transaction to prepare and save transaction record
 */
class Transaction extends Base
{
    public $transactionModel = null;

    public $planModel = null;

    /**
     * to intialize parent variables
     */
    public function initialize()
    {
        $this->transactionModel = $this->di->getObjectManager()->get(TransactionModel::class);
        $this->planModel = $this->di->getObjectManager()->get(Plan::class);
    }

    /**
     * to keep track of records on plan activation and plan upgrade
     */
    public function planActivated($planData): void
    {
        if (!empty($planData)) {
            $this->initialize();
            $transactionData = [];
            $userId = $planData['user_id'] ?? $this->di->getUser()->id;
            $transactionData = $this->transactionModel->getCurrentTransaction($userId);
            $activePlan = $this->planModel->getActivePlanForCurrentUser($userId);
            $currentPayment = $this->planModel->getPaymentInfoForCurrentUser($userId);

            if (!empty($currentPayment)) {
                $planHistory = [];
                $planHistory['active_plan_id'] = $activePlan['_id'] ?? null;
                $planHistory['plan_details'] = [
                    'title' => $activePlan['plan_details']['title'] ?? null,
                    'plan_id' => $activePlan['plan_details']['plan_id'] ?? null,
                    'billed_type' => $activePlan['plan_details']['billed_type'] ?? null,
                    'category' => $activePlan['plan_details']['category'] ?? Plan::CATERGORY_REGULAR,
                    'custom_plan' => $activePlan['plan_details']['custom_plan'] ?? false,
                    'discounts' => $activePlan['plan_details']['discounts'] ?? [],
                    'custom_price' => $activePlan['plan_details']['custom_price'] ?? null,
                ];
                $planHistory['plan_details']['services_groups'] = $this->getServices($activePlan['plan_details']);
                isset($activePlan['capped_amount']) && $planHistory['plan_details']['capped_amount'] = $activePlan['capped_amount'];
                isset($planData['quote_id']) && $planHistory['quote_id'] = $planData['quote_id'];
                isset($currentPayment['_id']) && $planHistory['payment_details']['_id'] = $currentPayment['_id'];
                isset($currentPayment['marketplace_data']['id']) && $planHistory['payment_details']['charge_id'] = $currentPayment['marketplace_data']['id'];
                isset($currentPayment['marketplace_data']['price']) && $planHistory['payment_details']['price'] = $currentPayment['marketplace_data']['price'];
                isset($currentPayment['created_at']) && $planHistory['payment_details']['paid_on'] = $currentPayment['created_at'];
                isset($currentPayment['marketplace_data']['capped_amount']) && $planHistory['payment_details']['capped_amount'] = $currentPayment['marketplace_data']['capped_amount'];
                isset($currentPayment['marketplace_data']['balance_used']) && $planHistory['payment_details']['balance_used'] = $currentPayment['marketplace_data']['balance_used'];
                $planHistory['status'] = $activePlan['status'] ?? null;
                $planHistory['activated_on'] = date('c');
                isset($planData['plan_upgraded']) && $planHistory['plan_upgraded'] = $planData['plan_upgraded'];
            }

            if(!empty($transactionData) && !empty($transactionData['active_plan_details'])) {
                $oldActivePlan = $transactionData['active_plan_details'];
                if(isset($transactionData['plan_history']) && !empty($transactionData['plan_history'])) {
                    foreach($transactionData['plan_history'] as $k => $history) {
                        if(isset($history['active_plan_id']) && $history['active_plan_id'] == $oldActivePlan['_id']) {
                            $transactionData['plan_history'][$k]['status'] = Plan::USER_PLAN_STATUS_INACTIVE;
                            $transactionData['plan_history'][$k]['deactivated_on'] = date('c');
                            $transactionData['plan_history'][$k]['payment_details']['status'] = Plan::PAYMENT_STATUS_DEACTIVATED;
                            if (isset($planData['old_user_services']) && !empty($planData['old_user_services'])) {
                                foreach ($planData['old_user_services'] as $userService) {
                                    $serviceInfo = [];
                                    isset($userService['service_type']) && $serviceInfo['service_type'] = $userService['service_type'];
                                    isset($userService['postpaid']) && $serviceInfo['postpaid'] = $userService['postpaid'];
                                    isset($userService['prepaid']) && $serviceInfo['prepaid'] = $userService['prepaid'];
                                    isset($userService['add_on']) && $serviceInfo['add_on'] = $userService['add_on'];
                                    isset($userService['supported_features']) && $serviceInfo['supported_features'] = $userService['supported_features'];
                                    $transactionData['plan_history'][$k]['credits_used'][] = $serviceInfo;
                                }
                            }
                        }
                    }
                }
            }

            $transactionData['active_plan_details'] = $activePlan;
            $transactionData['plan_history'][] = $planHistory;
            $transactionData['user_id'] = $planData['user_id'] ?? $this->di->getUser()->id;
            $transactionData['app_tag'] = $planData['app_tag'] ?? null;
            $transactionData['last_access_activate_date'] = date('c');
            $transactionData['month'] = date('m');
            $transactionData['year'] = date('Y');
            $this->transactionModel->setData($transactionData);
            $this->transactionModel->saveData($userId);
            $this->syncActivated($userId);
        }
    }

    /**
     * to update the sync deactivation status in transaction
     */
    public function syncDeactivated($userId): void
    {
        $this->initialize();
        $syncData['sync_activated'] = false;
        $syncData['last_access_revoke_date'] = date('c');
        $this->transactionModel->setData($syncData);
        $this->transactionModel->saveData($userId);
    }

     /**
     * to update the sync activation status in transaction
     */
    public function syncActivated($userId): void
    {
        $this->initialize();
        $syncData['sync_activated'] = true;
        $syncData['last_access_activate_date'] = date('c');
        $this->transactionModel->setData($syncData);
        $this->transactionModel->saveData($userId);
    }

    /**
     * to add records of settlement on settlement generate
     */
    public function settlementGenerated($userId): void
    {
        $this->initialize();
        $settlementInfo = $this->planModel->getPendingSettlementInvoice($userId);
        $transactionData = [];
        if(!empty($settlementInfo)) {
            $settlementHistory = [];
            $transactionData = $this->transactionModel->getCurrentTransaction($userId);
            if(!empty($transactionData) && isset($transactionData['settlement_history'])) {
                $settlementHistory = $transactionData['settlement_history'];
            }
            // $settlementInfo = $activeInfo['settlement_info']['invoice'];
            $transactionData['active_settlement'] = $settlementInfo;
            $settlementHistoryData = [
                "invoice_id" => $settlementInfo['_id'],
                "credits_used" => $settlementInfo['credits_used'],
                "settlement_amount" => $settlementInfo['settlement_amount'],
                "quote_id" => $settlementInfo['quote_id'],
                "service_credits_info" => [
                    'prepaid' => $settlementInfo['prepaid'],
                    'postpaid' => $settlementInfo['postpaid'],
                ],
                "status" => $settlementInfo['status'],
                "generated_on" => $settlementInfo['created_at'],
                "is_capped" => $settlementInfo['is_capped'] ?? false,
                "per_unit_usage_price" => $settlementInfo['per_unit_usage_price'] ?? null,
                "subscription_billing_date" => $settlementInfo['subscription_billing_date'] ?? null
            ];
            array_push($settlementHistory, $settlementHistoryData);
            $transactionData['settlement_history'] = $settlementHistory;

            $this->transactionModel->setData($transactionData);
            $this->transactionModel->saveData($userId);
        }
    }

    /**
     * to update transaction record on settlement approval
     */
    public function settlementPaid($settlementData): void
    {
        $this->initialize();
        if(isset($settlementData['user_id']) && !empty($settlementData['user_id'])) {
            $settlementHistory = [];
            $activeSettlement = [];

            $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
            $collection = $baseMongo->getCollectionForTable($this->planModel::PAYMENT_DETAILS_COLLECTION_NAME);
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

            $userId= $settlementData['user_id'];
            $transactionData = $this->transactionModel->getCurrentTransaction($userId);
            $settlementHistory = [];
            if(!empty($transactionData)) {
                isset($transactionData['settlement_history']) && $settlementHistory = $transactionData['settlement_history'];
                isset($transactionData['active_settlement']) && $activeSettlement = $transactionData['active_settlement'];
                if(!empty($activeSettlement) && ($activeSettlement['quote_id'] == $settlementData['quote_id'])) {
                    $transactionData['active_settlement'] = [];
                }
            } else {
                $transactionData['user_id'] = $userId;
                $transactionData['app_tag'] = $this->di->getAppCode()->getAppTag() ?? null;
                $transactionData['month'] = date('m');
                $transactionData['year'] = date('Y');
            }

            if(!empty($settlementHistory)) {
                foreach($settlementHistory as $k => $settlement) {
                    if($settlement['quote_id'] == $settlementData['quote_id']) {
                        $invoiceData = $collection->findOne([
                            "type" => 'settlement_invoice',
                            "_id" => $settlement['invoice_id']
                        ], $options);
                        if(!empty($invoiceData)) {
                            $settlementHistory[$k]['credits_used'] = $invoiceData['credits_used'];
                            $settlementHistory[$k]['settlement_amount'] = $invoiceData['settlement_amount'];
                            $settlementHistory[$k]['service_credits_info'] = [
                                'prepaid' => $invoiceData['prepaid'],
                                'postpaid' => $invoiceData['postpaid'],
                            ];
                        }

                        $settlementHistory[$k]['payment_details'] = $settlementData['payment_info'];
                        $settlementHistory[$k]['status'] = $settlementData['status'];
                    }
                }
            } else {
                $invoiceData = $collection->findOne([
                    "type" => 'settlement_invoice',
                    "quote_id" => $settlementData['quote_id']
                ], $options);
                if(!empty($invoiceData)) {
                    $settlementHistory[] = [
                        "invoice_id" => $invoiceData['_id'],
                        "credits_used" => $invoiceData['credits_used'],
                        "settlement_amount" => $invoiceData['settlement_amount'],
                        "quote_id" => $invoiceData['quote_id'],
                        "service_credits_info" => [
                            'prepaid' => $invoiceData['prepaid'],
                            'postpaid' => $invoiceData['postpaid'],
                        ],
                        "status" => $settlementData['status'],
                        "generated_on" => $invoiceData['created_at'],
                        'payment_details' => $settlementData['payment_info'],
                    ];
                }
            }

            $transactionData['settlement_history'] = $settlementHistory;
            $this->transactionModel->setData($transactionData);
            $this->transactionModel->saveData($userId);
        }
    }

    /**
     * to update record on custom payment
     */
    public function customPaymentProcessed($paymentData): void
    {
        $this->initialize();
        if(isset($paymentData['user_id']) && isset($paymentData['quote_id'])) {
            $userId = $paymentData['user_id'];
            $quoteData = $this->planModel->getQuoteByQuoteId($paymentData['quote_id']);
            $transactionData = $this->transactionModel->getCurrentTransaction($userId);
            if(!empty($quoteData)) {
                $customData = [
                    "quote_id" => $quoteData['_id'],
                    "name" => $quoteData['plan_details']['title'] ?? "",
                    "amount" => $quoteData['plan_details']['custom_price'] ?? 0,
                    "status" => $quoteData['status'],
                    "generated_on" => $quoteData['created_at'],
                    "payment_details" => [$paymentData['payment_details'] ?? []]
                ];
                if(!empty($transactionData)) {
                    $transactionData['custom_payment_history'][] = $customData;
                } else {
                    $transactionData['user_id'] = $userId;
                    $transactionData['app_tag'] = $this->di->getAppCode()->getAppTag() ?? null;
                    $transactionData['month'] = date('m');
                    $transactionData['year'] = date('Y');
                    $transactionData['created_at'] = date('c');

                    $transactionData['custom_payment_history'][] = $customData;
                }
            }

            $this->transactionModel->setData($transactionData);
            $this->transactionModel->saveData($userId);
        }
    }

    /**
     * to update record on plan deactivation
     */
    public function planDeactivated($planData): void
    {
        $this->initialize();
        if(isset($planData['user_id']) && !empty($planData['deactivated_plan_details'])) {
            $planHistoryExist = true;
            $userId = $planData['user_id'];
            $transactionData = $this->transactionModel->getCurrentTransaction($userId);
            $deactivatedPlan = $planData['deactivated_plan_details'];
            if(!empty($transactionData)) {
                if(isset($transactionData['active_plan_details']) && !empty($transactionData['active_plan_details'])) {
                    if ($transactionData['active_plan_details']['_id'] == $deactivatedPlan['_id']) {
                        $transactionData['active_plan_details'] = [];
                    }
                }

                if(isset($transactionData['plan_history']) && !empty($transactionData['plan_history'])) {
                    $planHistoryExist = true;
                    foreach($transactionData['plan_history'] as $k => $planHistory) {
                        if($planHistory['active_plan_id'] == $deactivatedPlan['_id']) {
                            $transactionData['plan_history'][$k]['status'] = Plan::USER_PLAN_STATUS_INACTIVE;
                            $transactionData['plan_history'][$k]['deactivated_on'] = date('c');
                            $transactionData['plan_history'][$k]['payment_details']['status'] = Plan::PAYMENT_STATUS_DEACTIVATED;
                        }
                    }
                } else {
                    $planHistoryExist = false;
                }
            } else {
                $planHistoryExist = false;
            }

            if(!$planHistoryExist) {
                $planHistory = [];
                $planHistory['active_plan_id'] = $deactivatedPlan['_id'];
                $planHistory['plan_details'] = [
                    'title' => $deactivatedPlan['plan_details']['title'] ?? null,
                    'plan_id' => $deactivatedPlan['plan_details']['plan_id'] ?? null,
                    'billed_type' => $deactivatedPlan['plan_details']['billed_type'] ?? null,
                    'custom_price' => $deactivatedPlan['plan_details']['custom_price'] ?? null,
                    // 'services_groups' => $deactivatedPlan['plan_details']['services_groups'] ?? [],
                    'category' => $deactivatedPlan['plan_details']['category'] ?? Plan::CATERGORY_REGULAR,
                    'payment_type' => $deactivatedPlan['plan_details']['payment_type'] ?? Plan::BILLING_TYPE_RECURRING,
                    'custom_plan' => $deactivatedPlan['plan_details']['custom_plan'] ?? false,
                ];
                $planHistory['plan_details']['services_groups'] = $this->getServices($deactivatedPlan['plan_details']);
                $planHistory['status'] = Plan::USER_PLAN_STATUS_INACTIVE;
                $planHistory['activated_on'] = $deactivatedPlan['created_at'];
                $planHistory['deactivated_on'] = date('c');
                isset($planData['quote_id']) && $planHistory['quote_id'] = $planData['quote_id'];
                if(isset($planData['payment_details']) && !empty($planData['payment_details'])) {
                    $paymentDetails = $planData['payment_details'];
                    isset($paymentDetails['_id']) && $planHistory['payment_details']['_id'] = $paymentDetails['_id'];
                    $planHistory['payment_details']['status'] = Plan::PAYMENT_STATUS_DEACTIVATED;
                    isset($paymentDetails['marketplace_data']['id']) && $planHistory['payment_details']['charge_id'] = $paymentDetails['marketplace_data']['id'];
                    isset($paymentDetails['marketplace_data']['price']) && $planHistory['payment_details']['price'] = $paymentDetails['marketplace_data']['price'];
                    isset($paymentDetails['created_at']) && $planHistory['payment_details']['paid_on'] = $paymentDetails['created_at'];
                }
            }

            $transactionData['user_id'] = $userId;
            $transactionData['app_tag'] = $this->di->getAppCode()->getAppTag() ?? null;
            $transactionData['month'] = date('m');
            $transactionData['year'] = date('Y');
            $transactionData['created_at'] = date('c');
            $transactionData['plan_history'][] = $planHistory ?? [];
            $this->transactionModel->setData($transactionData);
            $this->transactionModel->saveData($userId);
        }
    }

    /**
     * to update the status of user removal
     */
    public function userRemovedFromPayment($userId): void
    {
        $this->initialize();
        $uninstallData['user_unistalled'] = true;
        $uninstallData['data_removed_from_table'] = true;
        $uninstallData['data_removed_on'] = date('c');
        $this->transactionModel->setData($uninstallData);
        $this->transactionModel->saveData($userId);
    }

    public function getServices($planDetails)
    {
        $serviceGroups = [];
        if (!empty($planDetails['services_groups'])) {
            foreach($planDetails['services_groups'] as $services) {
                foreach ($services['services'] as $service) {
                    $serviceGroups[] = [
                        'service_type' => $service['type'],
                        'prepaid' => $service['prepaid'] ?? [],
                        'postpaid' => $service['postpaid'] ?? [],
                        'supported_features' => $service['supported_features'] ?? []
                    ];
                }
            }
        }
        return $serviceGroups;
    }

    public function updateSettlementStatus($settlementData)
    {
        $this->initialize();
        if(isset($settlementData['user_id']) && !empty($settlementData['user_id']) && isset($settlementData['is_capped']) && $settlementData['is_capped']) {
            $settlementHistory = [];
            $activeSettlement = [];

            $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
            $collection = $baseMongo->getCollectionForTable($this->planModel::PAYMENT_DETAILS_COLLECTION_NAME);
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

            $userId= $settlementData['user_id'];
            $transactionData = $this->transactionModel->getCurrentTransaction($userId);
            $settlementHistory = [];
            if(!empty($transactionData)) {
                isset($transactionData['settlement_history']) && $settlementHistory = $transactionData['settlement_history'];
                isset($transactionData['active_settlement']) && $activeSettlement = $transactionData['active_settlement'];
                if(!empty($activeSettlement) && ($activeSettlement['quote_id'] == $settlementData['quote_id'])) {
                    $transactionData['active_settlement'] = $settlementData;
                }
            } else {
                $transactionData['user_id'] = $userId;
                $transactionData['app_tag'] = $this->di->getAppCode()->getAppTag() ?? null;
                $transactionData['month'] = date('m');
                $transactionData['year'] = date('Y');
            }

            if(!empty($settlementHistory)) {
                foreach($settlementHistory as $k => $settlement) {
                    if($settlement['quote_id'] == $settlementData['quote_id']) {
                        $settlementHistory[$k]['status'] = $settlementData['status'];
                    }
                }
            } else {
                $invoiceData = $collection->findOne([
                    "type" => 'settlement_invoice',
                    "quote_id" => $settlementData['quote_id']
                ], $options);
                if(!empty($invoiceData)) {
                     $settlementHistory[] = [
                        "invoice_id" => $invoiceData['_id'],
                        "credits_used" => $invoiceData['credits_used'],
                        "settlement_amount" => $invoiceData['settlement_amount'],
                        "quote_id" => $invoiceData['quote_id'],
                        "service_credits_info" => [
                            'prepaid' => $invoiceData['prepaid'],
                            'postpaid' => $invoiceData['postpaid'],
                        ],
                        "status" => $invoiceData['status'],
                        "generated_on" => $invoiceData['created_at'],
                        "is_capped" => $invoiceData['is_capped'] ?? false,
                        "per_unit_usage_price" => $invoiceData['per_unit_usage_price'] ?? null
                    ];
                }
            }

            $transactionData['settlement_history'] = $settlementHistory;
            $this->transactionModel->setData($transactionData);
            $this->transactionModel->saveData($userId);
        }
    }
}
