<?php
namespace App\Frontend\Controllers;
use App\Core\Controllers\BaseController;
use App\Frontend\Components\AmazonebaymultiHelper;
class AmazonebaymultiController extends BaseController
{
    public function getShopDetailsAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);

        return $this->prepareResponse($helper->getShopDetails($rawBody));
    }

    public function saveregistrationdataAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);
        return $this->prepareResponse($helper->saveRegistrationData($rawBody));
    }

    public function getaccountsconnectedAction(){
        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);
        return $this->prepareResponse($helper->getAccountsConnected());
    }

    public function savefiltersAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);
        return $this->prepareResponse($helper->saveFilterData($rawBody));
    }

    public function getConfigurationInformationAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);
        return $this->prepareResponse($helper->getConfigurationData($rawBody));
    }

    public function getConnectedAccountAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);
        return $this->prepareResponse($helper->getAllConnectedAcccounts(false, $rawBody));
    }

    public function shiporderonAmazonAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = "No order found to ship";
        if (isset($rawBody['source_order_ids']) && !empty($rawBody['source_order_ids'])) {
            $response =  $this->di->getObjectManager()->get('\App\Amazon\Components\Order')
                ->init(['shop_id' => 8, 'user_id' => 3])
                ->shipOrders($rawBody['source_order_ids']);
        }

        return $this->prepareResponse($response);
    }

    public function createOrderonShopifyAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (isset($rawBody['source_order_ids']) && !empty($rawBody['source_order_ids'])) {
            foreach ($rawBody['source_order_ids'] as $amazonOrderId) {
                $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Order')->init(['shop_id' => 8])
                    ->fetchOrders(['accounts' => 8, 'order_id' => $amazonOrderId]);
            }
        }

        $response = ['success' => true, 'message' => 'Amazon Order(s) created on Shopify Successfully'];
        return $this->prepareResponse($response);
    }

    public function syncorderonAmazonAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (isset($rawBody['source_order_ids']) && !empty($rawBody['source_order_ids'])) {
            foreach ($rawBody['source_order_ids'] as $amazonOrderId) {
                $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Order')->init(['shop_id' => 8])
                    ->fetchOrders(['accounts' => 8, 'order_id' => $amazonOrderId]);
            }
        }

        $response = ['success' => true, 'message' => 'Amazon Order(s) synced Successfully'];
        return $this->prepareResponse($response);
    }

    public function saveProfileAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);
        return $this->prepareResponse($helper->saveProfile($rawBody));
    }

    public function saveSalesProfileAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);
        return $this->prepareResponse($helper->saveSalesProfile($rawBody));
    }

    public function getSalesProfilebyIdAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);
        return $this->prepareResponse($helper->getSalesProfileId($rawBody));
    }

    public function getProfilebyIdAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);
        return $this->prepareResponse($helper->getProfilebyId($rawBody));
    }

    public function getAllSalesProductTemplatesAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);
        return $this->prepareResponse($helper->getAllSalesProductTemplates($rawBody));
    }

    public function saveGlobalSettingsAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);
        return $this->prepareResponse($helper->saveGlobalSettings($rawBody));
    }

    public function getGlobalSettingsAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);
        return $this->prepareResponse($helper->getGlobalSettings($rawBody));
    }

    public function saveSyncSettingsAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);
        return $this->prepareResponse($helper->saveSyncSettings($rawBody));
    }

    public function getSyncSettingsAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);
        return $this->prepareResponse($helper->getSyncSettings($rawBody));
    }

    public function deleteProfileAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);
        return $this->prepareResponse($helper->deleteProfile($rawBody));
    }

    public function deleteSalesChannelProfileAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);
        return $this->prepareResponse($helper->deleteSalesChannelProfile($rawBody));
    }

    public function deleteAccountAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);
        return $this->prepareResponse($helper->deleteAccount($rawBody));
    }

    public function updateActiveInactiveShopAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);
        return $this->prepareResponse($helper->updateActiveInactiveShop($rawBody));
    }

    public function testAction(): void{
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        print_r($rawBody);
        die('test');
    }

    public function getOnboardingChecklistAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AmazonebaymultiHelper::class);
        return $this->prepareResponse($helper->getOnboardingChecklist());
    }
}
