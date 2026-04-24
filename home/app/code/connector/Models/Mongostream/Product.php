<?php

namespace App\Connector\Models\Mongostream;

use App\Core\Models\BaseMongo;
class Product extends BaseMongo
{
   
    public function formatUserStatus($data)
    {
        $helper = new \App\Connector\Models\Mongostream\UserStatusHelper;
        return $helper->processUserStatus($data);
    }

    public function formatProductData($data)
    {
        $helper = new \App\Connector\Models\Mongostream\ProductDeleteHelper;
        return $helper->processProductDeleteEvent($data);
    }
  
    public function formatNextPrevData($data)
    {
        $helper = new \App\Connector\Models\Mongostream\NextPrevStateHelper;
        return $helper->processNextPrevEvent($data);
    }
}
