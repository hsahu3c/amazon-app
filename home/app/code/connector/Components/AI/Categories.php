<?php

namespace App\Connector\Components\AI;

use App\Core\Models\BaseMongo;

class Categories extends BaseMongo
{
    private $DbConfig;

    private $amazonMultiDataBaseInfoRemote;

    private $amazonMultiRemote;

    private string $collection = 'category';

    public $userId;

    private function setDbSource(array $database)
    {

        $dsn =  $database['host'];
        $mongo = new \MongoDB\Client($dsn, array("username" => $database['username'], "password" => $database['password']));
        $mongo =  $mongo->selectDatabase($database['dbname']);

        return $mongo;
    }

    private function init(array $data = []): void
    {
        $this->DbConfig = $this->di->getConfig()->get('databases')->toArray();

        $this->amazonMultiDataBaseInfoRemote = $this->DbConfig['remote_db'];
        $this->amazonMultiRemote = $this->setDbSource($this->amazonMultiDataBaseInfoRemote);;


        if (isset($data['user_id'])) {
            $this->userId = $data['user_id'];
        } else {
            $this->userId = $this->di->getUser()->id;
        }
    }

    /**
     * @return array<mixed, array<'$match', array<'_id', array<'$in', \MongoDB\BSON\ObjectId[]>>>>
     */
    private function getAggregateForCategory($data): array
    {
        $ids = [];
        foreach ($data as $val) {
            array_push($ids, new \MongoDB\BSON\ObjectId($val));
        }

        $aggregate = [['$match' => ['_id' => ['$in' => $ids]]]];
        return $aggregate;
    }

    private function getFilteredCategories($data)
    {
        $categories = $this->amazonMultiRemote->selectCollection($this->collection)->aggregate($this->getAggregateForCategory($data))->toArray();
        return $categories;
    }

    public function getCategories($data)
    {
        if (!isset($data['ids']) && gettype($data['ids']) !== 'array') {
            return ['success' => false, 'message' => 'ids missing or type must be array'];
        }

        $this->init($data);
        $categories = $this->getFilteredCategories($data['ids']);
        return ['success' => true, 'data' => $categories];
    }
}
