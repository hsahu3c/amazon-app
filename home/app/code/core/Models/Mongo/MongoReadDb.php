<?php

namespace App\Core\Models\Mongo;

/**
 * Utilizes unused shards for read connections
 */
class MongoReadDb
{
    /**
     * returns a prepared connection to the best replica member
     * otherwise, primary instance is returned.
     *
     * @param string $key last updated key to estimate best connection
     * @return Mongo\MongoWrapper/MongoDB\Database
     */
    public function electBestShardRead($lastUpdatedMongoKey)
    {
        $lags = [];
        $primaryMember = null;
        $secondaryMembers = [];
        try {
            $data = $this->di->getDb()
                ->getManager()
                ->executeCommand(
                    'admin',
                    new \MongoDB\Driver\Command(
                        ['replSetGetStatus' => 1]
                    )
                );
        } catch (\Exception $e) {
            return $this->di->getDb();
        }
        foreach ($data->toArray()[0]->members as $member) {
            if ($member->stateStr === "(not reachable/healthy)") {
                continue;
            }
            if ($member->stateStr === 'SECONDARY') {
                $secondaryMembers[$member->name] = (string) $member->optimeDate;
            }
            if ($member->stateStr === 'PRIMARY') {
                $primaryMember = (string) $member->optimeDate;
            }
        }
        if (!count($secondaryMembers)) {
            return $this->di->getDb();
        }
        foreach ($secondaryMembers as $k => $v) {
            $lags[$k] = ($primaryMember - $v) / 1000;
        }
        // absolute time from key
        foreach ($lags as $lKey => $lValue) {
            $lastUpdatedKey = (explode(".", microtime(true))[0] * 1000 - $lastUpdatedMongoKey) / 1000;
            if ($lastUpdatedKey < $lValue)
                unset($lags[$lKey]);
        }
        arsort($lags);
        $finalConnectionStrings = array_keys($lags);
        if (count($finalConnectionStrings)) {
            $prefferedReadConnection = end($finalConnectionStrings);
        } else {
            return $this->di->getDb();
        }
        $database = $this->di->getConfig()->databases["db"];
        $connectionString = "mongodb://" .
            $database->get("username") . ":" .
            $database->get("password") . "@" .
            $prefferedReadConnection . "/?tls=true";
        try {
            $this->di->set("readDb", function () use ($connectionString, $database) {
                $shardInstance = new \MongoDB\Client($connectionString);
                return $shardInstance->selectDatabase($database->get("dbname"));
            }, false);
        } catch (\Exception $e) {
            return $this->di->getDb();
        }
        return $this->di->getReadDb();
    }
}
