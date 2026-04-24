<?php

namespace App\Plan\Models;

use App\Core\Models\BaseMongo as CoreBaseMongo;

class BaseMongo extends CoreBaseMongo
{

    public $logFile;

    public $paymentCollection = null;

    public $baseMongo = null;

    public $planCollection = null;

    public $commonObj = null;

    public const TYPEMAP_OPTIONS = ["typeMap" => ['root' => 'array', 'document' => 'array']];

    public const PAYMENT_DETAILS_COLLECTION_NAME = 'payment_details';

    public const PLAN_COLLECTION_NAME = 'plan';

    /**
     * @param $source
     * @param $params
     * @return array
     */
    public function getFilteredResults($source, $params, $sort = [])
    {
        $collection = $this->getCollection($source);
        $limit = $params['count'] ?? 20;
        $page = $params['activePage'] ?? 1;
        $offset = ($page - 1) * $limit;
        $options = [
            'limit' => (int)$limit,
            'skip' => (int)$offset,
            "typeMap" => ['root' => 'array', 'document' => 'array']
        ];
        $conditionalQuery = self::search($params);
        if (count($sort)) {
            $options["sort"] = $sort;
        }

        $rows = $collection->find($conditionalQuery, $options);
        $totalRows = $collection->find($conditionalQuery);
        $responseData = [];
        $responseData['success'] = true;
        $responseData['data']['rows'] = $rows->toArray();
        $responseData['data']['count'] = count($totalRows->toArray());
        return $responseData;
    }

    /**
     * to check if plan is free
     */
    public function isFreePlan($planDetails)
    {
        if (isset($planDetails['custom_price'], $planDetails['title']) && ($planDetails['custom_price'] == 0) && (strtolower((string) $planDetails['title']) == 'free')) {
            return true;
        }

        return false;
    }

    /**
     * to check if plan is custom
     */
    public function isCustomPlanOnetime($planDetails)
    {
        if (isset($planDetails['custom_plan']) && $planDetails['custom_plan'] && !isset($planDetails['payment_type'])) {
            return true;
        }

        if (isset($planDetails['custom_plan']) && $planDetails['custom_plan']
                && isset($planDetails['payment_type'])
                && ($planDetails['payment_type'] == Plan::BILLING_TYPE_ONETIME)) {
            return true;
        }

        return false;
    }

     /**
     * to check if plan is trial
     */
    public function isTrialPlan($planDetails)
    {
        return isset($planDetails['code']) && (strtolower((string) $planDetails['code']) == 'trial') ? true : false;
    }

    public function isMaxPlan($planDetails, $userService = [])
    {
        if (
            ($planDetails['custom_price'] >= Plan::MAX_MONTHLY_PAID_PLAN_PRICE)
            && ($planDetails['billed_type'] == Plan::BILLED_TYPE_MONTHLY)
            && !isset($planDetails['custom_plan'])
        ) {
            return true;
        }
        if (!empty($userService['prepaid']['service_credits']) && ($userService['prepaid']['service_credits'] >= Plan::MAX_PLAN_ORDER_CREDIT_COUNT)) {
            return true;
        }
        return false;
    }
}
