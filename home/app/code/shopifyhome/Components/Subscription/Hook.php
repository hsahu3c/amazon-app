<?php

namespace App\Shopifyhome\Components\Subscription;

use App\Core\Components\Base;
use App\Plan\Models\Plan;
use App\Plan\Models\BaseMongo;

class Hook extends Base
{
    public function appSubscriptionUpdate($data = [])
    {
        $logFile = 'shopify/webhook/' . date('Y-m-d') . '/subscription.log';
        $this->di->getLog()->logContent('Subscription data received: ' . json_encode($data, true), 'info', $logFile);
        if (isset($data['data']['app_subscription'])) {
            $planModel = $this->di->getObjectManager()->create(Plan::class);
            $subscriptionData = $data['data']['app_subscription'];
            $status = $subscriptionData['status'] ?? "";
            $status = strtolower((string) $status);

            $idData = $subscriptionData['admin_graphql_api_id'] ?? [];
            $idDataArr = explode("AppSubscription/", (string) $idData);
            $chargeId = $idDataArr[count($idDataArr) - 1] ?? "";
            $this->di->getLog()->logContent('Charge Id: ' . $chargeId, 'info', $logFile);
            $this->di->getLog()->logContent('status: ' . $status, 'info', $logFile);
            if (!empty($status) && !empty($chargeId)) {
                $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
                $planCollection = $baseMongo->getCollectionForTable(Plan::PAYMENT_DETAILS_COLLECTION_NAME);
                $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
                switch ($status) {
                    case 'cancelled':
                        $this->di->getLog()->logContent('Recurring cancelled', 'info', $logFile);
                        $planUpgrade = $planCollection->findOne(
                            [
                                'user_id' => $this->di->getUser()->id,
                                'type' => 'process',
                                'upgrade' => true
                            ],
                            $options
                        );
                        if(!empty($planUpgrade)) {
                            $this->di->getLog()->logContent('Plan upgrade data found: '.json_encode($planUpgrade), 'info', $logFile);
                            $timestamp = $planUpgrade['created_at'];
                            $minutesToAdd = 12;
                            $newTimestamp = date('Y-m-d H:i:s', strtotime($timestamp . ' + ' . $minutesToAdd . ' minutes'));
                            if($newTimestamp > date('Y-m-d H:i:s')) {
                                return $this->requeSqsData($data);
                            }
                            $this->di->getLog()->logContent('Proceeding for delete upgrade entry from db', 'info', $logFile);
                            $planCollection->deleteOne(
                                ['_id' => $planUpgrade['_id']]
                            );
                        }

                        $userService = $planModel->getCurrentUserServices($this->di->getUser()->id, Plan::SERVICE_TYPE_ORDER_SYNC);
                        if (!empty($userService)) {
                            $this->di->getAppCode()->setAppTag($userService['app_tag'] ?? Plan::APP_TAG);
                            if (empty($userService['source_id'])) {
                                $userService['source_id'] = $planModel->commonObj->getShopifyShopId();
                            }

                            if (isset($userService['source_id'])) {
                                $this->di->getRequester()->setSource(
                                    [
                                        'source_id' => $userService['source_id'],
                                        'source_name' => ''
                                    ]
                                );
                                $planModel->deactivatePlan('paid', $this->di->getUser()->id, $chargeId);
                            }
                        }

                        break;

                    case 'active':
                        $this->di->getLog()->logContent('data: '.json_encode(['user_id' => $this->di->getUser()->id, 'type' => 'quote', 'generated_charge_id' => (string)$chargeId]), 'info', $logFile);
                       $quoteToProcess = $planCollection->findOne(['user_id' => $this->di->getUser()->id, 'type' => 'quote', 'generated_charge_id' => (string)$chargeId], $options);
                       $this->di->getLog()->logContent('quoteToProcess: '.json_encode($quoteToProcess, true), 'info', $logFile);
                        if (!empty($quoteToProcess['status']) && (($quoteToProcess['status'] == Plan::QUOTE_STATUS_ACTIVE) || ($quoteToProcess['status'] == Plan::QUOTE_STATUS_WAITING_FOR_PAYMENT))) {
                            if (!empty($quoteToProcess['url_generated_at'])) {
                                $time = date('Y-m-d H:i:s', strtotime($quoteToProcess['url_generated_at'].'+15min'));
                                if ($time >= date('Y-m-d H:i:s')) {
                                    $this->di->getLog()->logContent('Requeing data for activation', 'info', $logFile);
                                    return $this->requeSqsData($data);
                                }
                                if ($time < date('Y-m-d H:i:s')) {
                                    $this->di->getLog()->logContent('Processing activation', 'info', $logFile);
                                    //we cannot use activate apyment here as there are possiblities that this paymenmt can be for any settlement or plan or custom so we need to handle this globally
                                    $activatePaymentData = [
                                        'quote_id' => $quoteToProcess['_id'],
                                        'charge_id' => $chargeId,
                                        'user_id' => $quoteToProcess['user_id']
                                    ];
                                    $planModel->activatePaymentBasedOnType($activatePaymentData);
                                }
                            }
                        }

                       //not going to work on the below code for now may be used in future related to capped credit

                        // $this->di->getLog()->logContent('Processing handleSubsciptionUpdate!', 'info', $logFile);
                        // $planHelper = $this->di->getObjectManager()->get('\App\Plan\Components\Helper');
                        // if(method_exists($planHelper, "handleSubsciptionUpdate")) {
                        //     $planHelper->handleSubsciptionUpdate($subscriptionData);
                        // } else {
                        //     $this->di->getLog()->logContent('Method not exist!', 'info', $logFile);
                        // }
                        break;
                    default:
                        break;
                }
            }
        } elseif(isset($data['data']['throttle_handle'])) {
            $this->di->getLog()->logContent('Handling throttle', 'info', $logFile);
            $this->di->getObjectManager()->create(Plan::class)->activatePaymentBasedOnType($data['data']['throttle_handle']);
        }
    }

    public function requeSqsData($data)
    {
        $sqsData = $data;
        $sqsData['delay'] = 15 * 60;
        $this->di->getMessageManager()->pushMessage($sqsData);
        return true;
    }
}
