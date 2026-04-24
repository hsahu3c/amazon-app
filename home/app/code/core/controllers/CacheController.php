<?php

namespace App\Core\Controllers;

/**
 * Controller implementation for CacheTask
 */
class CacheController extends BaseController
{
    private $cache;
    /**
     * Initialize common variables
     *
     * @return void
     */
    public function initialize()
    {
        $this->cache = $this->getDi()->getCache();
    }
    /**
     * Cache flush
     *
     * @return void
     */
    public function flushAction()
    {
        $success = false;
        $error = false;
        try {
            if ($this->cache->flush()) {
                $success = true;
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
        if ($error) {
            $success = false;
        }
        $response = [
            "success" => $success,
            "error" => $error
        ];
        return $this->prepareResponse($response);
    }
    /**
     * List all keys in cache
     *
     * @return void
     * 
     * response format
     *      {success: true|false,
     *       keys: array of keys in cache,
     *       error: error message, if any | false} 
     */
    public function listAction()
    {
        $success = true;
        $error = false;
        try {
            $all = $this->cache->getAll();
        } catch (\Exception $e) {
            $success = false;
            $error = $e->getMessage();
        }
        $response = [
            "success" => $success,
            "keys" => $all,
            "error" => $error
        ];
        return $this->prepareResponse($response);
    }
}
