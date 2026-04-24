<?php

namespace App\Frontend\Components\AmazonMulti;

use App\Core\Components\Base;
class ExhaustedGridHelper extends Base
{
    public function getExhaustedLimit($userData)
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
        $aggregate = [];

        $aggregate[] = ['$match' => ['type' => 'user_service', 'prepaid.available_credits' => 0]];
        $aggregate[] = ['$lookup' => ['from' => 'user_details', 'localField' => 'user_id', 'foreignField' => 'user_id', 'as' => 'user']];
        $aggregate[] =  ['$addFields' => ['user' => ['$arrayElemAt' => ['$user', 0]]]];
        $aggregate[] = ['$addFields' => ['shops' => ['$arrayElemAt' => ['$user.shops', 0]]]];
        $aggregate[] = ['$addFields' => ['apps' => ['$arrayElemAt' => ['$shops.apps', 0]]]];
        $aggregate[] = ['$match' => ['apps.app_status' => 'active']];
        $aggregate[] =  ['$addFields' => ['username' => '$user.username']];
        $aggregate[] =  ['$addFields' => ['email' => '$user.email']];
        $aggregate[] =  ['$addFields' => ['service_credits' => '$prepaid.service_credits']];
        $aggregate[] =  ['$addFields' => ['postpaid_credits' => '$postpaid.total_used_credits']];
        $aggregate[] = ['$match' => ['username' => ['$exists' => true]]];
        if (count($filters)) {
            $helper = $this->di->getObjectManager()->get(Helper::class);
            $conditionalQuery['$and'][] = $helper->searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $aggregate[] = ['$skip' => $skip];
        $aggregate[] = ['$limit' => (int)$count];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('payment_details');
        $payments = $collection->aggregate($aggregate)->toArray();
        return ['success' => true,  'data' => $payments];
    }

    public function getExhaustedLimitCount($userData)
    {
        $filters = isset($userData['filter']) ? $userData : [];
        $conditionalQuery = [];
        $aggregate = [];

        $aggregate[] = ['$match' => ['type' => 'user_service', 'prepaid.available_credits' => 0]];
        $aggregate[] = ['$lookup' => ['from' => 'user_details', 'localField' => 'user_id', 'foreignField' => 'user_id', 'as' => 'user']];
        $aggregate[] =  ['$addFields' => ['user' => ['$arrayElemAt' => ['$user', 0]]]];
        $aggregate[] = ['$addFields' => ['shops' => ['$arrayElemAt' => ['$user.shops', 0]]]];
        $aggregate[] = ['$addFields' => ['apps' => ['$arrayElemAt' => ['$shops.apps', 0]]]];
        $aggregate[] = ['$match' => ['apps.app_status' => 'active']];
        $aggregate[] =  ['$addFields' => ['username' => '$user.username']];
        $aggregate[] =  ['$addFields' => ['email' => '$user.email']];
        $aggregate[] =  ['$addFields' => ['service_credits' => '$prepaid.service_credits']];
        $aggregate[] =  ['$addFields' => ['postpaid_credits' => '$postpaid.total_used_credits']];
        $aggregate[] = ['$match' => ['username' => ['$exists' => true]]];
        if (count($filters)) {
            $helper = $this->di->getObjectManager()->get(Helper::class);
            $conditionalQuery['$and'][] = $helper->searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $aggregate[] = ['$count' => "count"];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('payment_details');
        $payments = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'count' => $payments[0]['count'] ?? 0];
    }

}