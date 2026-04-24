<?php

namespace App\Frontend\Components\AmazonMulti;

use App\Core\Components\Base;
class SettlementAmountsHelper extends Base
{


    /**
     * get aggegate for settlement Amount
     */

     public function getAggregateQuery(){
        $aggregate = [];
        $aggregate[] = ['$match' => ['type' => "settlement_invoice", 'status' => "pending", 'postpaid' => ['$exists' => true], 'postpaid.total_used_credits' => ['$ne' => 0]]];
        $aggregate[] = ['$sort' => ['postpaid.total_used_credits' => -1]];
        $aggregate[] =  ['$lookup' => ['from' => 'user_details', 'localField' => 'user_id', 'foreignField' => 'user_id', 'as' => 'user']];
        $aggregate[] = ['$addFields' => ['user' => ['$arrayElemAt' => ['$user', 0]]]];
        $aggregate[] = ['$addFields' => ['shops' => ['$arrayElemAt' => ['$user.shops', 0]]]];
        $aggregate[] = ['$addFields' => ['apps' => ['$arrayElemAt' => ['$shops.apps', 0]]]];
        $aggregate[] = ['$match' => ['apps.app_status' => 'active']];
        $aggregate[] =  ['$addFields' => ['username' => '$user.username']];
        $aggregate[] =  ['$addFields' => ['email' => '$user.email']];
        $aggregate[] =  ['$addFields' => ['created' => ['$dateFromString'=>["dateString"=>'$created_at']]]];
        $aggregate[] =  ['$addFields' => ['updated' => ['$dateFromString'=>["dateString"=>'$updated_at']]]];

        $aggregate[] = ['$match' => ['username' => ['$exists' => true]]];
        return $aggregate;
     }

    /**
     * return total settlement Amount
     */
    public function getSettleMentAmounts($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('payment_details');
        $aggregate = $this->getAggregateQuery();
        $aggregate[] = ['$group' => ['_id' => null, 'total' => ['$sum' => '$settlement_amount']]];
        $result = $collection->aggregate($aggregate)->toArray();
        return [
            'success' => true,
            'amount' => $result[0]['total'] ?? 0,
        ];
    }

    /**
     * formatting grid
     */
    public function formatSettlementData($data)
    {
        $tempUserRows = [];
        foreach ($data as $key => $value) {
            $tempUserRows[$key]['service_credits'] = $data[$key]['prepaid']['service_credits'];
            $tempUserRows[$key]['user_id'] = $data[$key]['user_id'];
            $tempUserRows[$key]['username'] = $data[$key]['username'];
            $tempUserRows[$key]['email'] = $data[$key]['email'];
            $tempUserRows[$key]['created_at'] = $data[$key]['created_at'];
            $tempUserRows[$key]['updated_at'] = $data[$key]['updated_at'];
            $tempUserRows[$key]['last_payment_date'] = $data[$key]['last_payment_date'];
            $tempUserRows[$key]['postpaid_used_credits'] = $data[$key]['postpaid']['total_used_credits'];
            $tempUserRows[$key]['postpaid_available_credits'] = $data[$key]['postpaid']['available_credits'];
            $tempUserRows[$key]['postpaid_settlement_amount'] = $data[$key]['settlement_amount'];
        }

        return $tempUserRows;
    }

    /**
     * getSettlemet Grid data
     */
    public function getSettlementGrid($userData)
    {
        $count = 10;
        $activePage = 1;
        if (isset($userData['count'])) {
            $count = $userData['count'];
        }

        if (isset($userData['activePage'])) {
            $activePage = $userData['activePage'] - 1;
        }

        $skip = (int)$count * (int)$activePage;
        $filters = isset($userData['filter']) ? $userData : [];
        $conditionalQuery = [];
        $aggregate = $this->getAggregateQuery();
        if (count($filters)) {
            $helper = $this->di->getObjectManager()->get(Helper::class);
            $conditionalQuery['$and'][] = $helper->searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $aggregate[] = ['$skip' => $skip];
        $aggregate[] = ['$limit' => (int)$count];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('payment_details');
        $userDetails = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'query' => $aggregate, 'data' => [
            'rows' => $this->formatSettlementData($userDetails),
            'data'=>$userDetails
        ]];
    }

    /**
     * getSettlemet Grid count
     */
    public function getSettlementGridCount($userData)
    {
        $filters = isset($userData['filter']) ? $userData : [];
        $conditionalQuery = [];
        $aggregate = $this->getAggregateQuery();
        if (count($filters)) {
            $helper = $this->di->getObjectManager()->get(Helper::class);
            $conditionalQuery['$and'][] = $helper->searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $aggregate[] = ['$count' => 'total'];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('payment_details');
        $userDetails = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'count' => $userDetails[0]['total'] ?? 0];
    }

    /**
     * get order usage
     */
    public function getOrderUsage($params)
    {
        if (isset($params['user_id']) && !empty($params['user_id'])) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $planCollection = $mongo->getCollectionForTable('payment_details');
            $planData = $planCollection->find(['user_id' => $params['user_id'], 'type' => 'monthly_usage'], ["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();
            $data = [];
            foreach ($planData as $val) {
                $data[$val['month'] . '-' . $val['year']] = ['prepaid' => $val['used_services'][0]['prepaid']['total_used_credits'], 'postpaid' => $val['used_services'][0]['postpaid']['total_used_credits']];
            }

            return [
                'success' => true,
                'data' => $data
            ];
        }
        return [
            'success' => false,
            'msg' => "User Id Missing"
        ];
    }
}
