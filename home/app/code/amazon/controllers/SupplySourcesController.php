<?php

namespace App\Amazon\Controllers;

use App\Amazon\Components\SupplySource\SupplySource;
use App\Core\Controllers\BaseController;

class SupplySourcesController extends BaseController
{
    public function createAction()
    {
        $requestData = $this->getRequestData();
        $requestData['user_id'] = $this->di->getUser()->id;
        $requestData['source']['shop_id'] ??= $this->di->getRequester()->getSourceId() ?? false;
        $requestData['shop_id'] ??= $this->di->getRequester()->getTargetId() ?? false;

        $supplySourcesHelper = $this->di->getObjectManager()->get(SupplySource::class);
        return $this->prepareResponse($supplySourcesHelper->create($requestData));
    }

    public function updateAction()
    {
        $requestData = $this->getRequestData();
        $requestData['user_id'] = $this->di->getUser()->id;
        $requestData['source']['shop_id'] ??= $this->di->getRequester()->getSourceId() ?? false;
        $requestData['shop_id'] ??= $this->di->getRequester()->getTargetId() ?? false;

        $supplySourcesHelper = $this->di->getObjectManager()->get(SupplySource::class);
        return $this->prepareResponse($supplySourcesHelper->update($requestData));
    }

    public function fetchSupplySourcesAction()
    {
        $requestData = $this->getRequestData();
        $requestData['user_id'] = $this->di->getUser()->id;
        $requestData['source']['shop_id'] ??= $this->di->getRequester()->getSourceId() ?? false;
        $requestData['shop_id'] ??= $this->di->getRequester()->getTargetId() ?? false;

        $supplySourcesHelper = $this->di->getObjectManager()->get(SupplySource::class);
        return $this->prepareResponse($supplySourcesHelper->fetchSupplySourcesFromAmazon($requestData));
    }

    public function getSupplySourceAction()
    {
        $requestData = $this->getRequestData();
        $requestData['user_id'] = $this->di->getUser()->id;
        $requestData['source']['shop_id'] ??= $this->di->getRequester()->getSourceId() ?? false;
        $requestData['shop_id'] ??= $this->di->getRequester()->getTargetId() ?? false;

        $supplySourcesHelper = $this->di->getObjectManager()->get(SupplySource::class);
        return $this->prepareResponse($supplySourcesHelper->fetchSupplySourceFromAmazon($requestData));
    }

    public function updateStatusAction()
    {
        $requestData = $this->getRequestData();
        $requestData['user_id'] = $this->di->getUser()->id;
        $requestData['source']['shop_id'] ??= $this->di->getRequester()->getSourceId() ?? false;
        $requestData['shop_id'] ??= $this->di->getRequester()->getTargetId() ?? false;

        $supplySourcesHelper = $this->di->getObjectManager()->get(SupplySource::class);
        return $this->prepareResponse($supplySourcesHelper->updateStatusOnAmazon($requestData));
    }

    public function archiveAction()
    {
        $requestData = $this->getRequestData();
        $requestData['user_id'] = $this->di->getUser()->id;
        $requestData['source']['shop_id'] ??= $this->di->getRequester()->getSourceId() ?? false;
        $requestData['shop_id'] ??= $this->di->getRequester()->getTargetId() ?? false;

        $supplySourcesHelper = $this->di->getObjectManager()->get(SupplySource::class);
        return $this->prepareResponse($supplySourcesHelper->archiveSupplySource($requestData));
    }

    public function getAllSupplySourcesAction()
    {
        $requestData = $this->getRequestData();
        $requestData['user_id'] = $this->di->getUser()->id;
        $requestData['shop_id'] ??= $this->di->getRequester()->getTargetId() ?? false;

        $supplySourcesHelper = $this->di->getObjectManager()->get(SupplySource::class);
        return $this->prepareResponse($supplySourcesHelper->getAllSupplySourcesFromDb($requestData));
    }

    public function linkSupplySourceToWarehouseAction()
    {
        $requestData = $this->getRequestData();
        $requestData['user_id'] = $this->di->getUser()->id;
        $requestData['shop_id'] ??= $this->di->getRequester()->getTargetId() ?? false;

        $supplySourcesHelper = $this->di->getObjectManager()->get(SupplySource::class);
        return $this->prepareResponse($supplySourcesHelper->linkSupplySourceToWarehouse($requestData));
    }

    public function unlinkSupplySourceFromWarehouseAction()
    {
        $requestData = $this->getRequestData();
        $requestData['user_id'] = $this->di->getUser()->id;
        $requestData['shop_id'] ??= $this->di->getRequester()->getTargetId() ?? false;

        $supplySourcesHelper = $this->di->getObjectManager()->get(SupplySource::class);
        return $this->prepareResponse($supplySourcesHelper->unlinkSupplySourceFromWarehouse($requestData));
    }

    public function getStatusWiseCountAction()
    {
        $requestData = $this->getRequestData();
        $requestData['user_id'] = $this->di->getUser()->id;
        $requestData['shop_id'] ??= $this->di->getRequester()->getTargetId() ?? false;

        $supplySourcesHelper = $this->di->getObjectManager()->get(SupplySource::class);
        return $this->prepareResponse($supplySourcesHelper->getStatusWiseCount($requestData));
    }
}
