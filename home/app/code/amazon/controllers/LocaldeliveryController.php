<?php

namespace App\Amazon\Controllers;

use App\Core\Controllers\BaseController;
use App\Amazon\Components\LocalDelivery;
class LocaldeliveryController extends BaseController
{

    public function getAllActiveLocationAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalDelivery::class);
        return $this->prepareResponse($helper->getAllActiveLocation($rawBody));

    }

    public function getProductsForLocalDeliveryAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalDelivery::class);
        return $this->prepareResponse($helper->getProductsForLocalDelivery($rawBody));
    }

    public function shippingTemplateCountLDAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalDelivery::class);
        return $this->prepareResponse($helper->shippingTemplateCountLD($rawBody));
    }

    public function saveProductForLocalDeliveryAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalDelivery::class);
        return $this->prepareResponse($helper->saveProductForLocalDelivery($rawBody));

    }

    public function getAllShippingTemplateFromDBAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalDelivery::class);
        return $this->prepareResponse($helper->getAllShippingTemplateFromDB($rawBody));
    }

    public function getAllShippingTemplateFromAmazonAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalDelivery::class);
        return $this->prepareResponse($helper->getAllShippingTemplateFromAmazon($rawBody));
    }

    public function createSkuForLocalDeliveryAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalDelivery::class);
        return $this->prepareResponse($helper->createSkuForLocalDelivery($rawBody));

    }

    public function getRefineProductsForLDAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalDelivery::class);
        return $this->prepareResponse($helper->getRefineProductsForLD($rawBody));

    }

    public function getRefineProductsCountForLDAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalDelivery::class);
        return $this->prepareResponse($helper->getRefineProductsCountForLD($rawBody));

    }
}
