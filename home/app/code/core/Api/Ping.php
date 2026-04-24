<?php

namespace App\Core\Api;

/**
 * Check connection status with Mongo and Redis
 */
class Ping extends \Phalcon\Di\Injectable
{
    /**
     * Ping both mongo and Redis
     *
     * @return array
     */
    public function get(): array
    {
        return $this->di->getObjectManager()->get('\App\Core\Components\Ping')->pingBoth();
    }
}
