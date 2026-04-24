<?php

namespace App\Plan\Controllers;

use App\Core\Controllers\BaseController;
use App\Plan\Components\Common;
use App\Plan\Models\Plan;
use App\Plan\Components\Helper;

/**
 * Class AdminController
 * @package App\Plan\Controllers
 */
class AdminController extends BaseController
{
    /**
     * to let client downgrade the plan to free
     */
    public function downgradeToFreeAction()
    {
        $rawBody = $this->getRequestData();
        if (!isset($rawBody['user_id'])) {
            return $this->prepareResponse([
                'success' => false,
                'message' => 'user_id required!'
            ]);
        }
        return $this->prepareResponse([
            'success' => false,
            'message' => 'Not supported!'
        ]);
        if (isset($rawBody['password']) && $rawBody['password'] == 'downgrade') {
            if (isset($rawBody['schedule']) && $rawBody['schedule']) {
                if (isset($rawBody['schedule_at']) && !empty($rawBody['schedule_at'])) {
                    $response = $this->di->getObjectManager()->get(Plan::class)->scheduleForPlanDowngradeToFree($rawBody);
                } else {
                    $response = [
                        'success' => false,
                        'message' => 'Date of scheduling required!'
                    ];
                }
            } else {
                $response = $this->di->getObjectManager()->get(Plan::class)->downgradeToFree($rawBody);
            }
        } else {
            $response = [
                'success' => false,
                'message' => 'Incorrect Password/Password required'
            ];
        }
        return  $this->prepareResponse($response);
    }

    /**
     * can be used by admins as per requirement
     *
     * @return mixed
     */
    public function cancelRecurryingChargeAction()
    {
        $rawBody = $this->getRequestData();
        $response = $this->di->getObjectManager()->get(Plan::class)->cancelRecurryingCharge($rawBody);
        return  $this->prepareResponse($response);
    }

    /**
     * to waive off settlement
     */
    public function waiveOffPendingSettlementAction()
    {
        $rawBody = $this->getRequestData();
        if (!isset($rawBody['user_id'])) {
            $response = [
                'success' => false,
                'message' => 'user_id required!'
            ];
        } else {
            $diRes = $this->di->getObjectManager()->get(Common::class)->setDiForUser($rawBody['user_id']);
            if (!$diRes['success']) {
                $response = $diRes;
            } else {
                $planModel = $this->di->getObjectManager()->get(Plan::class);
                $pendingSettlement = $planModel->getPendingSettlementInvoice($rawBody['user_id']);
                if (!empty($pendingSettlement)) {
                    if (isset($pendingSettlement['is_capped'])) {
                        $response = [
                            'success' => false,
                            'message' => 'Settlement will be deducted automatically'
                        ];
                        return  $this->prepareResponse($response);
                    }
                    $planModel->paymentCollection->updateOne(
                        [
                            'user_id' => $this->di->getUser()->id,
                            'type' => Plan::SETTLEMENT_INVOICE,
                            'status' => Plan::PAYMENT_STATUS_PENDING,
                            'is_capped' => ['$exists' => false]
                        ],
                        [
                            '$set' => ['status' => 'waived_off', 'updated_at' => date('Y-m-d H:i:s')]
                        ]
                    );
                    $resetLimitFilterData = [
                        'user_id' => $this->di->getUser()->id,
                        'type' => Plan::PAYMENT_TYPE_USER_SERVICE,
                        'service_type' => Plan::SERVICE_TYPE_ORDER_SYNC,
                        '$expr' => [
                            '$gt' => [
                                '$postpaid.total_used_credits',
                                0
                            ]
                        ]
                    ];
                    $planModel->paymentCollection->updateOne($resetLimitFilterData, [
                        '$set' => [
                            'postpaid.available_credits' => 0,
                            'postpaid.total_used_credits' => 0
                        ]
                    ]);
                    // reset in dynamo as well
                    $userDetails = $this->di->getObjectManager()->get(Common::class)->getUserDetail($rawBody['user_id']);
                    if (!empty($userDetails) && isset($userDetails['shops'])) {
                        foreach ($userDetails['shops'] as $shop) {
                            if (isset($shop['marketplace']) && ($shop['marketplace'] == 'amazon')) {
                                $this->di->getObjectManager()->get(Plan::class)->updateEntryInDynamo($shop['remote_shop_id'], 0);
                            }
                        }
                    }
                    $response = [
                        'success' => true,
                        'message' => 'Settlement waived off for the user!'
                    ];
                } else {
                    $response = [
                        'success' => false,
                        'message' => 'No pending settlement!'
                    ];
                }
            }
        }

        return  $this->prepareResponse($response);
    }

