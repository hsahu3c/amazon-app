<?php

namespace App\Connector\Models\MongostreamV2;

use App\Connector\Models\MongostreamV2\Helper;

class DataWrapper
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function __debugInfo()
    {
        $debugData = $this->data;
        if (is_callable($debugData['getProductInfo'])) {
            $debugData['getProductInfo'] = 'Closure for fetching product data';
        }

        return $debugData;
    }

    public function getData()
    {
        return $this->data;
    }

    public function getProductInfo($additionalParams = [])
    {
        return (new Helper())->getProduct($this->data + $additionalParams);
    }
}
