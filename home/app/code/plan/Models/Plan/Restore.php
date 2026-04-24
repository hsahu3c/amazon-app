<?php

namespace App\Plan\Models\Plan;

use DateTime;
use App\Plan\Models\Plan;
use App\Plan\Models\BaseMongo;

/**
 * Class Restore
 * @package App\Plan\Models\Plan
 * for plan restoration process
 */
#[\AllowDynamicProperties]
class Restore extends \App\Core\Models\BaseMongo
{
    public const PAYMENT_DETAILS_COLLECTION_NAME = 'payment_details';

    public const PAYMENT_DETAILS_UNINSTALLED_USERS = 'payment_details_uninstalled_users';

    public const UNINSTALL_USER_DETAILS = 'uninstall_user_details';

    public const USER_DETAILS = 'user_details';

    public $planModel = null;

    public function onConstruct(): void
    {
        $this->di = $this->getDi();
        $this->planModel = $this->di->getObjectManager()->create(Plan::class);
    }

    public function restorePlan($userId)
    {
        $logFile = 'plan/afterAccountConnection/restore/' . date('Y-m-d') . '.log';
        $userDetail = $this->planModel->commonObj->getUserDetail($userId);
        if (!empty($userDetail) && isset($userDetail['username'])) {
            $this->planModel->commonObj->addLog(' ---- Processing Plan Restoration for '. $userId .'---- ', $logFile);
            if ($this->canProceed($userDetail['username']) === false) {
                $this->planModel->commonObj->addLog('user restricted to be restored!', $logFile);
                return false;
            }

            $uninstallPaymentCollection = $this->planModel->baseMongo->getCollection(self::PAYMENT_DETAILS_UNINSTALLED_USERS);

            $userIdChanged = false;
            $dataExistInCurrentPaymentCollection = false;
            $oldUserId = "";
            $uninstallDate = null;

            //process to find active plan if present and if user id changed or not - start
            $queryToFindActivePlan = [
                ['$match' => [
                    'type' => 'active_plan',
                    'user_id' => $userId
                ]],
                ['$sort' => ['created_at' => -1]],
                ['$limit' => 1]
            ];
            $activePlanData = $this->planModel->paymentCollection->aggregate($queryToFindActivePlan, Plan::TYPEMAP_OPTIONS)->toArray();
            //Step 1: if user id not changes and data exist in current collection
            if (!empty($activePlanData)) {
                $dataExistInCurrentPaymentCollection = true;
            } else {
                //Step 2: if user id not changes but data exist in uninstall payment collection
                $activePlanData = $uninstallPaymentCollection->aggregate($queryToFindActivePlan, Plan::TYPEMAP_OPTIONS)->toArray();
                
                if (empty($activePlanData)) {
                    //Step 3: if user id changed
                    $collection = $this->planModel->baseMongo->getCollection('uninstall_user_details');
                    $oldUserQuery = [
                        ['$match' => [
                            'username' => $userDetail['username'] ?? "",
                            'email' => $userDetail['email'] ?? "",
                            'shops' => ['$exists' => true]
                        ]],
                        [
                            '$unwind' => '$shops'
                        ],
                        [
                            '$match' => [
                                'shops.marketplace' => Plan::SOURCE_MARKETPLACE
                            ]
                        ],
                        [
                            '$sort' => [
                                "shops.apps.uninstall_date" => -1,
                                "installation_date" => -1
                            ]
                        ],
                        [
                            '$limit' => 1
                        ]
                        ];
                    $oldUserDetails = $collection->aggregate($oldUserQuery, Plan::TYPEMAP_OPTIONS)->toArray();
                    $oldUserDetails = $oldUserDetails[0] ?? [];
                    if (!empty($oldUserDetails['user_id'])) {
                        $userIdChanged = true;
                        $oldUserId = $oldUserDetails['user_id'] ?? "";

                        if(isset($oldUserDetails['shops']['apps'])) {
                            foreach($oldUserDetails['shops']['apps'] as $app) {
                                if(isset($app['uninstall_date'])) {
                                    $uninstallDate = $app['uninstall_date'];
                                }
                            }
                        }

                        //changed query with old user id
                        $queryToFindActivePlan = [
                            ['$match' => [
                                'type' => 'active_plan',
                                'user_id' => $oldUserId
                            ]],
                            ['$sort' => ['created_at' => -1]],
                            ['$limit' => 1]
                        ];
                        //Step 4: if data exist in current collection
                        $activePlanData = $this->planModel->paymentCollection->aggregate($queryToFindActivePlan, Plan::TYPEMAP_OPTIONS)->toArray();
                        if (!empty($activePlanData)) {
                            $dataExistInCurrentPaymentCollection = true;
                        } else {
                            //Step 5: if data exist in uninstall collection
                            $activePlanData = $uninstallPaymentCollection->aggregate($queryToFindActivePlan, Plan::TYPEMAP_OPTIONS)->toArray();
                            if (empty($activePlanData)) {
                                $this->planModel->commonObj->addLog('Data not found in any of the possible cases', $logFile);
                                return false;
                            }
                        }
                    } else {
                        $this->planModel->commonObj->addLog('Data not found from current user id and no previous user exist in uninstall_user_details!', $logFile);
                        return false;
                    }
                }
            }

            $this->planModel->commonObj->addLog('dataExistInCurrentPaymentCollection => ' . $dataExistInCurrentPaymentCollection, $logFile);
            $this->planModel->commonObj->addLog('userIdChanged => ' . $userIdChanged, $logFile);
            $this->planModel->commonObj->addLog('oldUserId => ' . $oldUserId, $logFile);
            $lastActivatedPlan = !empty($activePlanData[0]) ? $activePlanData[0] : (!empty($activePlanData) ? $activePlanData : []);
            $this->planModel->commonObj->addLog('lastActivatedPlan => ' . json_encode($lastActivatedPlan), $logFile);
            // if (empty($lastActivatedPlan) || ($lastActivatedPlan['plan_details']['title'] == 'Free')) {
            //     $this->planModel->commonObj->addLog('lastActivatedPlan is free!', $logFile);
            //     return false;
            // }

            //process to find active plan if present and if user id changed or not - end

            //check if we need to filter through old or new user id - start
            $paymentQuery = [];
            $userServiceQuery = [];
            $pendingSettlementQuery = [];

            if ($userIdChanged && !empty($oldUserId)) {
                $paymentQuery = [
                    ['$match' => [
                        'type' => 'payment',
                        'user_id' => $oldUserId,
                        'plan_status' => ['$exists' => true]
                    ]],
                    ['$sort' => ['created_at' => -1]],
                    ['$limit' => 1]
                ];

                $userServiceQuery = [
                    ['$match' => [
                        'type' => Plan::PAYMENT_TYPE_USER_SERVICE,
                        'user_id' => $oldUserId,
                        'service_type' => Plan::SERVICE_TYPE_ORDER_SYNC
                    ]],
                    ['$sort' => ['created_at' => -1]],
                    ['$limit' => 1]
                ];
                $pendingSettlementQuery = [
                    [
                        '$match' => [
                            'user_id' => $oldUserId,
                            'type' => Plan::SETTLEMENT_INVOICE,
                            'status' => Plan::PAYMENT_STATUS_PENDING,
                            'is_capped' => ['$exists' => false]
                        ]
                        ],
                        ['$sort' => ['last_payment_date' => -1]],
                        ['$limit' => 1]
                ];
            } elseif (!$userIdChanged) {
                $paymentQuery = [
                    ['$match' => [
                        'type' => 'payment',
                        'user_id' => $userId,
                        'plan_status' => ['$exists' => true]
                    ]],
                    ['$sort' => ['created_at' => -1]],
                    ['$limit' => 1]
                ];

                $userServiceQuery = [
                    ['$match' => [
                        'type' => Plan::PAYMENT_TYPE_USER_SERVICE,
                        'user_id' => $userId,
                        'service_type' => Plan::SERVICE_TYPE_ORDER_SYNC
                    ]],
                    ['$sort' => ['created_at' => -1]],
                    ['$limit' => 1]
                ];
                $pendingSettlementQuery = [
                    [
                        '$match' =>  [
                            'user_id' => $userId,
                            'type' => Plan::SETTLEMENT_INVOICE,
                            'status' => Plan::PAYMENT_STATUS_PENDING,
                            'is_capped' => ['$exists' => false]
                        ]
                    ],
                    ['$sort' => ['last_payment_date' => -1]],
                    ['$limit' => 1] 
                ];
            }

            $paymentData = [];
            $userServiceData = [];
            $pendingSettlement = [];
            $settlementQuote = [];
            if ($dataExistInCurrentPaymentCollection) {
                $paymentData = $this->planModel->paymentCollection->aggregate($paymentQuery, Plan::TYPEMAP_OPTIONS)->toArray();
                $userServiceData = $this->planModel->paymentCollection->aggregate($userServiceQuery, Plan::TYPEMAP_OPTIONS)->toArray();
                $pendingSettlement = $this->planModel->paymentCollection->aggregate($pendingSettlementQuery, Plan::TYPEMAP_OPTIONS)->toArray();
                $pendingSettlement = $pendingSettlement[0] ?? [];
                if (!empty($pendingSettlement)  && isset($pendingSettlement['quote_id'])) {
                    $settlementQuoteQuery = [
                        '_id' => $pendingSettlement['quote_id'],
                        'user_id' => $pendingSettlement['user_id'],
                        'type' => Plan::PAYMENT_TYPE_QUOTE,
                        'status' => Plan::QUOTE_STATUS_ACTIVE,
                        'plan_type' => Plan::QUOTE_TYPE_SETTLEMENT
                    ];
                    $settlementQuote = $this->planModel->paymentCollection->findOne($settlementQuoteQuery, Plan::TYPEMAP_OPTIONS);
                }
            } else {
                $paymentData = $uninstallPaymentCollection->aggregate($paymentQuery, Plan::TYPEMAP_OPTIONS)->toArray();
                $userServiceData = $uninstallPaymentCollection->aggregate($userServiceQuery, Plan::TYPEMAP_OPTIONS)->toArray();
                $pendingSettlement = $uninstallPaymentCollection->aggregate($pendingSettlementQuery, Plan::TYPEMAP_OPTIONS)->toArray();
                $pendingSettlement = $pendingSettlement[0] ?? [];
                if (!empty($pendingSettlement)  && isset($pendingSettlement['quote_id'])) {
                    $settlementQuoteQuery = [
                        '_id' => $pendingSettlement['quote_id'],
                        'user_id' => $pendingSettlement['user_id'],
                        'type' => Plan::PAYMENT_TYPE_QUOTE,
                        'status' => Plan::QUOTE_STATUS_ACTIVE,
                        'plan_type' => Plan::QUOTE_TYPE_SETTLEMENT
                    ];
                    $settlementQuote = $uninstallPaymentCollection->findOne($settlementQuoteQuery, Plan::TYPEMAP_OPTIONS);
                }
            }

            

            //In case of multi user_service docs there is conflict so we will also not proceed
            if (!empty($userServiceData) && count($userServiceData) > 1) {
                $this->planModel->commonObj->addLog('More than 1 user service exist in db! cannot proceed.', $logFile);
                return false;
            }

            $lastPaymentActiveData = $paymentData[0] ?? [];
            $userServiceData = $userServiceData[0] ?? [];
            $this->planModel->commonObj->addLog('lastPaymentActiveData => ' . json_encode($lastPaymentActiveData), $logFile);
            $this->planModel->commonObj->addLog('userServiceData => ' . json_encode($userServiceData), $logFile);
            $this->planModel->commonObj->addLog('pendingSettlement => ' . json_encode($pendingSettlement), $logFile);
            //check if we need to filter through old or new user id - end

            //check if we can restore plan or not - start
            $isPlanRecurring = true;
            $alreadyCustomOnetime = false;
            $deactivateDate = null;
            $lastActivePlanFree = false;
            if (!empty($lastActivatedPlan) && !empty($lastPaymentActiveData) && !empty($userServiceData)) {
                if ($this->planModel->isFreePlan($lastActivatedPlan['plan_details']) || $this->planModel->isTrialPlan($lastActivatedPlan['plan_details'])) {
                    $lastActivePlanFree = true;
                    $canProceedWithPlanRestoration = false;
                } else {
                    //Case1: If plan was custom
                    if ((isset($lastActivatedPlan['plan_details']['custom_plan']) && $lastActivatedPlan['plan_details']['custom_plan']) || (isset($lastActivatedPlan['plan_details']['payment_type']) && $lastActivatedPlan['plan_details']['payment_type'] == Plan::BILLING_TYPE_ONETIME)) {
                        $paymentType = $lastActivatedPlan['plan_details']['payment_type'] ?? Plan::BILLING_TYPE_ONETIME;
                        if ($paymentType === Plan::BILLING_TYPE_ONETIME) {
                            $isPlanRecurring = false;
                            if (isset($userServiceData['deactivate_on']) && ($userServiceData['deactivate_on'] > date('Y-m-d'))) {
                                $alreadyCustomOnetime = true;
                                $canProceedWithPlanRestoration = true;
                            }
                        }
                    }

                    if ($isPlanRecurring) {
                        if(empty($uninstallDate)) {
                            $uninstallDate = $lastActivatedPlan['inactivated_on'] ?? date('Y-m-d');
                        }

                        $deactivateDate = $this->getDeactivatedDate($lastActivatedPlan['created_at'] ?? null, $lastActivatedPlan['plan_details']['billed_type'] ?? Plan::BILLED_TYPE_MONTHLY, $uninstallDate);
                        if ($deactivateDate > date('Y-m-d')) {
                            $canProceedWithPlanRestoration = true;
                        }
                    }
                }
            } else {
                return false;
            }

            $this->planModel->commonObj->addLog('uninstallDate => ' . $uninstallDate, $logFile);
            $this->planModel->commonObj->addLog('canProceedWithPlanRestoration => ' . $canProceedWithPlanRestoration, $logFile);
            $this->planModel->commonObj->addLog('deactivateDate => ' . $deactivateDate, $logFile);
            $this->planModel->commonObj->addLog('alreadyCustomOnetime => ' . $alreadyCustomOnetime, $logFile);
            $this->planModel->commonObj->addLog('isPlanRecurring => ' . $isPlanRecurring, $logFile);
            $this->planModel->commonObj->addLog('lastActivePlanFree => ' . $lastActivePlanFree, $logFile);

            //check if we can restore plan or not - end
            $hasPendingSettlement = false;
            if (!empty($lastActivatedPlan)) {
                if (isset($lastActivatedPlan['plan_details']['trial_days']) && isset($lastActivatedPlan['created_at'])) {
                    $purchasedDate = new DateTime($lastActivatedPlan['created_at']);
                    $currentDate = new DateTime('c');
                    $date6MonthBack = date('Y-m-d', strtotime('-6months'));
                    $uninstallDateFormat = new DateTime($uninstallDate);
                    $planActiveForDays = ($uninstallDateFormat->diff($purchasedDate)->d > 0)
                        ? $uninstallDateFormat->diff($purchasedDate)->d
                        : 0;
                    $uninstallDateFormated = date('Y-m-d', strtotime($uninstallDate));
                    $this->planModel->commonObj->addLog('planActiveForDays => ' . $planActiveForDays, $logFile);
                    if (((int)$lastActivatedPlan['plan_details']['trial_days'] <= 7) && ($planActiveForDays < 7) && ($uninstallDateFormated > $date6MonthBack)) {
                        $trialDays = (int)$lastActivatedPlan['plan_details']['trial_days'] - $planActiveForDays;
                        $trialDays = ($trialDays < 0) ? 0 : $trialDays;
                        $dataToAdd = [
                            'user_id' => $userId,
                            'trial_days_left' => $trialDays,
                            'created_at' => date('c'),
                            'type' =>'trial_days',
                            'last_plan_details' => $lastActivatedPlan['plan_details'] ?? []
                        ];
                        $this->planModel->commonObj->addLog('Since we have '.$trialDays.' reserved days for the trial we are adding an entry for the trial days!', $logFile);
                        $this->planModel->paymentCollection->replaceOne(
                            [
                                'user_id' => $userId,
                                'type' =>'trial_days'
                            ],
                            $dataToAdd,
                            ['upsert' => true]
                        );
                    }
                }

                if (!empty($pendingSettlement) && !empty($settlementQuote)) {
                    if (isset($pendingSettlement['settlement_amount']) && ((int)$pendingSettlement['settlement_amount'] >= 20)) {
                        $hasPendingSettlement = true;
                    }
                }

                //this is done to reset the user service credits of the users in case they reinstall and their usage is some to choose for new plan
                if (!$userIdChanged && $dataExistInCurrentPaymentCollection && !empty($userServiceData)) {
                    $updateData = [
                        'prepaid.total_used_credits' => 0,
                        'prepaid.available_credits' => $userServiceData['prepaid']['service_credits'] ?? 0
                    ];
                    $this->planModel->paymentCollection->updateOne(['_id' => $userServiceData['_id'], 'user_id' => $userId], ['$set' => $updateData]);
                }
                if (($deactivateDate <  date('Y-m-d', strtotime(' -2 month'))) && !$dataExistInCurrentPaymentCollection && $userIdChanged && !$hasPendingSettlement) {
                    $this->planModel->commonObj->addLog('since the last plan deactivate date is less than 15 days the data for this client is valid to be deleted! ', $logFile);
                    $uninstallPaymentCollection->deleteMany(['user_id' => $oldUserId]);
                }
            }

            // return false;//currently restoration of the plan is disabled and it is handled here so that trial can be processed

            $activateSync = false;
            $this->planModel->commonObj->addLog('hasPendingSettlement => ' . $hasPendingSettlement, $logFile);
            if ($canProceedWithPlanRestoration && $hasPendingSettlement) {
                $this->planModel->commonObj->addLog('processing with settlement restoration.', $logFile);
                $deactivateDate = date('Y-m-d');
                //now we check if user id changed or not
                $sourceId = $this->planModel->commonObj->getShopifyShopId() ?? null;

                //active plan update
                $lastActivatedPlan['status'] = Plan::USER_PLAN_STATUS_ACTIVE;
                $lastActivatedPlan['updated_at'] = date('Y-m-d H:i:s');
                $lastActivatedPlan['plan_restored'] = true;
                $lastActivatedPlan['user_id'] = $userId;
                $lastActivatedPlan['restored_on'] = date('Y-m-d H:i:s');
                $lastActivatedPlan['source_id'] = $sourceId;

                //last payment doc update
                $lastPaymentActiveData['plan_status'] = Plan::USER_PLAN_STATUS_ACTIVE;
                $lastPaymentActiveData['updated_at'] = date('Y-m-d H:i:s');
                $lastPaymentActiveData['user_id'] = $userId;
                $lastPaymentActiveData['plan_restored'] = true;
                $lastPaymentActiveData['restored_on'] = date('Y-m-d H:i:s');
                $lastPaymentActiveData['source_id'] = $sourceId;

                //user service update
                $userServiceData['updated_at'] = date('Y-m-d H:i:s');
                $userServiceData['user_id'] = $userId;
                $userServiceData['plan_restored'] = true;
                $userServiceData['source_id'] = $sourceId;
                if (!$alreadyCustomOnetime) {
                    $userServiceData['deactivate_on'] = $deactivateDate;
                    $lastActivatedPlan['plan_details']['custom_plan'] = true;
                    $lastActivatedPlan['plan_details']['payment_type'] = Plan::BILLING_TYPE_ONETIME;
                }

                //if user id changed then we need to add old_user_id and new user id will be replaced
                if ($userIdChanged) {
                    $lastInactivatedPlan['old_user_id'] = $oldUserId;
                    $lastPaymentActiveData['old_user_id'] = $oldUserId;
                    $userServiceData['old_user_id'] = $oldUserId;
                }

                if ($dataExistInCurrentPaymentCollection) {
                    try {
                        $planUpdateResult = $this->planModel->paymentCollection->replaceOne(
                            ['_id' => $lastActivatedPlan['_id'], 'type' => Plan::PAYMENT_TYPE_ACTIVE_PLAN],
                            $lastActivatedPlan,
                            ['upsert' => true]
                        );
                        if($planUpdateResult->getMatchedCount() === $planUpdateResult->getModifiedCount()) {
                            $this->planModel->commonObj->addLog('Updates for active plan done successfully!', $logFile);
                        } else {
                            $this->planModel->commonObj->addLog('Updates for active plan are compromised!', $logFile);
                        }

                        $paymentUpdateResult = $this->planModel->paymentCollection->replaceOne(
                            ['_id' => $lastPaymentActiveData['_id'], 'type' =>  Plan::PAYMENT_TYPE_PAYMENT],
                            $lastPaymentActiveData,
                            ['upsert' => true]
                        );
                        if($paymentUpdateResult->getMatchedCount() === $paymentUpdateResult->getModifiedCount()) {
                            $this->planModel->commonObj->addLog('Updates for payment done successfully!', $logFile);
                        } else {
                            $this->planModel->commonObj->addLog('Updates for payment are compromised!', $logFile);
                        }

                        $userserviceUpdateResult = $this->planModel->paymentCollection->replaceOne(
                            ['_id' => $userServiceData['_id'], 'type' => Plan::PAYMENT_TYPE_USER_SERVICE],
                            $userServiceData,
                            ['upsert' => true]
                        );
                        if($userserviceUpdateResult->getMatchedCount() === $userserviceUpdateResult->getModifiedCount()) {
                            $this->planModel->commonObj->addLog('Updates for userservice done successfully!', $logFile);
                        } else {
                            $this->planModel->commonObj->addLog('Updates for userservice are compromised!', $logFile);
                        }

                        if ($userIdChanged) {
                            $userIdUpdate = $this->planModel->paymentCollection->updateMany(['user_id' => $oldUserId], ['$set' => ['user_id' => $userId, 'source_id' => $sourceId]]);
                            $this->planModel->commonObj->addLog("Matched " . $userIdUpdate->getMatchedCount() . " document(s) and modified " . $userIdUpdate->getModifiedCount() . " document(s).", $logFile);
                            if($userIdUpdate->getMatchedCount() === $userIdUpdate->getModifiedCount()) {
                                $this->planModel->commonObj->addLog('Updation of latest user id done successfully!', $logFile);
                            } else {
                                $this->planModel->commonObj->addLog('There is some issues while updating data for new user id', $logFile);
                            }
                        }
                        $activateSync = true;
                    } catch (Exception $e) {
                        $this->planModel->commonObj->addLog('Some error occured ->'.json_encode($e->getMessage()), $logFile);
                        $activateSync = false;
                    }
                } else {
                    try {
                        $planUpdateResult = $uninstallPaymentCollection->replaceOne(
                            ['_id' => $lastActivatedPlan['_id'], 'type' => Plan::PAYMENT_TYPE_ACTIVE_PLAN],
                            $lastActivatedPlan,
                            ['upsert' => true]
                        );
                        if($planUpdateResult->getMatchedCount() === $planUpdateResult->getModifiedCount()) {
                            $this->planModel->commonObj->addLog('Updates for active plan done successfully!', $logFile);
                        } else {
                            $this->planModel->commonObj->addLog('Updates for active plan are compromised!', $logFile);
                        }

                        $paymentUpdateResult = $uninstallPaymentCollection->replaceOne(
                            ['_id' => $lastPaymentActiveData['_id'], 'type' => Plan::PAYMENT_TYPE_PAYMENT],
                            $lastPaymentActiveData,
                            ['upsert' => true]
                        );
                        if($paymentUpdateResult->getMatchedCount() === $paymentUpdateResult->getModifiedCount()) {
                            $this->planModel->commonObj->addLog('Updates for payment done successfully!', $logFile);
                        } else {
                            $this->planModel->commonObj->addLog('Updates for payment are compromised!', $logFile);
                        }

                        $userserviceUpdateResult = $uninstallPaymentCollection->replaceOne(
                            ['_id' => $userServiceData['_id'], 'type' => Plan::PAYMENT_TYPE_USER_SERVICE],
                            $userServiceData,
                            ['upsert' => true]
                        );
                        if($userserviceUpdateResult->getMatchedCount() === $userserviceUpdateResult->getModifiedCount()) {
                            $this->planModel->commonObj->addLog('Updates for userservice done successfully!', $logFile);
                        } else {
                            $this->planModel->commonObj->addLog('Updates for userservice are compromised!', $logFile);
                        }

                        if ($userIdChanged) {
                            $userIdUpdate = $uninstallPaymentCollection->updateMany(['user_id' => $oldUserId], ['$set' => ['user_id' => $userId, 'source_id' => $sourceId]]);
                            $this->planModel->commonObj->addLog("Matched " . $userIdUpdate->getMatchedCount() . " document(s) and modified " . $userIdUpdate->getModifiedCount() . " document(s).", $logFile);
                            if($userIdUpdate->getMatchedCount() === $userIdUpdate->getModifiedCount()) {
                                $this->planModel->commonObj->addLog('Updation of latest user id done successfully!', $logFile);
                            } else {
                                $this->planModel->commonObj->addLog('There is some issues while updating data for new user id', $logFile);
                            }
                        }

                        $uninstalledPaymentData = $uninstallPaymentCollection->find(['user_id' => $userId], Plan::TYPEMAP_OPTIONS)->toArray();
                        if (!empty($uninstalledPaymentData)) {
                            $restoreCountResult = $this->planModel->paymentCollection->insertMany($uninstalledPaymentData);
                            if ($restoreCountResult->getInsertedCount() === count($uninstalledPaymentData)) {
                                $this->planModel->commonObj->addLog('Insertion in payment_details successful, deleting data from uninstall collection', $logFile);
                                $deleteResult = $uninstallPaymentCollection->deleteMany(['user_id' => $userId]);
                                $this->planModel->commonObj->addLog("Deleted " . $deleteResult->getDeletedCount() . " document(s).", $logFile);
                                $activateSync = true;
                            } else {
                                $this->planModel->commonObj->addLog('Warning: Insertion in payment_details compromised!!', $logFile);
                                $this->planModel->commonObj->addLog('Data to be inserted: '.json_encode($uninstalledPaymentData), $logFile);
                            }
                        }
                    } catch (Exception $e) {
                        $this->planModel->commonObj->addLog('Some error occured ->'.json_encode($e->getMessage()), $logFile);
                        $activateSync = false;
                    }
                }
                //we need to activate sycing, we need to update source shop id in docs, we need to update configs as well
                if ($activateSync && !$hasPendingSettlement) {
                    $this->planModel->commonObj->addLog('Updating register for order sync!', $logFile);
                    $this->planModel->updateRegisterOrderSyncIfExist($userId);
                    $this->planModel->commonObj->addLog('Activating sync!', $logFile);
                    if (isset($userDetail['shops']) && !empty($userDetail['shops'])) {
                        $shopData = [];
                        foreach ($userDetail['shops'] as $shop) {
                            if ($shop['marketplace'] == Plan::SOURCE_MARKETPLACE) {
                                $shopData = $shop;
                            }
                        }
                        if (!empty($shopData)) {
                            $this->planModel->reUpdateEntry($userId, $shopData);
                        }
                    }
                    return true;
                }
            }
        } else {
            $this->planModel->commonObj->addLog('user not found => '. $userId, $logFile);
        }

        return false;
    }