    public function refundApplicationCreditsAction()
    {
        $rawBody = $this->getRequestData();
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        return $this->prepareResponse($planModel->refundApplicationCreditsQL($rawBody));
    }

    public function convertFreeToTrialAction()
    {
        $response = [
            'success' => false,
            'message' => 'Not supported for now!'
        ];
        return $this->prepareResponse($response);
        $rawBody = $this->getRequestData();
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        return $this->prepareResponse($planModel->convertFreeToTrial($rawBody['user_id'] ?? false));
    }

    public function addAdditionalUserServiceAction()
    {
        $rawBody = $this->getRequestData();
        $response = $this->di->getObjectManager()->get(Plan::class)->addAdditionalUserService($rawBody);
        return $this->prepareResponse($response);
    }

    /**
     * Unified order limit management handler
     * 
     * @return mixed
     */
    public function manageOrderLimitAction()
    {
        $rawBody = $this->getRequestData();
        $username = $this->di->getUser()->username;
        $response = $this->di->getObjectManager()->get(Plan::class)->manageOrderLimitUnified($rawBody, $username);
        return $this->prepareResponse($response);
    }

    public function getOrderLimitActivitiesAction()
    {
        try {
            // Handle both GET and POST parameters
            $rawBody = $this->getRequestData();

            // For GET requests, merge query parameters
            if ($this->request->getMethod() === 'GET') {
                $queryParams = $this->request->getQuery();
                $rawBody = array_merge($rawBody, $queryParams);
            }

            $planModel = $this->di->getObjectManager()->get(Helper::class);
            $response = $planModel->getOrderLimitActivities($rawBody);
            return $this->prepareResponse($response);
        } catch (\Exception $e) {
            $this->di->getLog()->logContent('getOrderLimitActivitiesAction(): ' . json_encode($e->getMessage()), 'info', 'exception.log');
            $response = [
                'success' => false,
                'message' => 'An error occurred while fetching order limit activities: ' . $e->getMessage()
            ];
            return $this->prepareResponse($response);
        }
    }

    public function increaseOrderLimitAction()
    {
        $response = [
            'success' => false,
            'message' => 'Not supported for now!'
        ];
        return $this->prepareResponse($response);
        $rawBody = $this->getRequestData();
        $method = $this->request->getMethod();
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        return $this->prepareResponse($planModel->increaseOrderLimit($rawBody, $method));
    }

    public function fetchActivePlanDetailsAction()
    {
        $rawBody = $this->getRequestData();
        $planHelper = $this->di->getObjectManager()->get(\App\Plan\Components\Helper::class);
        return $this->prepareResponse($planHelper->fetchActivePlanDetails($rawBody));
    }

    public function fetchServiceByTypeAction()
    {
        $rawBody = $this->getRequestData();
        $planHelper = $this->di->getObjectManager()->get(\App\Plan\Components\Helper::class);
        return $this->prepareResponse($planHelper->fetchServiceByType($rawBody));
    }
    public function increaseServiceCreditsAction()
    {
        $rawBody = $this->getRequestData();
        $planHelper = $this->di->getObjectManager()->get(\App\Plan\Components\Helper::class);
        return $this->prepareResponse($planHelper->increaseServiceCredits($rawBody));
    }
    public function activateOrDeactivateAdditionalServicesAction()
    {
        $rawBody = $this->getRequestData();
        $planHelper = $this->di->getObjectManager()->get(\App\Plan\Components\Helper::class);
        return $this->prepareResponse($planHelper->activateOrDeactivateAdditionalServices($rawBody));
    }
}
