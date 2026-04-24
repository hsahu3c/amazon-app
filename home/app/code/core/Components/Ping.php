<?php

namespace App\Core\Components;

/**
 * Measure the latency of mongo and redis
 */
class Ping extends Base
{
    public $decimalPlaces = 3; // Number of decimal places to measure to 
    /**
     * Measure the latency of redis by setting a key
     *
     * @return float
     */
    public function pingRedis(): float
    {
        $startTime = microtime(true);
        $this->setCache("ping_test_cache", $startTime);
        $duration = microtime(true) - $startTime;
        return round($duration, $this->decimalPlaces);
    }
    /**
     * Measure mongodb latency by reading a role from acl_role
     *
     * @return float
     */
    public function pingMongo(): float
    {
        $role = $this->di->getObjectManager()->get("App\Core\Models\Acl\Role");
        $startTime = microtime(true);
        $role->getRole(["group_code" => "admin"]);
        $duration = microtime(true) - $startTime;
        return round($duration, $this->decimalPlaces);
    }
    /**
     * Measuring both latencies and returning formatted output
     *
     * @return void
     */
    public function pingBoth()
    {
        $result = [];
        $result["redis"] = $this->pingRedis() . " seconds";
        $result["mongo"] = $this->pingMongo() . " seconds";
        return $result;
    }
}
