<?php

namespace App\Core\Models;

use Phalcon\Logger\Logger;

class RequestLog extends \App\Core\Models\BaseMongo
{
    protected $table = 'request_log';
    public $isGlobal = true;
    public function initialize()
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDefaultDb());
    }
    public function get()
    {
        $collection = $this->getCollection();
        return $collection->find()->toArray();
    }
    public function insert($data)
    {
        if (!trim($data["url"])) return false;
        $collection = $this->getCollection();
        $log = $collection->findOne(['_id' => (string) isset($data['_id']) ? $data['_id'] : -1]);
        if ($log) {
            $id = $data['_id'];
            unset($data['_id']);

            $updateResult = $collection->updateOne(
                ['_id' => (string)$id],
                ['$set' => $data]
            );
            if ($updateResult->getMatchedCount()) {
                return true;
            }
            return false;
        } else {
            $data['created_at'] = new \MongoDB\BSON\UTCDateTime();
            $requestLog = $collection->insertOne($data);
            if ($requestLog->getInsertedCount()) {
                return true;
            } else {
                $errors = implode(',', $requestLog->getMessages());
                $this->di->getLog()->logContent(
                    $errors,
                    Logger::CRITICAL,
                    'request_log_insert.log'
                );
                return false;
            }
        }
    }
    /**
     * calculates the average time of the url
     *
     * @param int $oldAvg
     * @param int $oldLength
     * @param int $newValue
     * @return int
     */
    public function greedyAvg($oldAvg, $oldLength, $newValue)
    {
        return ((($oldAvg * $oldLength) + $newValue) / ($oldLength + 1));
    }
}
