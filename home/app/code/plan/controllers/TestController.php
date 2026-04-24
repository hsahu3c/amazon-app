<?php

namespace App\Plan\Controllers;

use App\Plan\Components\Method\ShopifyPayment;
use App\Core\Controllers\BaseController;
use App\Plan\Models\Plan;
use App\Plan\Models\BaseMongo;
use App\Plan\Components\SyncServices;

class TestController extends BaseController
{
    /**
     * to get and update entry in dynamo for a user
     */
    public function getAndUpdateDynamoEntryOfUserAction()
    {
        $rawBody = $this->getRequestData();
        $method = $this->request->getMethod();
        if(!isset($rawBody['remote_shop_id'])) {
            return $this->prepareResponse([
                'success' => false,
                'message' => "remote_shop_id required!"
            ]);
        }

        $remoteShopId = $rawBody['remote_shop_id'];
        return $this->prepareResponse($this->di->getObjectManager()->get(SyncServices::class)->getAndUpdateEntryInDynamo($remoteShopId, $rawBody, $method));
    }

    /**
     * to get and flush the cache of the user for product import service
     */
    public function getAndUpdateCacheForProductImportAction()
    {
        $rawBody = $this->getRequestData();
        $method = $this->request->getMethod();
        if(isset($rawBody['user_id']) || !empty($rawBody['user_id'])) {
            $userId = $rawBody['user_id'];
        } else {
            $userId = 'All';
        }

        if($userId == 'All') {
            if($method == "GET") {
                $response['data'] = $this->di->getCache()->getAll("plan");
            } elseif($method == "DELETE") {
                $response['success'] = $this->di->getCache()->flushByType("plan");
            } else {
                $response = "Invalid method!";
            }
        } else {
            if($method == "GET") {
                $response['data'] = $this->di->getCache()->get("import_restrictions_" . $userId, "plan");
            } elseif($method == "DELETE") {
                $response['success'] = $this->di->getCache()->delete("import_restrictions_" . $userId, "plan");
            } else {
                $response = "Invalid method!";
            }
        }

        return $this->prepareResponse($response);
    }

    public function getAndUpdateCacheForTrialExhaustAction()
    {
        $rawBody = $this->getRequestData();
        $method = $this->request->getMethod();
        if(isset($rawBody['user_id']) || !empty($rawBody['user_id'])) {
            $userId = $rawBody['user_id'] ?? "";
        }

        if($method == "GET") {
            $response['data'] = $this->di->getCache()->get("trial_plan_exhausted_" . $userId, "plan");
        } elseif($method == "DELETE") {
            $response['success'] = $this->di->getCache()->delete("trial_plan_exhausted_" . $userId, "plan");
        } else {
            $response = "Invalid method!";
        }

        return $this->prepareResponse($response);
    }

    public function removePickedAction()
    {
        $rawBody = $this->getRequestData();
        $response = [
            'success' => false,
            'message' => 'Proper info not provided!'
        ];
        if(isset($rawBody['proceed'])  && ($rawBody['proceed'] == "1")) {
            $mongo = $this->di->getObjectManager()->get(BaseMongo::class);
            $collection = $mongo->getCollection(Plan::PAYMENT_DETAILS_COLLECTION_NAME);
            $conditionFilterData = [
                'type' => Plan::PAYMENT_TYPE_USER_SERVICE,
                'service_type' => Plan::SERVICE_TYPE_ORDER_SYNC,
                'picked' => ['$exists' => true]
            ];
            if (isset($rawBody['user_id'])) {
                $conditionFilterData['user_id'] = $rawBody['user_id'];
            }

            if(isset($rawBody['count']) && ($rawBody['count'] == '1')) {
                $count = $collection->countDocuments($conditionFilterData);
                $response = [
                    'success' => true,
                    'message' => $count. ' docs found!'
                ];
            } elseif(isset($rawBody['unset']) && ($rawBody['unset'] == '1')) {
                $updateFilter = [
                    '$unset' => [
                        'picked' => 1
                    ]
                ];
                $result = $collection->updateMany($conditionFilterData, $updateFilter);
                $response = [
                    'success' => true,
                    'message' => $result->getModifiedCount(). ' docs modified!'
                ];
            }
        }

        return $this->prepareResponse($response);
    }

    public function testQLAction(): void
    {
        $rawBody = $this->getRequestData();
        $method = $this->request->getMethod();
        $billingType = $rawBody['billingType'] ?? "";
        $shopify = $this->di->getObjectManager()->get(ShopifyPayment::class);
        if(empty($rawBody['data']) || empty($billingType)) {
            $res = 'Data not found or billing type missing';
        } elseif ($rawBody['user'] == 'partner') {
                       $res = $shopify->createRefund($rawBody['data']);
        } else {
            if ($method == 'GET') {
                $res = $shopify->getPaymentData($rawBody['data'], $billingType);
            } elseif($method == 'POST') {
                $res = $shopify->createRemotePayment($rawBody['data'], $billingType);
            } elseif($method == 'DELETE') {
                $res = $shopify->cancelPayment($rawBody['data'], $billingType);
            } else {
                $res = 'Invalid method!';
            }
        }

        echo '<pre>'; print_r($res); die();
    }

    public function convertFreeToTrialAction()
    {
        $rawBody = $this->getRequestData();
        if (!isset($rawBody['user_id'])) {
            $response = [
                'success' => false,
                'message' => 'user_id required!'
            ];
        } elseif(isset($rawBody['user_id']) && $rawBody['user_id'] == 'all') {
            $response = $this->di->getObjectManager()->get(Plan::class)->convertFreeToTrial();
        } else {
            $response = $this->di->getObjectManager()->get(Plan::class)->convertFreeToTrial($rawBody['user_id'], true);
        }

        return $this->prepareResponse($response);
    }

    public function addAdditionalServicesAction()
    {
        $response = [
            'success' => false,
            'message' => 'Not supported for now!'
        ];
        return $this->prepareResponse($response);
        $rawBody = $this->getRequestData();
        $response = $this->di->getObjectManager()->get(Plan::class)->addAdditionalServices($rawBody);
        return $this->prepareResponse($response);
    }
}