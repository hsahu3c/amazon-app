<?php

use App\Core\Models\User;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use App\Plan\Models\BaseMongo as BaseMongo;

use App\Plan\Models\Plan as PlanModel;
use App\Plan\Components\Common;
use App\Plan\Components\Method\ShopifyPayment;
use Exception;

class PlanScriptCommand extends Command
{

    protected static $defaultName = "plan:run-script";

    protected static $defaultDescription = "Run Plan Script";

    protected $di;

    protected $output;
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
        $this->setHelp('To run any plan script');
        //$this->addOption("user_id", "u", InputOption::VALUE_OPTIONAL, "user_id of the user");
        $this->addOption(
            "skip",
            "s",
            InputOption::VALUE_OPTIONAL,
            "skip db",
            ""
        );
        $this->addOption(
            "function",
            "f",
            InputOption::VALUE_OPTIONAL,
            "run function",
            ""
        );
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
            "plan_type",
            "p",
            InputOption::VALUE_OPTIONAL,
            "plan type (free or paid)",
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
        $this->output = $output;
        print_r("Executing command to run scripts \n");
        $f = $input->getOption("function") ?? "";
        if(empty($f)) {
            echo 'Provide a valid function!';
            return 0;
        }

        $this->$f($input);
        return 0;
    }

    // public function updateSourceId($paymentCollection)
    // {
    //     $userIds = $paymentCollection->distinct('user_id', []);

    //     foreach ($userIds as $userId) {
    //         $res = $this->setDiForUser($userId);
    //         if (!$res['success']) {
    //             $userNotFound[] = $userId;
    //         }
    //         $filterQuery = ['user_id' => $userId];
    //         $shopIdRes = $this->getShopifyShopId();
    //         if (!$shopIdRes['success']) {
    //             $shopNotFound[] = $userId;
    //         }
    //         $shopId = $shopIdRes['data'];
    //         $updateData = ['$set' => ['source_id' => $shopId]];
    //         $paymentCollection->updateMany($filterQuery, $updateData);
    //     }
    //     echo 'shop not found';
    //     print_r($shopNotFound);
    //     echo 'user not found';
    //     print_r($userNotFound);
    // }

    public function getShopifyShopId(): array
    {
        $shops = $this->di->getUser()->shops;

        $shopId = null;
        foreach ($shops as $shop) {
            if ($shop['marketplace'] == 'shopify') {
                $shopId = $shop['_id'];
                break;
            }
        }

        if ($shopId == null) {
            return [
                'success' => false,
                'message' => 'shop_id not found for shopify.'
            ];
        }

        return [
            'success' => true,
            'data' => $shopId
        ];
    }

    public function setDiForUser($userId): array
    {
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

    // public function getRevenueData($filter = [])
    // {
    //     $plans = [
    //         0 => 50,
    //         15 => 100,
    //         49 => 500,
    //         89 => 1000,
    //         159 => 2500,
    //         259 => 5000,
    //         459 => 10000,
    //         659 => 25000,
    //         859 => 1000000
    //     ];
    //     $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
    //     $collection = $baseMongo->getCollectionForTable('payment_details');
    //     $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
    //     $matchQuery = [
    //         '$match' => [
    //             "type" => "active_plan",
    //             "status" => "active",
    //             "test_user" => [
    //                 '$exists' => false,
    //             ],
    //             'plan_details.custom_price' => [
    //                 '$ne' => 0
    //             ]
    //         ]
    //     ];
    //     $projection = [
    //         '$project' => [
    //             "user_id" => 1,
    //             "plan_details.custom_price" => 1
    //         ]
    //     ];
    //     $groupQuery = [
    //         '$group' => [
    //             "_id" => '$plan_details.custom_price',
    //             "user_ids" => [
    //                 '$addToSet' => '$user_id'
    //             ]
    //         ]
    //     ];
    //     $planUsersData = $collection->aggregate([
    //        $matchQuery, $projection, $groupQuery
    //     ], $options)->toArray();
    //     $revenue= 0;
    //     $actualRevenue= 0;
    //     if (!empty($planUsersData)) {
    //         foreach ($planUsersData as $planWiseUserData) {
    //             $planPrice = $planWiseUserData['_id'];
    //             if ($planPrice != 0) {
    //                 $planCredits = 0;
    //                 $actualPlanPrice = 0;
    //                 $yearly = false;
    //                 if (isset($plans[(int)$planPrice])) {
    //                     $planCredits = $plans[(int)$planPrice];
    //                 } else {
    //                     $quote = $this->getPlanOnTheBasisOfQuote($planPrice);
    //                     if (!empty($quote) && isset($quote['plan_details'])) {
    //                         if ($quote['plan_details']['billed_type'] == 'yearly') {
    //                             $actualPlanPrice = $planPrice;
    //                             if (isset($quote['plan_details']['discounts'])) {
    //                                 $planPrice = $this->getDiscountedAmount($quote['plan_details'], $planPrice);
    //                             }
    //                             $planPrice = $planPrice / 12;
    //                             $yearly = true;
    //                         }
    //                         $planCredits = (int)$quote['plan_details']['services_groups'][0]['services'][0]['prepaid']['service_credits'] ?? 0;
    //                     }
    //                 }
    //                 $users = $planWiseUserData['user_ids'];
    //                 if (!empty($users) && $planCredits) {
    //                     $usersCount = count($users);
    //                     $calculated= 0;
    //                     $calculated += $this->calcRevenuePerPlan($users, $planCredits, $planPrice, $plans, $usersCount, $actualPlanPrice, $yearly);
    //                     $revenue += $calculated;
    //                     $actualRevenue += ($planPrice * $usersCount);
    //                     // echo $planPrice." ";
    //                     // echo $calculated." <br>";
    //                 }
    //             }
    //         }
    //     }
    //     echo 'Predicted Revenue: ' . $revenue."  ";
    //     echo 'Actual Revenue: ' . $actualRevenue;
    // }
    // public function getPlanOnTheBasisOfQuote($planPrice)
    // {
    //     $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
    //     $collection = $baseMongo->getCollectionForTable('payment_details');
    //     $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
    //     return $collection->findOne([
    //         'type' => 'quote',
    //         "plan_details.custom_price" => $planPrice,
    //         "plan_details.services_groups" => [
    //             '$exists' => true
    //         ]
    //     ], $options);
    // }
    // public function calcRevenuePerPlan($users, $planCredits, $planPrice, $plans, &$usersCount, $actualPlanPrice = 0, $yearly = false)
    // {
    //     $planModel = $this->di->getObjectManager()->get(PlanModel::class);
    //     $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
    //     $collection = $baseMongo->getCollectionForTable('payment_details');
    //     $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
    //     $usersCount = 0;
    //     $perDollarOrders = $planCredits / $planPrice;
    //     $totalCreditsUsed = 0;
    //     foreach ($users as $user) {
    //         $diResponse = $this->setDiForUser($user);
    //         if ($diResponse['success']) {
    //             $usersCount += 1;
    //             $userService = $collection->findOne([
    //                 'type' => 'user_service',
    //                 'user_id' => $user
    //             ], $options);
    //             if (!empty($userService) && isset($userService['prepaid'])) {
    //                 $totalCreditsUsed += $userService['prepaid']['total_used_credits'];
    //             }
    //         }
    //     }
    //     $orderPredictedPerMonth = $this->calcPredictedOrderCount($totalCreditsUsed);
    //     $actualCount = ($planCredits * $usersCount);
    //     $orderCount = $actualCount - $orderPredictedPerMonth;
    //     $perDollarOrdersForNextPlan = 0;
    //     $revenue = 0;
    //     //$revenue = $orderPredictedPerMonth / $perDollarOrders;
    //     if ($orderCount > 0) {
    //         $revenue = $orderPredictedPerMonth / $perDollarOrders;
    //     } elseif ($orderCount < 0) {
    //         $exceedCount = $orderPredictedPerMonth - $actualCount;
    //         if ($yearly == true) {
    //             $nextPlan = $this->getNextPlanYearly($actualPlanPrice, []);
    //         } else {
    //             $nextPlan = $this->getNextPlan($planPrice, $plans);
    //         }
    //         if (!empty($nextPlan) && $exceedCount) {
    //             $perDollarOrdersForNextPlan = $nextPlan['credits'] / $nextPlan['price'];
    //             $revenue += $exceedCount / $perDollarOrdersForNextPlan;
    //         }
    //         $revenue += ($planPrice * $usersCount);
    //     } elseif ($orderCount == 0) {
    //         $revenue = ($planPrice * $usersCount);
    //     }
    //     return $revenue;
    // }
    // public function calcPredictedOrderCount($totalCreditsUsed)
    // {
    //     $currentDate = date('d', strtotime('now'));
    //     $lastDate = date('d', strtotime('last day of this month'));
    //     return ($totalCreditsUsed / $currentDate) * $lastDate;
    // }
    // public function getNextPlan($planPrice, $plans)
    // {
    //     foreach ($plans as $key => $plan) {
    //         if ($key > $planPrice) {
    //             return [
    //                 'price' => $key,
    //                 'credits' => $plan
    //             ];
    //         }
    //     }
    //     return [];
    // }
    // public function getNextPlanYearly($planPrice, $plan = [])
    // {
    //     $plans = [
    //         0 => 50,
    //         180 => 100,
    //         588 => 500,
    //         1068 => 1000,
    //         1908 => 2500,
    //         3108 => 5000,
    //         5508 => 10000,
    //         7908 => 25000,
    //         10308 => 1000000
    //     ];
    //     $price = 0;
    //     foreach ($plans as $key => $plan) {
    //         if ($key > $planPrice) {
    //             $price = $key / 12;
    //             return [
    //                 'price' => $price,
    //                 'credits' => $plan
    //             ];
    //         }
    //     }
    //     return [];
    // }
    // public function getDiscountedAmount($planDetails, $amount)
    // {
    //     $discountedAmount = 0;
    //     if (isset($planDetails['discounts']) && !empty($planDetails['discounts'])) {
    //         foreach ($planDetails['discounts'] as $discount) {
    //             if (isset($discount['type']) && isset($discount['value'])) {
    //                 switch ($discount['type']) {
    //                     case 'percentage':
    //                         $discountedAmount += ($amount * ((float)$discount['value']) / 100);
    //                         break;
    //                     case 'fixed':
    //                         $discountedAmount += (float)$discount['value'];
    //                         break;
    //                     default:
    //                         $discountedAmount += 0;
    //                         break;
    //                 }
    //             }
    //         }
    //     }
    //     return $amount - $discountedAmount;
    // }
    /**
     * to check if users are active or not, if not deactivate them and if they didn't exist move their data to another collection
     */
    public function checkActiveUsers($skip): void
    {
        $planModel = $this->di->getObjectManager()->get(PlanModel::class);
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $paymentCollection = $baseMongo->getCollectionForTable('payment_details');
        $bkpPaymentCollection = $baseMongo->getCollectionForTable('payment_details_uninstalled_users');
        $logFile = 'plan/'.date('Y-m-d').'/check_active_plans.log';
        $size = 100;
        $typeMapOptions['typeMap'] = ['root' => 'array', 'document' => 'array'];
        $options = $typeMapOptions;
        $options['limit'] = $size;
        $options['skip'] = $skip * $size;

        $query = [
            'type' => PlanModel::PAYMENT_TYPE_USER_SERVICE,
            'source_marketplace' => PlanModel::SOURCE_MARKETPLACE,
            'target_marketplace' => PlanModel::TARGET_MARKETPLACE,
            'prepaid.service_credits' => ['$gt' => 50]
        ];
        $userServices = $paymentCollection->find($query, $options)->toArray();

        if (empty($userServices)) {
            echo 'There are no user services available';
            return;
        }

        $notFoundUsers = [];
        foreach ($userServices as $userService) {
            if (isset($userService['user_id'])) {
                $userId = $userService['user_id'];
                print_r("\nProcessing for user - {$userId} ---------- \n");
                $diResponse = $this->setDiForUser($userId);
                if ($diResponse['success']) {
                    print_r("- DI set for the user \n");
                    $planModel->addLog('Di set successfully for user: '.$userId, $logFile);
                    $activePlan = $planModel->getActivePlanForCurrentUser($userId);
                    if (!empty($activePlan) && isset($activePlan['plan_details'])) {
                        $planDetails = $activePlan['plan_details'];
                        if ((isset($planDetails['title'], $planDetails['custom_price'])
                        && ((strtolower((string) $planDetails['title']) == 'free') || ((int)$planDetails['custom_price'] == 0)))
                        || (isset($planDetails['custom_plan']) && $planDetails['custom_plan'])
                        ) {
                            if (isset($planDetails['custom_plan']) && $planDetails['custom_plan']) {
                                print_r("- Custom plan user  \n");
                            } else {
                                print_r("- Free plan user \n");
                            }

                            $planModel->addLog('Free plan or custom plan user: '.$userId, $logFile);
                            continue;
                        }

                        $paymentDetail = $planModel->getPaymentInfoForCurrentUser($userId);
                        $shop = $planModel->getShop($planModel->commonObj->getShopifyShopId(), $userId);
                        if (empty($shop)) {
                            print_r("- Empty shop \n");
                            $planModel->addLog('Shop not found for user: '. $userId, $logFile);
                        } else {
                            if ($paymentDetail && isset($paymentDetail['marketplace_data']['id'])) {
                                $chargeId = $paymentDetail['marketplace_data']['id'];
                                $isActive = true;
                                $activeCharges = [];
                                if ($shop && isset($shop['remote_shop_id'])) {
                                    $data['shop_id'] = $shop['remote_shop_id'];
                                    $data['id'] = $chargeId;
                                    print_r("- Fetching data from Shopify \n");
                                    $response = $this->di->getObjectManager()->get(ShopifyPayment::class)->initApiClient()->call('billing/recurring', [], $data, 'GET');
                                    if (isset($response['data'])) {
                                        $response = $response['data'];
                                        print_r("- DB chargeId status on Shopify - {$response['status']} \n");
                                        if (isset($response['id']) && $response['status'] != 'active') {
                                            print_r("Payment status changed \n");
                                            $isActive = false;
                                            $planModel->addLog('Current payment recurring not active for user: '.$userId.' Username => '. $this->di->getUser()->getConfig()['username'], $logFile);
                                        }

                                        if (!$isActive) {
                                            $getRecurringData['shop_id'] = $shop['remote_shop_id'];
                                            $getResponse = $this->di->getObjectManager()->get(ShopifyPayment::class)->initApiClient()->call('billing/recurring', [], $getRecurringData, 'GET');
                                            if (isset($getResponse['data'])) {
                                                $getResponse = $getResponse['data'];
                                                $activeCharges = array_filter($getResponse, fn(array $charge): bool => $charge['status'] == 'active');
                                            }

                                            if (empty($activeCharges)) {
                                                $isActive = false;
                                            } else {
                                                print_r("- New active charge found\n");
                                                $planModel->addLog('Active recurring charges data: '.json_encode($activeCharges, true), $logFile);
                                            }
                                        }
                                    }

                                    if (!$isActive && empty($activeCharges)) {
                                        print_r("- No active plan, deactivating plan\n");
                                        $planModel->addLog('No active recurring for user: '.$userId.' Username => '. $this->di->getUser()->getConfig()['username'], $logFile);
                                        $planModel->addLog('Deactivating user', $logFile);
                                        $planModel->deactivatePlan('paid', $userId, $chargeId);
                                    } else {
                                        print_r("- User has an active plan\n");
                                    }
                                } else {
                                    print_r("- remote shop id not found \n");
                                }
                            } else {
                                print_r("- No payment found in Db \n");
                            }
                        }
                    } else {
                        print_r("- No active plan for the user \n");
                    }
                } else {
                    print_r("- User not found! \n");
                    $planModel->addLog('User not found: '.$userId, $logFile);
                    $notFoundUsers[] = $userId;
                    $allPaymentData = $paymentCollection->find([
                            'user_id' => $userId
                    ], $typeMapOptions)->toArray();
                    if (!empty($allPaymentData)) {
                        print_r("- Removing user data and adding in other collection \n");
                        $planModel->addLog('Removing user data and adding in payment_details_deactivated_users_backup collection', $logFile);
                        $bkpResponse = $bkpPaymentCollection->insertMany($allPaymentData);
                        if ($bkpResponse->getInsertedCount() == count($allPaymentData)) {
                            print_r("- Insertion in other collection successfull \n");
                            print_r("- Removing user data \n");
                            $paymentCollection->deleteMany([
                                'user_id' => $userId
                            ]);
                        }
                    }
                }
            }
        }
    }


    public function checkforInvalidActiveUsers(): void
    {
        $query = [
            'type' => 'active_plan',
            'status' => 'active'
        ];
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $paymentCollection = $baseMongo->getCollectionForTable(PlanModel::PAYMENT_DETAILS_COLLECTION_NAME);
        $activePlans = $paymentCollection->distinct("user_id", $query, PlanModel::TYPEMAP_OPTIONS)->toArray();
        $invalidIds = [];
        $additionalDocIds = [];
        if(!empty($activePlans)) {
            echo 'Total count: '.count($activePlans).PHP_EOL;
            foreach($activePlans as $activePlanUser) {
                $paymentQuery = [
                    'type' => 'payment',
                    'plan_status' => 'active',
                    'user_id' => $activePlanUser
                ];
                $paymentDocs = $paymentCollection->find($paymentQuery, PlanModel::TYPEMAP_OPTIONS)->toArray();
                if(empty($paymentDocs)) {
                    echo 'No payment doc found!';
                    $invalidIds[] = $activePlanUser;
                } elseif(count($paymentDocs) > 1) {
                    echo 'More than one active payment doc found!';
                    $additionalDocIds[] = $activePlanUser;
                }
            }
        }
    }

    public function checkForFreeUsersWithActiveRecurring($input): void
    {
        $filter = [
            'type' => PlanModel::PAYMENT_TYPE_ACTIVE_PLAN,
            'status' => PlanModel::USER_PLAN_STATUS_ACTIVE,
            '$or' => [
                ["plan_details.title" => "Free"],
                ["plan_details.custom_price" => 0],
                ["plan_details.custom_price" => '0'],
            ]
        ];
        $skip = (int)$input->getOption("skip") ?? 0;
        $limit = (int)$input->getOption("limit") ?? 0;
        $options = [
            '$sort' => '_id',
            'limit' => $limit,
            'skip' => $skip,
            'typeMap' => ['root' => 'array', 'document' => 'array'],
        ];
        $activeUserIds = [];
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $paymentCollection = $baseMongo->getCollectionForTable('payment_details');
        $freePlans = $paymentCollection->distinct("user_id", $filter, $options);
        echo 'Total users found: '.count($freePlans).PHP_EOL;
        if(!empty($freePlans)) {
            $planModel = $this->di->getObjectManager()->get(PlanModel::class);
            $commonObj = $this->di->getObjectManager()->get(Common::class);
            $shopifyPayment = $this->di->getObjectManager()->get(ShopifyPayment::class);
            foreach($freePlans as $freePlanUser) {
                    echo 'User: '.$freePlanUser.PHP_EOL;
                    $res = $commonObj->setDiForUser($freePlanUser);
                    if (!$res['success']) {
                        continue;
                    }

                    $shopId = $commonObj->getShopifyShopId($freePlanUser);
                    $shop = $commonObj->getShop($shopId, $freePlanUser);
                    if(!empty($shop) && isset($shop['remote_shop_id'])) {
                        $remoteRes = $shopifyPayment->getPaymentData(['shop_id' => $shop['remote_shop_id']], PlanModel::BILLING_TYPE_RECURRING);
                        if (!empty($remoteRes['data'])) {
                            $getResponse = $remoteRes['data'];
                            $activeCharges = array_filter($getResponse, fn(array $charge): bool => $charge['status'] == 'active');
                            if(!empty($activeCharges)) {
                                $activeUserIds[] = $freePlanUser;
                            }
                        }
                    }

            }
        }

        $planModel->addLog('Free users with active plan: '.json_encode($activeUserIds, true), 'plan/command/checkForFreeUsersWithActiveRecurring.log');
        if(!empty($activeUserIds)) {
            print_r('Users having active recurring and are on free plan: '.PHP_EOL);
            print_r(json_encode($activeUserIds));
        } else {
            print_r('No user found!');
        }
    }

    /**
     * Update additional services supported features for free plan users
     *
     * @param InputInterface $input
     * @return void
     */
    public function updateAdditionalServices($input): void
    {
        $logFile = 'plan/script/update_additional_services' . date('d-m-Y') . '.log';
        $this->di->getLog()->logContent('Starting updateAdditionalServicesForFreePlan script', 'info', $logFile);
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Executing updateAdditionalServicesForFreePlan script</>');

        $planType = $input->getOption("plan_type") ?? false;
        if(!$planType) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Plan type is required. Use \'free\' or \'paid\' or \'enterprise\' (-p) option is required</>');
            $this->di->getLog()->logContent('Plan type is required. Use \'free\' or \'paid\' or \'enterprise\'', 'error', $logFile);
            return;
        }

        $extraServices = $this->getExtraServicesByPlanType($planType);

        if (empty($extraServices)) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Invalid plan type provided. Use \'free\' or \'paid\' or \'enterprise\'</>');
            $this->di->getLog()->logContent('Invalid plan type: ' . $planType, 'error', $logFile);
            return;
        }

        $this->di->getLog()->logContent('Plan type: ' . $planType . ', Extra services: ' . json_encode($extraServices), 'info', $logFile);
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Plan type: '.$planType.'</>');

        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $paymentCollection = $baseMongo->getCollectionForTable('payment_details');
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];

        $query = [];

        if ($planType === 'free') {
            $query = [
                'status' => 'active',
                'type' => 'active_plan',
                'plan_details.custom_price' => 0
            ];
        } elseif ($planType === 'paid') {
            $query = [
                'service_type' => 'order_sync',
                'type' => 'user_service',
                'prepaid.service_credits' => ['$gt' => 50, '$lt' => 2000]
            ];
        } elseif ($planType === 'enterprise') {
            $query = [
                'service_type' => 'order_sync',
                'type' => 'user_service',
                'prepaid.service_credits' => ['$gte' => 2000]
            ];
        }

        $userIds = $paymentCollection->distinct('user_id', $query, $arrayParams);

        if (empty($userIds)) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> No user found with the plan type</>');
            $this->di->getLog()->logContent("No user found with the plan type", 'info', $logFile);
            return;
        }

        $userIds = array_map('strval', $userIds);
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Found ' . count($userIds) . ' user(s) with the plan type</>');
        $this->di->getLog()->logContent('Found ' . count($userIds) . ' ' . $planType . ' plan users', 'info', $logFile);
        $this->di->getLog()->logContent('User IDs: ' . json_encode($userIds), 'info', $logFile);

        $setData = ['updated_at' => date('Y-m-d H:i:s')];
        foreach ($extraServices as $key => $value) {
            $setData['supported_features.' . $key] = $value;
        }
        $updateResult = $paymentCollection->updateMany(
            [
                'user_id' => ['$in' => $userIds],
                'type' => 'user_service',
                'service_type' => 'additional_services'
            ],
            ['$set' => $setData]
        );

        $modifiedCount = $updateResult->getModifiedCount();
        $matchedCount = $updateResult->getMatchedCount();

        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Matched documents: ' . $matchedCount . ', Modified documents: ' . $modifiedCount . '</>');
        $this->di->getLog()->logContent('Update completed. Matched: ' . $matchedCount . ', Modified: ' . $modifiedCount, 'info', $logFile);
    }

    /**
     * Get extra services based on plan type
     *
     * @param string $planType
     * @return array
     */
    private function getExtraServicesByPlanType($planType): array
    {
        $services = [
            'free' => [
                'bulk_amazon_sync_actions' => false,
                'manual_amazon_sync_status' => false,
                'csv_import_export' => false,
                'manual_amazon_fetch_product_type' => false,
                '2048_variant_support' => false
            ],
            'paid' => [
                'bulk_amazon_sync_actions' => true,
                'manual_amazon_sync_status' => true,
                'csv_import_export' => true,
                'manual_amazon_fetch_product_type' => true,
                '2048_variant_support' => false
            ],
            'enterprise' => [
                'bulk_amazon_sync_actions' => true,
                'manual_amazon_sync_status' => true,
                'csv_import_export' => true,
                'manual_amazon_fetch_product_type' => true,
                '2048_variant_support' => true
            ]
        ];

        return $services[$planType] ?? [];
    }

    /**
     * Mark users for service sync
     * Marks users with different keys based on scenario:
     * - service_group_missing: true - if service_group is not found
     * - service_missing: true - if service_group exists but service code is not present
     * 
     * @param InputInterface $input
     * @return void
     */
    public function markUsersForServiceSync($input): void
    {
        // Static values
        $serviceGroupTitle = "Product Management";
        $serviceCode = "template_creation";
        
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $planCollection = $baseMongo->getCollectionForTable(PlanModel::PLAN_COLLECTION_NAME);
        $paymentCollection = $baseMongo->getCollectionForTable(PlanModel::PAYMENT_DETAILS_COLLECTION_NAME);
        
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $logFile = 'plan/script/markUsersForServiceSync' . date('d-m-Y') . '.log';
        
        // Ask for confirmation
        $this->output->writeln('');
        $this->output->writeln('<options=bold;bg=yellow> ⚠ </><options=bold;fg=yellow> You are about to mark users for service sync</>');
        $this->output->writeln('<options=bold> Service Group: ' . $serviceGroupTitle . '</>');
        $this->output->writeln('<options=bold> Service Code: ' . $serviceCode . '</>');
        $this->output->writeln('');
        
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            '<options=bold;fg=cyan>Are you sure you want to mark users with missing serviceGroup and serviceCode? (y/n): </>',
            false
        );
        
        if (!$helper->ask($input, $this->output, $question)) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Operation cancelled by user</>');
            $this->di->getLog()->logContent("Operation cancelled by user", 'info', $logFile);
            return;
        }
        
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Starting markUsersForServiceSync for service_group: ' . $serviceGroupTitle . ', service_code: ' . $serviceCode . '</>');
        $this->di->getLog()->logContent("Starting markUsersForServiceSync for service_group: {$serviceGroupTitle}, service_code: {$serviceCode}", 'info', $logFile);
        
        // Step 1: Query plan collection for custom_price: 0 and code: "free"
        $planQuery = [
            'custom_price' => 0,
            'code' => 'free'
        ];
        
        $planData = $planCollection->findOne($planQuery, $options);
        
        if (empty($planData)) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Plan not found with custom_price: 0 and code: free</>');
            $this->di->getLog()->logContent("Plan not found with custom_price: 0 and code: free", 'error', $logFile);
            return;
        }
        
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Plan found: ' . $planData['title'] . '</>');
        $this->di->getLog()->logContent("Plan found: " . json_encode($planData['title']), 'info', $logFile);
        
        // Step 2: Verify the service exists in the plan (search by code field)
        $targetService = null;
        if (isset($planData['services_groups']) && is_array($planData['services_groups'])) {
            foreach ($planData['services_groups'] as $serviceGroup) {
                if (isset($serviceGroup['title']) && $serviceGroup['title'] === $serviceGroupTitle) {
                    if (isset($serviceGroup['services']) && is_array($serviceGroup['services'])) {
                        foreach ($serviceGroup['services'] as $service) {
                            if (isset($service['code']) && $service['code'] === $serviceCode) {
                                $targetService = $service;
                                break 2;
                            }
                        }
                    }
                }
            }
        }
        
        if (empty($targetService)) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Service not found: service_group=\'' . $serviceGroupTitle . '\', service_code=\'' . $serviceCode . '\'</>');
            $this->di->getLog()->logContent("Service not found: service_group='{$serviceGroupTitle}', service_code='{$serviceCode}'", 'error', $logFile);
            return;
        }
        
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Target service found: ' . ($targetService['title'] ?? 'N/A') . ' (code: ' . $targetService['code'] . ')</>');
        $this->di->getLog()->logContent("Target service found: " . ($targetService['title'] ?? 'N/A') . " (code: {$targetService['code']})", 'info', $logFile);
        
        // Step 3: Find all users where:
        // - type = "active_plan"
        // - plan_details.services_groups exists
        // - And they are not already marked as synced
        
        $activePlanQuery = [
            'type' => PlanModel::PAYMENT_TYPE_ACTIVE_PLAN,
            'status' => PlanModel::USER_PLAN_STATUS_ACTIVE,
            'plan_details.services_groups' => ['$exists' => true],
            'plan_service_synced' => ['$exists' => false],
            'service_missing' => ['$exists' => false],
            'service_group_missing' => ['$exists' => false]
        ];
        
        $activePlans = $paymentCollection->find($activePlanQuery, $options)->toArray();
        
        if (empty($activePlans)) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> No active plans found</>');
            $this->di->getLog()->logContent("No active plans found", 'info', $logFile);
            return;
        }
        
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Total active plans found: ' . count($activePlans) . '</>');
        $this->di->getLog()->logContent("Total active plans found: " . count($activePlans), 'info', $logFile);
        
        $serviceMissingCount = 0;
        $serviceGroupMissingCount = 0;
        $serviceSyncedCount = 0;
        
        // Step 4: Process each active plan and mark users based on scenario
        foreach ($activePlans as $activePlan) {
            $userId = $activePlan['user_id'] ?? null;
            if (empty($userId)) {
                continue;
            }
            
            $servicesGroups = $activePlan['plan_details']['services_groups'] ?? [];
            
            // Check if service_group exists
            $serviceGroupFound = false;
            $serviceExists = false;
            
            foreach ($servicesGroups as $serviceGroup) {
                if (isset($serviceGroup['title']) && $serviceGroup['title'] === $serviceGroupTitle) {
                    $serviceGroupFound = true;
                    
                    // Check if service code already exists
                    if (isset($serviceGroup['services']) && is_array($serviceGroup['services'])) {
                        foreach ($serviceGroup['services'] as $service) {
                            if (isset($service['code']) && $service['code'] === $serviceCode) {
                                $serviceExists = true;
                                break;
                            }
                        }
                    }
                    break;
                }
            }
            
            // Mark users based on scenario
            $updateFilter = [
                '_id' => $activePlan['_id'],
                'user_id' => $userId
            ];
            
            $updateData = [
                '$set' => [
                    'updated_at' => date('Y-m-d H:i:s')
                ]
            ];
            
            if (!$serviceGroupFound) {
                // Service group not found - mark with service_group_missing
                $updateData['$set']['service_group_missing'] = true;
                $markKey = 'service_group_missing';
                $markCount = &$serviceGroupMissingCount;
                $markMessage = "Service group '{$serviceGroupTitle}' not found";
            } elseif (!$serviceExists) {
                // Service group found but service code not present - mark with service_missing
                $updateData['$set']['service_missing'] = true;
                $markKey = 'service_missing';
                $markCount = &$serviceMissingCount;
                $markMessage = "Service group found but service code '{$serviceCode}' not present";
            } else {
                // Both exist - set plan_service_synced
                $updateData['$set']['plan_service_synced'] = true;
                $markKey = 'plan_service_synced';
                $markCount = &$serviceSyncedCount;
                $markMessage = "Service already exists for service_group '{$serviceGroupTitle}' and service_code '{$serviceCode}'";
            }
            
            try {
                $updateResult = $paymentCollection->updateOne($updateFilter, $updateData);
                
                if ($updateResult->isAcknowledged() && $updateResult->getModifiedCount() > 0) {
                    $markCount++;
                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Marked user (' . $markKey . '): ' . $userId . ' - ' . $markMessage . '</>');
                    $this->di->getLog()->logContent("Marked user ({$markKey}): {$userId} - {$markMessage}", 'info', $logFile);
                } else {
                    $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Failed to mark user: ' . $userId . '</>');
                    $this->di->getLog()->logContent("Failed to mark user: {$userId}", 'error', $logFile);
                }
            } catch (Exception $e) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error marking user ' . $userId . ': ' . $e->getMessage() . '</>');
                $this->di->getLog()->logContent("Error marking user {$userId}: " . $e->getMessage(), 'error', $logFile);
            }
        }
        
        $this->output->writeln('');
        $this->output->writeln('<options=bold;bg=blue> === Marking Complete ===</>');
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Users marked with service_missing: ' . $serviceMissingCount . '</>');
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Users marked with service_group_missing: ' . $serviceGroupMissingCount . '</>');
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Users marked with plan_service_synced (service already exists): ' . $serviceSyncedCount . '</>');
        $this->di->getLog()->logContent("=== Marking Complete ===", 'info', $logFile);
        $this->di->getLog()->logContent("Users marked with service_missing: {$serviceMissingCount}", 'info', $logFile);
        $this->di->getLog()->logContent("Users marked with service_group_missing: {$serviceGroupMissingCount}", 'info', $logFile);
        $this->di->getLog()->logContent("Users marked with plan_service_synced (service already exists): {$serviceSyncedCount}", 'info', $logFile);
    }

    /**
     * Sync service for marked users
     * Processes only users marked with service_missing: true (service_group exists but service code is missing)
     * Adds the missing service to their plan_details and marks them as plan_service_synced: true
     * 
     * @param InputInterface $input
     * @return void
     */
    public function syncServiceForMarkedUsers($input): void
    {
        // Static values
        $serviceGroupTitle = "Product Management";
        $serviceCode = "template_creation";
        
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $planCollection = $baseMongo->getCollectionForTable(PlanModel::PLAN_COLLECTION_NAME);
        $paymentCollection = $baseMongo->getCollectionForTable(PlanModel::PAYMENT_DETAILS_COLLECTION_NAME);
        
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $logFile = 'plan/script/syncServiceForMarkedUsers' . date('d-m-Y') . '.log';
        
        // Ask for confirmation
        $this->output->writeln('');
        $this->output->writeln('<options=bold;bg=yellow> ⚠ </><options=bold;fg=yellow> You are about to sync services for marked users</>');
        $this->output->writeln('<options=bold> Service Group: ' . $serviceGroupTitle . '</>');
        $this->output->writeln('<options=bold> Service Code: ' . $serviceCode . '</>');
        $this->output->writeln('');
        
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            '<options=bold;fg=cyan>Are you sure you want to sync services for marked users? (y/n): </>',
            false
        );
        
        if (!$helper->ask($input, $this->output, $question)) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Operation cancelled by user</>');
            $this->di->getLog()->logContent("Operation cancelled by user", 'info', $logFile);
            return;
        }
        
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Starting syncServiceForMarkedUsers for service_group: ' . $serviceGroupTitle . ', service_code: ' . $serviceCode . '</>');
        $this->di->getLog()->logContent("Starting syncServiceForMarkedUsers for service_group: {$serviceGroupTitle}, service_code: {$serviceCode}", 'info', $logFile);
        
        // Step 1: Query plan collection for custom_price: 0 and code: "free"
        $planQuery = [
            'custom_price' => 0,
            'code' => 'free'
        ];
        
        $planData = $planCollection->findOne($planQuery, $options);
        
        if (empty($planData)) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Plan not found with custom_price: 0 and code: free</>');
            $this->di->getLog()->logContent("Plan not found with custom_price: 0 and code: free", 'error', $logFile);
            return;
        }
        
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Plan found: ' . $planData['title'] . '</>');
        $this->di->getLog()->logContent("Plan found: {$planData['title']}", 'info', $logFile);
        
        // Step 2: Extract the service object from services_groups
        $targetService = null;
        if (isset($planData['services_groups']) && is_array($planData['services_groups'])) {
            foreach ($planData['services_groups'] as $serviceGroup) {
                if (isset($serviceGroup['title']) && $serviceGroup['title'] === $serviceGroupTitle) {
                    if (isset($serviceGroup['services']) && is_array($serviceGroup['services'])) {
                        foreach ($serviceGroup['services'] as $service) {
                            if (isset($service['code']) && $service['code'] === $serviceCode) {
                                $targetService = $service;
                                break 2;
                            }
                        }
                    }
                }
            }
        }
        
        if (empty($targetService)) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Service not found: service_group=\'' . $serviceGroupTitle . '\', code=\'' . $serviceCode . '\'</>');
            $this->di->getLog()->logContent("Service not found: service_group='{$serviceGroupTitle}', code='{$serviceCode}'", 'error', $logFile);
            return;
        }
        
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Target service found: ' . $targetService['title'] . ' (code: ' . $targetService['code'] . ')</>');
        $this->di->getLog()->logContent("Target service found: {$targetService['title']} (code: {$targetService['code']})", 'info', $logFile);
        
        // Step 3: Find all marked users (service_missing: true) - only users where service_group exists but service code is missing
        $markedUsersQuery = [
            'type' => PlanModel::PAYMENT_TYPE_ACTIVE_PLAN,
            'status' => PlanModel::USER_PLAN_STATUS_ACTIVE,
            'service_missing' => true,
            'plan_details.services_groups' => ['$exists' => true]
        ];
        
        $markedPlans = $paymentCollection->find($markedUsersQuery, $options)->toArray();
        
        if (empty($markedPlans)) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> No marked users found with service_missing: true</>');
            $this->di->getLog()->logContent("No marked users found with service_missing: true", 'info', $logFile);
            return;
        }
        
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Total marked users found: ' . count($markedPlans) . '</>');
        $this->di->getLog()->logContent("Total marked users found: " . count($markedPlans), 'info', $logFile);
        
        $updatedCount = 0;
        $skippedCount = 0;
        
        // Step 4: Process each marked user and sync the service
        foreach ($markedPlans as $activePlan) {
            $userId = $activePlan['user_id'] ?? null;
            if (empty($userId)) {
                continue;
            }
            
            $servicesGroups = $activePlan['plan_details']['services_groups'] ?? [];
            
            // Find the service group and add the service
            $serviceGroupFound = false;
            $serviceGroupIndex = null;
            
            foreach ($servicesGroups as $index => $serviceGroup) {
                if (isset($serviceGroup['title']) && $serviceGroup['title'] === $serviceGroupTitle) {
                    $serviceGroupFound = true;
                    $serviceGroupIndex = $index;
                    
                    // Double-check if service code already exists (shouldn't, but just in case)
                    $serviceExists = false;
                    if (isset($serviceGroup['services']) && is_array($serviceGroup['services'])) {
                        foreach ($serviceGroup['services'] as $service) {
                            if (isset($service['code']) && $service['code'] === $serviceCode) {
                                $serviceExists = true;
                                break;
                            }
                        }
                    }
                    
                    // If service already exists, skip and remove the mark
                    if ($serviceExists) {
                        $skippedCount++;
                        $updateFilter = [
                            '_id' => $activePlan['_id'],
                            'user_id' => $userId
                        ];
                        
                        $updateData = [
                            '$unset' => ['service_missing' => ""],
                            '$set' => [
                                'plan_service_synced' => true,
                                'updated_at' => date('Y-m-d H:i:s')
                            ]
                        ];
                        
                        $paymentCollection->updateOne($updateFilter, $updateData);
                        $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Service already exists for user: ' . $userId . ', removing mark</>');
                        $this->di->getLog()->logContent("Service already exists for user: {$userId}, removing mark", 'info', $logFile);
                        continue 2;
                    }
                    
                    break;
                }
            }
            
            if ($serviceGroupFound && $serviceGroupIndex !== null) {
                // Add the service to the appropriate service group
                if (!isset($servicesGroups[$serviceGroupIndex]['services'])) {
                    $servicesGroups[$serviceGroupIndex]['services'] = [];
                }
                
                // Add the service (make a copy to avoid reference issues)
                $serviceToAdd = $targetService;
                $servicesGroups[$serviceGroupIndex]['services'][] = $serviceToAdd;
                
                // Update the document
                $updateFilter = [
                    '_id' => $activePlan['_id'],
                    'user_id' => $userId
                ];
                
                $updateData = [
                    '$unset' => ['service_missing' => ""],
                    '$set' => [
                        'plan_details.services_groups' => $servicesGroups,
                        'plan_service_synced' => true,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]
                ];
                
                try {
                    $updateResult = $paymentCollection->updateOne($updateFilter, $updateData);
                    
                    if ($updateResult->isAcknowledged() && $updateResult->getModifiedCount() > 0) {
                        $updatedCount++;
                        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Synced service for user: ' . $userId . '</>');
                        $this->di->getLog()->logContent("Successfully synced service for user: {$userId}", 'info', $logFile);
                    } else {
                        $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Failed to sync service for user: ' . $userId . '</>');
                        $this->di->getLog()->logContent("Failed to sync service for user: {$userId}", 'error', $logFile);
                    }
                } catch (Exception $e) {
                    $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error syncing service for user ' . $userId . ': ' . $e->getMessage() . '</>');
                    $this->di->getLog()->logContent("Error syncing service for user {$userId}: " . $e->getMessage(), 'error', $logFile);
                }
            } else {
                $skippedCount++;
                $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Service group \'' . $serviceGroupTitle . '\' not found for user: ' . $userId . '</>');
                $this->di->getLog()->logContent("Service group '{$serviceGroupTitle}' not found for user: {$userId}", 'info', $logFile);
                
                // Remove the mark since service group doesn't exist (shouldn't happen if marked correctly)
                $updateFilter = [
                    '_id' => $activePlan['_id'],
                    'user_id' => $userId
                ];
                
                $updateData = [
                    '$unset' => ['service_missing' => ""],
                    '$set' => [
                        'updated_at' => date('Y-m-d H:i:s')
                    ]
                ];
                
                $paymentCollection->updateOne($updateFilter, $updateData);
            }
        }
        
        $this->output->writeln('');
        $this->output->writeln('<options=bold;bg=blue> === Sync Complete ===</>');
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Total users synced: ' . $updatedCount . '</>');
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Total users skipped: ' . $skippedCount . '</>');
        $this->di->getLog()->logContent("=== Sync Complete ===", 'info', $logFile);
        $this->di->getLog()->logContent("Total users synced: {$updatedCount}", 'info', $logFile);
        $this->di->getLog()->logContent("Total users skipped: {$skippedCount}", 'info', $logFile);
    }
}
