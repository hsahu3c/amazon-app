<?php

namespace App\Amazon\Controllers;

use App\Core\Controllers\BaseController;
use App\Amazon\Components\Listings\ValueMapping;

class ListingController extends BaseController
{
    public function getJsonMappedValuesAction()
    {
        $rawBody = $this->getRequestData();
        return $this->prepareResponse($this->di->getObjectManager()->get(ValueMapping::class)->getJsonMappedValues($rawBody));
    }

    public function getValueMappingAttributesAction()
    {
        $rawBody = $this->getRequestData();
        return $this->prepareResponse($this->di->getObjectManager()->get(ValueMapping::class)->getValueMappingAttributes($rawBody));
    }

    public function saveJsonMappedValuesAction()
    {
        $rawBody = $this->getRequestData();
        return $this->prepareResponse($this->di->getObjectManager()->get(ValueMapping::class)->saveJsonMappedValues($rawBody));
    }
}