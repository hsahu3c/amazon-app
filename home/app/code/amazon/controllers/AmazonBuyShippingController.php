<?php

namespace App\Amazon\Controllers;

use App\Core\Controllers\BaseController;
use App\Amazon\Components\AmazonBuyShipping\AmazonBuyShipping;

/**
 * Class AmazonBuyShippingController
 * @package App\Amazon\Controllers
 */
class AmazonBuyShippingController extends BaseController
{
    public function getRatesAction()
    {
        $requestData = $this->getRequestData();
        $getRatesResponse = $this->getAmazonBuyShippingHelper()->getRates($requestData);
        return $this->prepareResponse($getRatesResponse);
    }

    public function purchaseShipmentAction()
    {
        $requestData = $this->getRequestData();
        $purchaseShipmentResponse = $this->getAmazonBuyShippingHelper()->saveDataAndPurchaseShipment($requestData);
        return $this->prepareResponse($purchaseShipmentResponse);
    }

    public function getAllDataAction()
    {
        $requestData = $this->getRequestData();
        $getAllDataResponse = $this->getAmazonBuyShippingHelper()->getAllData($requestData);
        return $this->prepareResponse($getAllDataResponse);
    }

    public function getTrackingAction()
    {
        $requestData = $this->getRequestData();
        $getTrackingResponse = $this->getAmazonBuyShippingHelper()->getTracking($requestData);
        return $this->prepareResponse($getTrackingResponse);
    }

    public function cancelShipmentAction()
    {
        $requestData = $this->getRequestData();
        $cancelShipmentResponse = $this->getAmazonBuyShippingHelper()->cancelShipment($requestData);
        return $this->prepareResponse($cancelShipmentResponse);
    }

    public function getShipmentDocumentsAction()
    {
        $requestData = $this->getRequestData();
        $getShipmentDocumentsResponse = $this->getAmazonBuyShippingHelper()->getShipmentDocuments($requestData);
        return $this->prepareResponse($getShipmentDocumentsResponse);
    }

    public function getAmazonBuyShippingHelper()
    {
        return $this->di->getObjectManager()->get(AmazonBuyShipping::class);
    }
}