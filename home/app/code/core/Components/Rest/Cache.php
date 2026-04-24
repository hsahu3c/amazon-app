<?php

namespace App\Core\Components\Rest;

use App\Core\Components\Rest\Base;

/**
 * Calling the respective functions from this class's respective component
 */
class Cache extends Base
{
    public function cacheFlush($type = false)
    {
        $response = ["success" => 'true'];
        if ($type == false) {
            $this->di->getCache()->flush();
            $response['message'] = "Default cache flushed";
        } elseif ($type == "all") {
            $this->di->getCache()->flushAll();
            $response['message'] = "All cache flushed";
        } else {
            $this->di->getCache()->flushByType($type);
            $response['message'] = "{$type} cache flushed";
        }
        return $response;
    }
    public function list($type)
    {
        if ($type) {
            return $this->component->getAll($type);
        } else {
            return $this->component->getAll();
        }
    }
    public function block($type, $ttl)
    {
        $ttl = ctype_digit($ttl) ? (int) $ttl : 1800;
        $res = $this->di->getCache()->blockCache((string)$type, $ttl);
        return ["success" => $res, "msg" => "cache $type blocked for $ttl seconds"];
    }
}
