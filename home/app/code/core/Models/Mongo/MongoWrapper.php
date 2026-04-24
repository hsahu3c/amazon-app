<?php

namespace App\Core\Models\Mongo;

/**
 * wraps a mongodb connection to intercept queries
 */
class MongoWrapper
{
    public function __construct($context, $isReadDb = false)
    {
        $this->db = $isReadDb ?
            ($context->di->has("readDb") ? $context->di->getReadDb() : $context->di->getDb())
            :
            $context->di->getDb();
        $this->preservedConnection = $this->db;
        $this->logger = $context->di->getDb()->selectCollection('queries_log');
    }
    protected function logQuery($data)
    {
        $data["request_id"] = $_SERVER["HTTP_REQUEST_ID"] ?? microtime(true);
        $data["location"] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[2] ?? null;
        $this->logger->insertOne($data);
    }
    public function __call($method, $arguments)
    {
        if ($method === 'selectCollection') {
            if (!$this->db instanceof \MongoDB\Collection)
                $this->db = $this->db->selectCollection($arguments[0]);
            return $this;
        }
        $timeTaken = floor(microtime(true) * 1000);
        $result = call_user_func_array([$this->db, $method], $arguments ?? []);
        $data["time_taken"] = (floor(microtime(true) * 1000) - $timeTaken);
        if ($this->db instanceof \MongoDB\Collection) {
            $data["collection"] = $this->db->getCollectionName() ?? "can't determine";
            $data["operation"] = $method;
            $data["query"] = $arguments;
            $data["at"] = new \MongoDB\BSON\UTCDateTime();
            $this->logQuery($data);
        }
        return $result;
    }
    public function __get($method)
    {
        if ($this->db instanceof \MongoDB\Collection)
            $this->db = $this->preservedConnection->selectCollection($method);
        else
            $this->db = $this->db->selectCollection($method);
        return $this;
    }
}
