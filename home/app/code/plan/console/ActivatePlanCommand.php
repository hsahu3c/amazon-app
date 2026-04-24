<?php

use App\Plan\Models\BaseMongo;
use App\Plan\Components\Common;
use App\Connector\Models\QueuedTasks;
use App\Plan\Components\Method\ShopifyPayment;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use App\Plan\Models\Plan as PlanModel;

class ActivatePlanCommand extends Command
{

    protected static $defaultName = "plan:activate";

    protected static $defaultDescription = "Activate Pending Plans";
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
        $this->setHelp('To activate pending plans');
        $this->addOption(
            "function",
            "f",
            InputOption::VALUE_REQUIRED,
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
    }

    /**
     * The main logic to execute when the command is run
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        print_r("Executing command to Activate Plan \n");
        $f = $input->getOption("function") ?? "";
        print_r("Provided function: {$f} \n");
        if(empty($f)) {
            echo 'Provide a valid function!';
            return false;
        }

        $this->$f($input);
        return true;
    }

    public function activatePlanInPendingState(): void
    {
        $mongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $planModel = $this->di->getObjectManager()->get(PlanModel::class);
        $commonObj = $this->di->getObjectManager()->get(Common::class);
        $shopifyPayment = $this->di->getObjectManager()->get(ShopifyPayment::class);
        $queuedTask = new QueuedTasks;
        $paymentCollection = $mongo->getCollection(PlanModel::PAYMENT_DETAILS_COLLECTION_NAME);
        $date = date("Y-m-d H:i:s", strtotime("-12hours"));
        $query = [
            'type' => PlanModel::PAYMENT_TYPE_QUOTE,
            'status' => ['$in' => [PlanModel::QUOTE_STATUS_ACTIVE, PlanModel::QUOTE_STATUS_WAITING_FOR_PAYMENT]],
            'generated_charge_id' => ['$exists' => true],
            'url_generated_at' => ['$gt' => $date]
        ];
        $pendingQuotes = $paymentCollection->find($query, PlanModel::TYPEMAP_OPTIONS)->toArray();
        // echo 'Total found: '.count($pendingQuotes).PHP_EOL;
        $nullIds =[];
        if(!empty($pendingQuotes)) {
            foreach($pendingQuotes as $quote) {
                $diRes = $commonObj->setDiRequesterAndTag($quote['user_id'] ?? "");
                if (!$diRes['success']) {
                    continue;
                }

                if(!empty($quote['source_id'])) {
                    $shop = $commonObj->getShop($quote['source_id'], $quote['user_id']);
                    if(empty($shop)) {
                        continue;
                    }

                    $billingType = "";
                    if ($quote['plan_type'] == 'plan') {
                        if(!empty($quote['plan_details']['payment_type'])) {
                            $billingType = $quote['plan_details']['payment_type'];
                        } else {
                            $billingType = PlanModel::BILLING_TYPE_RECURRING;
                        }
                    } else {
                        $billingType = PlanModel::BILLING_TYPE_ONETIME;
                    }

                    $data['shop_id'] = $shop['remote_shop_id'] ?? "";
                    $data['id'] = $quote['generated_charge_id'];
                    $remoteData = $shopifyPayment->getPaymentData($data, $billingType);
                    if(!empty($remoteData) && isset($remoteData['success']) && !empty($remoteData['data'])) {
                        $remoteData = $remoteData['data'];
                        if (isset($remoteData['id']) && isset($remoteData['status'])) {
                            if ($remoteData['status'] == ShopifyPayment::STATUS_ACTIVE) {
                                $activateData = [
                                    'quote_id' => $quote['_id'],
                                    'charge_id' => $quote['generated_charge_id']
                                ];
                                $planModel->activatePaymentBasedOnType($activateData);
                            } elseif(in_array($remoteData['status'], [ShopifyPayment::STATUS_DECLINED, ShopifyPayment::STATUS_EXPIRED, ShopifyPayment::STATUS_CANCELLED, ShopifyPayment::STATUS_FROZEN])) {
                                $paymentCollection->updateOne([
                                    '_id' => $quote['_id'],
                                    'user_id' => $quote['user_id']
                                ],
                                [
                                    '$set' => [
                                        'status' => PlanModel::QUOTE_STATUS_REJECTED,
                                        'updated_at' => date("Y-m-d H:i:s")
                                    ]
                                ]
                                );
                                $queuedTask = new QueuedTasks;
                                foreach($quote['queue_task_ids'] as $taskId) {
                                    if (is_null($taskId)) {
                                        $nullIds[] = $quote['_id'];
                                    } else {
                                        $queuedTask->updateFeedProgress((string)$taskId, 100, 'Payment is terminated!');
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // echo 'Process end for active quotes within a day'.PHP_EOL;

        $queryforActiveQuotes = [
            'type' => PlanModel::PAYMENT_TYPE_QUOTE,
            'status' => ['$in' => [PlanModel::QUOTE_STATUS_ACTIVE, PlanModel::QUOTE_STATUS_WAITING_FOR_PAYMENT]],
            'generated_charge_id' => ['$exists' => true],
            'url_generated_at' => ['$lt' => $date]
        ];
        $pendingActiveQuotes = $paymentCollection->find($queryforActiveQuotes, PlanModel::TYPEMAP_OPTIONS)->toArray();
        // echo 'Total found active quote more than a day: '.count($pendingActiveQuotes).PHP_EOL;
        if(!empty($pendingActiveQuotes)) {
            foreach($pendingActiveQuotes as $pendingQuote) {
                $diRes = $commonObj->setDiRequesterAndTag($pendingQuote['user_id'] ?? "");
                if (!$diRes['success']) {
                    continue;
                }

                if(!empty($pendingQuote['source_id'])) {
                    $shop = $commonObj->getShop($pendingQuote['source_id'], $pendingQuote['user_id']);
                    if(empty($shop)) {
                        continue;
                    }

                    $billingType = "";
                    if ($pendingQuote['plan_type'] == 'plan') {
                        if(!empty($pendingQuote['plan_details']['payment_type'])) {
                            $billingType = $pendingQuote['plan_details']['payment_type'];
                        } else {
                            $billingType = PlanModel::BILLING_TYPE_RECURRING;
                        }
                    } else {
                        $billingType = PlanModel::BILLING_TYPE_ONETIME;
                    }

                    $remoteDataQuery['shop_id'] = $shop['remote_shop_id'] ?? "";
                    $remoteDataQuery['id'] = $pendingQuote['generated_charge_id'];
                    $remoteData = $shopifyPayment->getPaymentData($remoteDataQuery, $billingType);
                    if(!empty($remoteData) && isset($remoteData['success']) && !empty($remoteData['data']) && isset($remoteData['data']['id']) && isset($remoteData['data']['status']) && ($remoteData['data']['status'] == ShopifyPayment::STATUS_ACTIVE)) {
                        $activateData = [
                            'quote_id' => $pendingQuote['_id'],
                            'charge_id' => $pendingQuote['generated_charge_id']
                        ];
                        $planModel->activatePaymentBasedOnType($activateData);
                    }  else {
                        $paymentCollection->updateOne([
                            '_id' => $pendingQuote['_id'],
                            'user_id' => $pendingQuote['user_id']
                        ],
                        [
                            '$set' => [
                                'status' => PlanModel::QUOTE_STATUS_REJECTED,
                                'updated_at' => date("Y-m-d H:i:s")
                            ]
                        ]
                        );
                        foreach($pendingQuote['queue_task_ids'] as $taskId) {
                            if (is_null($taskId)) {
                                $nullIds[] = $pendingQuote['_id'];
                            } else {
                                $queuedTask->updateFeedProgress((string)$taskId, 100, 'Payment is terminated!');
                            }
                        }
                    }
                }
            }
        }

        if (!empty($nullIds)) {
            $queuedTaskCollection = $mongo->getCollection('queued_tasks');
            $queryForIds = [
                'additional_data.quote_id' => ['$in' => $nullIds]
            ];
            $pendingQueues = $queuedTaskCollection->find($queryForIds, PlanModel::TYPEMAP_OPTIONS)->toArray();
            if(!empty($pendingQueues)) {
                foreach($pendingQueues as $queue) {
                    $queuedTask->updateFeedProgress((string)$queue['_id'], 100, 'Payment is terminated!');
                }
            }
        } else {
            $queuedTaskCollection = $mongo->getCollection('queued_tasks');
            $processCodes = ['payment_process_plan', 'payment_process_custom', 'payment_process_settlement'];
            $ltdate = date("Y-m-d H:i:s", strtotime("-1day"));
            $queryForTags = [
                'process_code' => ['$in' => $processCodes],
                'created_at' => ['$lt' => $ltdate]
            ];
            $pendingQueues = $queuedTaskCollection->find($queryForTags, PlanModel::TYPEMAP_OPTIONS)->toArray();
            if(!empty($pendingQueues)) {
                foreach($pendingQueues as $queue) {
                    $queuedTask->updateFeedProgress((string)$queue['_id'], 100, 'Payment is terminated!');
                }
            }
        }

        // echo 'Process end for active quotes more than a day'.PHP_EOL;
        // echo 'Process end!';
    }

    public function downgradeToFreeForSchedulers($input = null): int
    {
        return 1;//to stop schedulerd for free plan
        // print_r("Executing command for downgrading free plans based on the date\n");
        // $date = date('Y-m-d');
        // $filter = [
        //         'type' => PlanModel::PAYMENT_TYPE_USER_SERVICE,
        //         'source_marketplace' => PlanModel::SOURCE_MARKETPLACE,
        //         'target_marketplace' => PlanModel::TARGET_MARKETPLACE,
        //         'marketplace' => PlanModel::TARGET_MARKETPLACE,
        //         'service_type' => PlanModel::SERVICE_TYPE_ORDER_SYNC,
        //         'downgrade_to_free' => true,
        //         'downgrade_to_free_on' => ['$lte' => $date]
        // ];
        // $baseMongo = $this->di->getObjectManager()->get('\App\Plan\Models\BaseMongo');
        // $paymentCollection = $baseMongo->getCollectionForTable('payment_details');
        // $userServicesToDowgradeToFree = $paymentCollection->find($filter, PlanModel::TYPEMAP_OPTIONS)->toArray();
        // if(!empty($userServicesToDowgradeToFree)) {
        //     $successUserIds = [];
        //     $failedUserIds = [];
        //     $planModel = $this->di->getObjectManager()->get(PlanModel::class);
        //     foreach($userServicesToDowgradeToFree as $userService) {
        //         $response = $planModel->downgradeToFree(['user_id' => $userService['user_id']]);
        //         if($response['success']) {
        //             $successUserIds[] = $userService['user_id'];
        //          } elseif(isset($userService['prepaid']['service_credits']) && ($userService['prepaid']['service_credits'] == PlanModel::FREE_PLAN_CREDITS)) {
        //             $successUserIds[] = $userService['user_id'];
        //          } else {
        //              $failedUserIds[] = $userService['user_id'];
        //          }
        //     }
        //     if(!empty($successUserIds)) {
        //         $filter['user_id'] = ['$in' => $successUserIds];
        //         $paymentCollection->updateMany($filter, [
        //             '$unset' => [
        //                 'downgrade_to_free' => true,
        //                 'downgrade_to_free_on' => true
        //             ]
        //         ]);
        //     }
        //     print_r("Success Users: ".json_encode($successUserIds)." | Failed Users: ". json_encode($failedUserIds));
        // } else {
        //     print_r("No one to downgrade!");
        // }
        // print_r("Executed successfully!");
    }
}
