<?php

namespace App\Connector\Controllers;

class CustomerController extends \App\Core\Controllers\BaseController
{

    public function getCustomersAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $customersModel = new \App\Connector\Models\Customer;
        $responseData = $customersModel->getCustomers($rawBody);
        return $this->prepareResponse($responseData);
    }

    public function createCustomerAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $customerData = $this->di->getObjectManager()->get('\App\Connector\Components\CustomerHelper')->getCustomerInRightFormat($this->di->getUser()->id, $rawBody);
        $customerModel = $this->di->getObjectManager()->create('\App\Connector\Models\Customer');
        return $this->prepareResponse($customerModel->createCustomer($customerData));
    }

    public function getCustomerAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        //Added this check for user authentication
        if(isset($rawBody['user_id'])) {
            $rawBody['user_id'] = $this->di->getUser()->id;
        }
        $customersModel = new \App\Connector\Models\CustomerContainer;
        $responseData = $customersModel->getCustomerByID($rawBody);
        return $this->prepareResponse($responseData);
    }

    public function getAllCustomersAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $customersModel = new \App\Connector\Models\CustomerContainer;
        $responseData = $customersModel->getCustomers($rawBody);
        return $this->prepareResponse($responseData);
    }

    public function getCustomerByIdAction()
    {
        $customersModel = new \App\Connector\Models\Customer();
        $responseData = $customersModel->getCustomerById($this->request->get());
        return $this->prepareResponse($responseData);
    }

    public function uploadCustomerAction()
    {
        $customersModel = new \App\Connector\Models\Customer();
        $responseData = $customersModel->uploadCustomer($this->request->get());
        return $this->prepareResponse($responseData);
    }

    public function updateCustomerStatusAction()
    {
        $customersModel = new \App\Connector\Models\Customer();
        $responseData = $customersModel->updateCustomerStatus($this->request->get());
        return $this->prepareResponse($responseData);
    }

    public function syncAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        return $this->prepareResponse($this->di->getObjectManager()->get('App\Connector\Components\CustomerHelper')->initiateCustomerSync($rawBody));
    }

    /**
     * Create Customer Action
     * @return string
     */
    public function importAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }else{
            $rawBody = $this->request->get();
        }
        
        $customer = $this->di->getObjectManager()->create('\App\Connector\Models\CustomerContainer');
        return $this->prepareResponse($customer->importCustomers($rawBody));
    }

    /**
     * Upload Order Action
     * @return string
     */
    public function uploadAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }else{
            $rawBody = $this->request->get();
        }
        
        $customer = $this->di->getObjectManager()->create('\App\Connector\Models\CustomerContainer');
        return $this->prepareResponse($customer->uploadCustomers($rawBody));
    }

    public function selectAndSyncAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $customerContainer = new \App\Connector\Models\CustomerContainer;
        return $this->prepareResponse($customerContainer->syncData($rawBody));
    }
    
    public function validateUserDetailsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $customerContainer = new \App\Connector\Models\CustomerModel;
        return $this->prepareResponse($customerContainer->validateUserDetails($rawBody));
    }
}
