<?php

namespace App\Frontend\Components\AmazonMulti;

use App\Core\Components\Base;
class SuperChargeHelper extends Base
{
    public function getMethod($methodName, $data)
    {
        return $this->$methodName($data);
    }

    private function getSuperChargeUsers($data): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('refine_user_details');
        $activePage = $data['activePage'] ?? 1;
        $skip = ((int)$activePage - 1) * 100;

        $filters = isset($data['filter']) ? $data : [];

        $aggregate[] = ['$sort' => ['_id' => -1]];
        $aggregate[] = ['$match' => ['superChargeAction' => true]];
        $aggregate[] = ['$lookup' => ['from' => 'ai_services', 'localField' => 'user_id', 'foreignField' => 'user_id', 'as' => 'user']];
        $aggregate[] =  ['$addFields' => ['user' => ['$arrayElemAt' => ['$user', 0]]]];
        if (count($filters)) {
            $helper = $this->di->getObjectManager()->get(Helper::class);
            $conditionalQuery['$and'][] = $helper->searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $aggregate[] = ['$skip' => $skip];
        $aggregate[] = ['$limit' => 10];
        $sales = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'data' => $sales];
    }

    private function getSuperChargeUsersCount($data): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('refine_user_details');
        $filters = isset($data['filter']) ? $data : [];

        $aggregate[] = ['$sort' => ['_id' => -1]];
        $aggregate[] = ['$match' => ['superChargeAction' => true]];
        $aggregate[] = ['$lookup' => ['from' => 'ai_services', 'localField' => 'user_id', 'foreignField' => 'user_id', 'as' => 'user']];
        $aggregate[] =  ['$addFields' => ['user' => ['$arrayElemAt' => ['$user', 0]]]];
        if ($filters !== []) {
            $helper = $this->di->getObjectManager()->get(Helper::class);
            $conditionalQuery['$and'][] = $helper->searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $aggregate[] = ['$count' => 'total'];

        $sales = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'data' => $sales[0]['total'] ?? 0];
    }
}
