<?php

use App\Plan\Components\Common;
use App\Plan\Models\Plan\Restore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Plan\Models\BaseMongo as BaseMongo;

use App\Plan\Models\Plan as PlanModel;

class PlanDataRemoveCommand extends Command
{

    protected static $defaultName = "plan:remove-unused-data";

    protected static $defaultDescription = "Run Plan Unused Data Removal Script";
    
    protected $di;
    /**
     * Constructor
     * Calls parent constructor and sets the di
     *
     * @param $di
     */
    public function __construct($di)
    {
        parent::__construct();
        $this->di = $di;
    }

    /**
     * Configuration for the command
     * Used to set help text and add options and arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('To run any plan data remove script');
        $this->addOption(
            "user",
            "u",
            InputOption::VALUE_OPTIONAL,
            "user id",
            ""
        );
        $this->addOption(
            "limit",
            "l",
            InputOption::VALUE_OPTIONAL,
            "limit",
            ""
        );
        $this->addOption(
            "function",
            "f",
            InputOption::VALUE_OPTIONAL,
            "function name",
            ""
        );
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $f = $input->getOption("function") ?? "";
        echo 'Function passed: '.$f.PHP_EOL;
        if(!empty($f)) {
            echo 'Processing --- '.PHP_EOL;
            $this->$f($input);
            return 0;
        }

        echo 'Provide Function name!'.PHP_EOL;

        return 0;
    }

    /**
     * script to remove data that is not required
     */
    public function removeUnusedDataFromCollection($input = null): void
    {
        $user = $input->getOption("user") ?? false;
        $limit = $input->getOption("limit") ?? 500;
        $uninstallPaymentCollection = $this->di->getObjectManager()->get(BaseMongo::class)->getCollectionForTable('payment_details_uninstalled_users');
        $planModel = $this->di->getObjectManager()->get(PlanModel::class);
        $logFile = 'plan/cron/'.date('Y-m-d').'_removeUnusedDataFromCollection.log';
        if($user) {
            $userIds = $uninstallPaymentCollection->distinct("user_id", ['user_id' => $user]);
        } else {
            $userIds = $uninstallPaymentCollection->distinct("user_id", [], ['$limit' => $limit]);//we cannot fetch here by any type as it can be possible that some of the doc types may not present
        }

        if(!empty($userIds)) {
            $uninstallDate = "";
            $userDetailsCollection = $this->di->getObjectManager()->get(BaseMongo::class)->getCollectionForTable('user_details');
            $uninstallUserDetailsCollection = $this->di->getObjectManager()->get(BaseMongo::class)->getCollectionForTable('uninstall_user_details');
            $planModel->commonObj->addLog('Total users found: '.count($userIds), $logFile);
            $deleteUserIds = [];
            foreach($userIds as $userId) {
                $planModel->commonObj->addLog('processing for user: '.$userId, $logFile);
                $user = $userDetailsCollection->findOne(['user_id' => $userId], PlanModel::TYPEMAP_OPTIONS);
                if(empty($user)) {//if user not exist in current user details means we can move further
                    $planModel->commonObj->addLog('User not found in the user details.', $logFile);
                    $oldUserQuery = [//query to find user in uninstall user collection
                        ['$match' => [
                            'user_id' => $userId,
                            'shops' => ['$exists' => true]
                        ]],
                        [
                            '$unwind' => '$shops'
                        ],
                        [
                            '$match' => [
                                'shops.marketplace' => PlanModel::SOURCE_MARKETPLACE
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
                    $userInUninstall = $uninstallUserDetailsCollection->aggregate($oldUserQuery, PlanModel::TYPEMAP_OPTIONS)->toArray();
                    $userInUninstall = $userInUninstall[0] ?? [];
                    if(!empty($userInUninstall)) {//if user exists in uninstall collection we need to check if we can restore it or not
                        //first we will fetch its all last activated plans
                        $planModel->commonObj->addLog('User found in the uninstall user details.', $logFile);
                        if(isset($userInUninstall['shops']['apps'])) {
                            foreach($userInUninstall['shops']['apps'] as $app) {
                                if(isset($app['uninstall_date'])) {
                                    $uninstallDate = $app['uninstall_date'];
                                }
                            }
                        }

                        $queryToFindActivePlan = [
                            ['$match' => [
                                'type' => 'active_plan',
                                'user_id' => $userId
                            ]],
                            ['$sort' => ['created_at' => -1]],
                            ['$limit' => 1]
                        ];
                        $activePlanData = $uninstallPaymentCollection->aggregate($queryToFindActivePlan, PlanModel::TYPEMAP_OPTIONS)->toArray();
                        $lastActivatedPlan = !empty($activePlanData[0]) ? $activePlanData[0] : (!empty($activePlanData) ? $activePlanData : []);
                        if(!empty($lastActivatedPlan)) {
                            $planModel->commonObj->addLog('Plan found for restoration!', $logFile);
                            //first we will check its possiblity to be restoration
                            $canBeRestored = $this->canPlanBeRestored($lastActivatedPlan, $uninstallDate, $userId);
                            if($canBeRestored) {
                                $planModel->commonObj->addLog('Plan Can be restored for this this user!', $logFile);
                            } else {
                                $deleteUserIds[] = $userId;
                                $planModel->commonObj->addLog('Plan cannot be restored for this this user so deleting data!', $logFile);
                            }
                        } else {
                            $deleteUserIds[] = $userId;
                            $planModel->commonObj->addLog('No last plan for activation so removing data of this client.....', $logFile);
                        }
                    } else {
                        $deleteUserIds[] = $userId;
                        $planModel->commonObj->addLog('User not found in uninstall user details as well so adding for delete ----', $logFile);
                    }
                } else {
                    $planModel->commonObj->addLog('User exist in current details db', $logFile);
                }
            }

            if(!empty($deleteUserIds)) {
                $planModel->commonObj->addLog('Deleted user ids => '.json_encode($deleteUserIds, true), $logFile);
                $deleteResult = $uninstallPaymentCollection->deleteMany(["user_id" => ['$in' => $deleteUserIds]]);
                $planModel->commonObj->addLog('Deleted count => '.$deleteResult->getDeletedCount(), $logFile);
                echo 'Deleted count => '.$deleteResult->getDeletedCount().PHP_EOL;
                $transactionDetails = $this->di->getObjectManager()->get(BaseMongo::class)->getCollectionForTable('transaction_details');
                $transactionDeleteResult = $transactionDetails->deleteMany(["user_id" => ['$in' => $deleteUserIds]]);
                $planModel->commonObj->addLog('Transaction Deleted count => '.$transactionDeleteResult->getDeletedCount(), $logFile);
                echo 'Transaction Deleted count => '.$transactionDeleteResult->getDeletedCount().PHP_EOL;
            } else {
                $planModel->commonObj->addLog('No user to delete data!', $logFile);
                echo 'No user to delete data!'.PHP_EOL;
            }
        } else {
            $planModel->commonObj->addLog('No users found!', $logFile);
            print_r('No users found!');
        }

        $planModel->commonObj->addLog('Script completed!', $logFile);
        echo 'Script completed!';
    }

    /**
     * to check if plan can be restored or not
     */
    public function canPlanBeRestored($lastActivatedPlan, $uninstallDate, $userId)
    {
        $planCanBeRestored = false;
        $isPlanRecurring = true;
        $deactivateDate = "";
        if (!empty($lastActivatedPlan)) {
            /**
             * this is added so that we cannot delete the data for new users within a time period of 2 months
             */
            if (isset($lastActivatedPlan['created_at']) && ($lastActivatedPlan['created_at']) > date('Y-m-d', strtotime('-2month'))) {
                return true;
            }

            if (isset($lastActivatedPlan['title']) && (($lastActivatedPlan['title'] === 'Free') || ($lastActivatedPlan['custom_price'] == 0))) {
                return false;
            }

            if ((isset($lastActivatedPlan['plan_details']['custom_plan']) && $lastActivatedPlan['plan_details']['custom_plan']) || (isset($lastActivatedPlan['plan_details']['payment_type']) && $lastActivatedPlan['plan_details']['payment_type'] == PlanModel::BILLING_TYPE_ONETIME)) {
                $paymentType = $lastActivatedPlan['plan_details']['payment_type'] ?? PlanModel::BILLING_TYPE_ONETIME; //by default custom plans are onetime
                if ($paymentType == PlanModel::BILLING_TYPE_ONETIME) {
                    $isPlanRecurring = false;
                    $userServiceQuery = [
                        ['$match' => [
                            'type' => PlanModel::PAYMENT_TYPE_USER_SERVICE,
                            'service_type' => PlanModel::SERVICE_TYPE_ORDER_SYNC,
                            'user_id' => $userId,
                        ]],
                        ['$sort' => ['created_at' => -1]],
                        ['$limit' => 1]
                    ];
                    $uninstallPaymentCollection = $this->di->getObjectManager()->get(BaseMongo::class)->getCollectionForTable('payment_details_uninstalled_users');
                    $userServiceData = $uninstallPaymentCollection->aggregate($userServiceQuery, PlanModel::TYPEMAP_OPTIONS)->toArray();
                    if (isset($userServiceData['deactivate_on']) && ($userServiceData['deactivate_on'] >= date('Y-m-d'))) {//deactivate date should be more than or eqaul to the current date
                        $planCanBeRestored = true;
                        $deactivateDate = $userServiceData['deactivate_on'];
                    }
                }
            }

            if ($isPlanRecurring) {
                if(empty($uninstallDate)) {
                    $uninstallDate = $lastActivatedPlan['inactivated_on'] ?? $lastActivatedPlan['updated_at'] ?? date('Y-m-d');
                }

                $deactivateDate = $this->di->getObjectManager()->get(Restore::class)->getDeactivatedDate($lastActivatedPlan['created_at'] ?? null, $lastActivatedPlan['plan_details']['billed_type'] ?? PlanModel::BILLED_TYPE_MONTHLY, $uninstallDate);
                if ($deactivateDate >= date('Y-m-d')) {
                    $planCanBeRestored = true;
                }
            }
        }

        return $planCanBeRestored;
    }

    /**
     * script to remove expired quotes, don't use as there can be the quotes of settlement as well
     */
    public function deleteExpiredQuotes($input = null): int
    {
        $userId = $input->getOption("user") ?? false;
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $collection = $baseMongo->getCollectionForTable(PlanModel::PAYMENT_DETAILS_COLLECTION_NAME);
        $currentDate = date('Y-m-d', strtotime("-10 days"));
        $filterData = [
            "type" => PlanModel::PAYMENT_TYPE_QUOTE,
            'source_marketplace' => PlanModel::SOURCE_MARKETPLACE,
            'target_marketplace' => PlanModel::TARGET_MARKETPLACE,
            "expire_at" => [
                '$lt' => $currentDate
            ],
            "status" => [
                '$in' => [
                    PlanModel::QUOTE_STATUS_ACTIVE,
                    PlanModel::QUOTE_STATUS_WAITING_FOR_PAYMENT
                ]
            ]
        ];
        if ($userId) {
            $filterData['user_id'] = $userId;
        }

        $deleteRes = $collection->deleteMany($filterData);
        echo 'Deleted quote count => '.$deleteRes->getDeletedCount().PHP_EOL;
        return 1;
    }

    /**
     * script to remove metrics data
     */
    public function removeMetricsData(): void
    {
        print_r("Executing command for removing sales data from action container\n");
        $date = date('Y-m-d', strtotime('-3 months'));
        $actionDataFilter = [
            'type' => 'sales_metrics_info',
            'action' => 'plan_recommendations',
            'created_at' => ['$lt' => $date]
        ];
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $actionCollection = $baseMongo->getCollectionForTable('action_container');
        $actionCollection->deleteMany($actionDataFilter, PlanModel::TYPEMAP_OPTIONS);
        print_r("Executed successfully!");
    }

    public function removeData($input = null): void
    {
        $logFile = 'plan/script/data_removal_'.date('Y-m-d').'.log';
        try {
            $commonObj = $this->di->getObjectManager()->get(Common::class);
            $uninstallPaymentDetailsCollection = $this->di->getObjectManager()->get(BaseMongo::class)->getCollectionForTable('payment_details_uninstalled_users');
            $entryForValidation = $uninstallPaymentDetailsCollection->findOne(['type' => 'last_removal_script_run'], PlanModel::TYPEMAP_OPTIONS);
            if (!empty($entryForValidation)) {
                if ($entryForValidation['last_run_at'] > date('Y-m-d', strtotime('-1 month'))) {
                    $commonObj->addLog("Script already run for the cycle", $logFile);
                    echo 'Script already run for the cycle';
                    return;
                } else {
                    $entryForValidation['last_run_at'] = date('Y-m-d H:i:s');
                }
            } else {
                $entryForValidation['type'] = 'last_removal_script_run';
                $entryForValidation['created_at'] = date('Y-m-d H:i:s');
                $entryForValidation['last_run_at'] = date('Y-m-d H:i:s');
            }
            $entry = $uninstallPaymentDetailsCollection->findOne(['type' => 'removal_script'], PlanModel::TYPEMAP_OPTIONS);
            $skip = 0;
            $limit = 250;
            if (!empty($entry)) {
                $limit = (int)($entry['limit']);
                $skip = (int)($entry['limit'] * $entry['skip']);
                $entry['skip'] = $entry['skip'] + 1;
                $entry['updated_at'] = date('Y-m-d H:i:s');
            } else {
                $uninstallUserDetailsCollection = $this->di->getObjectManager()->get(BaseMongo::class)->getCollectionForTable('uninstall_user_details');
                $total = $uninstallUserDetailsCollection->countDocuments([]);
                $entry['type'] = 'removal_script';
                $entry['limit'] = $limit;
                $entry['skip'] = $skip;
                $entry['total'] = $total;
                $entry['created_at'] = date('Y-m-d H:i:s');
                $entry['updated_at'] = date('Y-m-d H:i:s');
            }
            $uninstallUsers = $this->getUninstallData($skip, $limit);
            $totalUsers = [];
            $restoredUser = [];
            if (!empty($uninstallUsers)) {
                $commonObj->addLog("Total users found: ".count($uninstallUsers), $logFile);
                foreach($uninstallUsers as $users) {
                    if (!empty($users['users'])) {
                        $ids = array_column($users['users'], 'user_id');
                        $totalUsers[] = $ids;
                        $pipeline = [
                            ['$match' => [
                                'user_id' => ['$in' => $ids],
                                'type' => 'active_plan',
                                'picked_for_check' => ['$exists' => false],
                                // 'plan_details.title' => ['$ne' => 'Free']
                            ]],
                            ['$sort' => ['created_at' => -1]],
                            ['$limit' => 1]
                        ];
                        $lastActivePlan = $uninstallPaymentDetailsCollection->aggregate($pipeline, PlanModel::TYPEMAP_OPTIONS)->toArray();
                        if (!empty($lastActivePlan)) {
                            $lastActivePlan = $lastActivePlan[0] ?? $lastActivePlan;
                            foreach($users['users'] as $user) {
                                if ($user['user_id'] == $lastActivePlan['user_id']) {
                                    $lastShop = $user['last_shop'] ?? [];
                                    $uninstallDate = $lastShop['apps']['uninstall_date'] ?? "";
                                    $isPlanRecurring = true;
                                    $deactivateDate = null;
                                    if ((isset($lastActivePlan['plan_details']['custom_plan']) && $lastActivePlan['plan_details']['custom_plan']) || (isset($lastActivePlan['plan_details']['payment_type']) && $lastActivePlan['plan_details']['payment_type'] == PlanModel::BILLING_TYPE_ONETIME)) {
                                        $paymentType = $lastActivePlan['plan_details']['payment_type'] ?? PlanModel::BILLING_TYPE_ONETIME;
                                        if ($paymentType === PlanModel::BILLING_TYPE_ONETIME) {
                                            $isPlanRecurring = false;
                                            $userServicePipeline = [
                                                ['$match' => [
                                                    'user_id' => $user['user_id'],
                                                    'type' => 'user_service',
                                                    'service_type' => PlanModel::SERVICE_TYPE_ORDER_SYNC
                                                ]],
                                                ['$sort' => ['created_at' => -1]],
                                                ['$limit' => 1]
                                            ];
                                            $userServiceData = $uninstallPaymentDetailsCollection->aggregate($userServicePipeline, PlanModel::TYPEMAP_OPTIONS)->toArray();
                                            if (!empty($userServiceData[0]) && isset($userServiceData[0]['deactivate_on'])) {
                                                $deactivateDate = $userServiceData[0]['deactivate_on'];
                                            }
                                        }
                                    }
                                    if ($isPlanRecurring) {
                                        if(empty($uninstallDate)) {
                                            $uninstallDate = $lastActivePlan['inactivated_on'] ?? date('Y-m-d');
                                        }
                                        $deactivateDate = $this->getDeactivatedDate($lastActivePlan['created_at'], $lastActivePlan['billing_type'] ?? PlanModel::BILLED_TYPE_MONTHLY, $uninstallDate);
                                    }
                                    if (!is_null($deactivateDate) && ($deactivateDate > date('Y-m-d', strtotime('-2 month')))) {
                                        $restoredUser[] = $user['user_id'];
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                echo 'No users found!';
                $commonObj->addLog("No users found! ", $logFile);
                if ($skip >  $entry['total']) {
                    $commonObj->addLog("Process completed for all users!", $logFile);
                    $additionalUsers = $uninstallPaymentDetailsCollection->distinct("user_id", ['type' => 'active_plan', 'picked_for_check' => ['$exists' => false]]);
                    $userDetailsCollection = $this->di->getObjectManager()->get(BaseMongo::class)->getCollectionForTable('user_details');
                    $existingUsers = $userDetailsCollection->distinct("user_id", ['user_id' => ['$in' => $additionalUsers]]);
                    $usersToRemove = array_diff($additionalUsers, $existingUsers);
                    if (!empty($additionalUsers)) {
                        $commonObj->addLog("usersToRemove in last call:  ".json_encode($usersToRemove, true), $logFile);
                        $commonObj->addLog("existingUsers in last call:  ".json_encode($existingUsers, true), $logFile);
                        $uninstallPaymentDetailsCollection->deleteMany(['user_id' => ['$in' => $usersToRemove]]);
                    }
                    $uninstallPaymentDetailsCollection->deleteOne(['type' => 'removal_script']);
                    $uninstallPaymentDetailsCollection->replaceOne(['type' => 'last_removal_script_run'], $entryForValidation, ['upsert' => true]);
                    $uninstallPaymentDetailsCollection->updateMany(['type' => 'active_plan'], ['$unset' => ['picked_for_check' => 1]]);
                    $commonObj->addLog("last_removal_script_run:  ".json_encode($entryForValidation, true), $logFile);
                    echo 'Script finished!';
                    return;
                }
            }
            $uninstallPaymentDetailsCollection->replaceOne(['type' => 'removal_script'], $entry, ['upsert' => true]);
            if (!empty($totalUsers)) {
                $totalUsers = array_merge(...$totalUsers);
                $commonObj->addLog("totalUsers:  ".json_encode($totalUsers, true), $logFile);
                $usersToDelete = array_diff($totalUsers, $restoredUser);
                $commonObj->addLog("usersToDelete:  ".json_encode($usersToDelete, true), $logFile);
                $commonObj->addLog("restoredUser:  ".json_encode($restoredUser, true), $logFile);
                $uninstallPaymentDetailsCollection->updateMany(['user_id' => ['$in' => $restoredUser], 'type' => 'active_plan'], ['$set' => ['picked_for_check' => true]]);
                $uninstallPaymentDetailsCollection->deleteMany(['user_id' => ['$in' => $usersToDelete]]);
                echo 'Total users deleted: '. count($usersToDelete);
            }
            return;
        } catch (Exception $e) {
            $this->di->getLog()->logContent("Error in executing script: ".json_encode($e->getMessage(), true), 'info', $logFile);
        }
    }

    public function getDeactivatedDate($date = "", $billing = PlanModel::BILLED_TYPE_MONTHLY, $uninstallDate = "")
    {
        $uninstallDate = date('Y-m-d', strtotime($uninstallDate . ' -1 day'));
        $currentDate = date('Y-m-d');
        $deactivateDate = $currentDate;
        if ($billing == PlanModel::BILLED_TYPE_MONTHLY) {
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
        } elseif ($billing == PlanModel::BILLED_TYPE_YEARLY) {
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

     
     public function getUninstallData($skip = 0, $limit = 250)
     {
        $pipeline = [
            [
                '$skip' => $skip
            ],
            [
                '$limit' => $limit
            ],
            [
                '$unwind' => '$shops'
            ],
            [
                '$match' => [
                    "shops.marketplace" => "shopify"
                ]
            ],
            [
                '$unwind' => '$shops.apps'
            ],
            [
                '$group' => ["_id" => [
                    "username" => '$username',
                    "user_id"=> '$user_id'
                ],
                  "shops"=> [
                    '$push' => [
                      "marketplace" => '$shops.marketplace',
                      "installation_date" => '$shops.created_at',
                      "uninstall_date"=> '$shops.apps.uninstall_date',
                      "shop_data"=> '$shops'
                    ]
                  ]
                ]
                ],
                ['$project' => [
                    "shops" => [
                        '$filter' => [
                        "input" => '$shops',
                        "as" => "shop",
                        "cond" => ['$eq' => ['$$shop.marketplace', "shopify"] ]
                    ]
                    ]
                ]],
                [
                    '$unwind' => '$shops'
                ],
                [
                    '$sort' => [
                        "shops.uninstall_date"=> -1,
                        "shops.installation_date" => -1
                    ]
                ],
                [
                    '$group' => [
                        "_id" => '$_id',
                        "last_shop" => ['$first' => '$shops.shop_data']
                        
                ]
                ],
                [
                    '$group' => [
                        "_id"=> '$_id.username',
                            "users"=> [
                              '$push'=> [
                                "user_id"=> '$_id.user_id',
                                "last_shop"=>'$last_shop'
                              ]
                            ]
                    ]
                ],
                [
                    '$project' => [
                        "_id"=> 0,
                        "username"=>'$_id',
                        "users"=> [
                          '$map'=> [
                            "input"=> '$users',
                            "as"=> "user",
                            "in"=> [
                              "user_id"=> '$$user.user_id',
                              "last_shop"=> '$$user.last_shop'
                            ]
                          ]
                        ]
                    ]
                ]
            ];
            
            $uninstallUserDetailsCollection = $this->di->getObjectManager()->get(BaseMongo::class)->getCollectionForTable('uninstall_user_details');
            return $uninstallUserDetailsCollection->aggregate($pipeline, PlanModel::TYPEMAP_OPTIONS)->toArray();
     }
}