    public function getDeactivatedDate($date = "", $billing = Plan::BILLED_TYPE_MONTHLY, $uninstallDate = "")
    {
        $uninstallDate = date('Y-m-d', strtotime($uninstallDate . ' -1 day'));
        $currentDate = date('Y-m-d');
        $deactivateDate = $currentDate;
        if ($billing == Plan::BILLED_TYPE_MONTHLY) {
            $activatedDate = new DateTime($date);
            $activatedDate->modify('+1 month');
            $nextMonthBilling = $activatedDate->format('Y-m-d');
            if ($nextMonthBilling > $currentDate && $nextMonthBilling > $uninstallDate && $nextMonthBilling != $currentDate) {
                return $nextMonthBilling;
            }

            if ($uninstallDate > $nextMonthBilling) {
                $uninstallDateTime = new DateTime($uninstallDate);
                $activatedDate->setDate(
                  $uninstallDateTime->format('Y'),
                  $uninstallDateTime->format('m'),
                  $activatedDate->format('d')
              );
                if($uninstallDate < $activatedDate->format('Y-m-d')) {
                  return $activatedDate->format('Y-m-d');
                }

                $activatedDate->modify('+1 month');
                $newBillingAsPerUninstallationMonth = $activatedDate->format('Y-m-d');
                if($newBillingAsPerUninstallationMonth > $uninstallDate && $newBillingAsPerUninstallationMonth > $currentDate && $newBillingAsPerUninstallationMonth != $currentDate) {
                    return $newBillingAsPerUninstallationMonth;
                }
            }
        } elseif ($billing == Plan::BILLED_TYPE_YEARLY) {
            $activatedDate = new DateTime($date);
            $activatedDate->modify('+1 year');
            $nextYearBilling = $activatedDate->format('Y-m-d');
            if ($nextYearBilling > $currentDate && $nextYearBilling > $uninstallDate && $nextYearBilling != $currentDate) {
                return $nextYearBilling;
            }

            if ($uninstallDate > $nextYearBilling) {
                $uninstallDateTime = new DateTime($uninstallDate);
                $activatedDate->setDate(
                  $uninstallDateTime->format('Y'),
                  $activatedDate->format('m'),
                  $activatedDate->format('d')
              );
                if($uninstallDate < $activatedDate->format('Y-m-d')) {
                 return $activatedDate->format('Y-m-d');
               }

                $activatedDate->modify('+1 year');
                $newBillingAsPerUninstallationYear = $activatedDate->format('Y-m-d');
                if($newBillingAsPerUninstallationYear > $uninstallDate && $newBillingAsPerUninstallationYear > $currentDate && $newBillingAsPerUninstallationYear != $currentDate) {
                    return $newBillingAsPerUninstallationYear;
                }
            }
        }

        return $deactivateDate;
    }

    public function canProceed($username)
    {
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $actionContainer = $baseMongo->getCollectionForTable('action_container');
        $filter = [
            'type' => 'restricted_users_to_restore',
            'action' => 'plan_restore_restrictions'
        ];
        $collectionData = $actionContainer->findOne($filter, Plan::TYPEMAP_OPTIONS);
        if (!empty($collectionData) && !empty($collectionData['users']) && in_array($username, $collectionData['users'])) {
            return false;
        }

        return true;
    }
}
