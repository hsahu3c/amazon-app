<?php

namespace App\Amazon\Controllers;

use App\Core\Controllers\BaseController;
use App\Amazon\Components\LocalSelling;
class LocalsellingController extends BaseController
{
    public function fetchAllStoresFromAmazonAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        return $this->prepareResponse($helper->fetchAllStoresFromAmazon($rawBody));

    }

    public function checkAlreadyExistStoreAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        return $this->prepareResponse($helper->checkAlreadyExistStore($rawBody));
    }

    public function createSupplySourceAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        return $this->prepareResponse($helper->createSupplySource($rawBody));
    }

    public function updateStatusSupplySourceAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $key = 'update';
        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        return $this->prepareResponse($helper->updateStatusSupplySource($rawBody, $key));
    }

    public function getAllListSupplySourceAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        return $this->prepareResponse($helper->getAllListSupplySource($rawBody));
    }

    public function getAllStoresAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        return $this->prepareResponse($helper->getAllStores($rawBody));
    }

    public function getAllLocationAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        // print_r($rawBody);die;
        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        return $this->prepareResponse($helper->getAllLocation($rawBody));
    }

    public function getBopisOrdersStatusCountAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        return $this->prepareResponse($helper->getBopisOrdersCountStatus($rawBody));

    }

    public function updateSupplySourceAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $key = 'update';
        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        return $this->prepareResponse($helper->updateSupplySource($rawBody, $key));
    }

    public function getDetailsSingleSupplySourceAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        return $this->prepareResponse($helper->getDetailsSingleSupplySource($rawBody));

    }

    public function archiveSupplySourceAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        return $this->prepareResponse($helper->archiveSupplySource($rawBody));
    }

    public function getStoreFromDBAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        return $this->prepareResponse($helper->saveSupplySource($rawBody));
    }

    public function getSingleBopisOrdersAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        return $this->prepareResponse($helper->getSingleBopisOrders($rawBody));
    }

    public function getBopisOrdersAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        return $this->prepareResponse($helper->getBopisOrders($rawBody));

    }

    public function getAllStoreFromDBAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        return $this->prepareResponse($helper->getAllStoreFromDB($rawBody));
    }

    public function updateStatusBOPISOrderAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        return $this->prepareResponse($helper->updateStatusBOPISOrder($rawBody));
    }

    public function getAllActiveStoresAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        return $this->prepareResponse($helper->getAllActiveStores($rawBody));
    }

    public function getAllInactiveStoresAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        return $this->prepareResponse($helper->getAllInactiveStores($rawBody));

    }

    public function updateBOPISOrderStatusAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        return $this->prepareResponse($helper->updateBOPISOrderStatus($rawBody));

    }
}    