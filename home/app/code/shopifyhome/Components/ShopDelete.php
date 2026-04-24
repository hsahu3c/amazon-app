<?php

namespace App\Shopifyhome\Components;

use App\Shopifyhome\Components\Mail\Seller;

class ShopDelete
{

    public const MARKETPLACE = 'shopify';

    public function isAppActive($params)
    {
        $logFile = 'shopify'.DS.'uninstall'.DS.date('d-m-y').DS.'shopdelete'.DS.'app_delete.log';
        $this->di->getLog()->logContent('isAppActive:Request Received => ' . json_encode($params), 'info', $logFile);
        if(!isset($params['remote_shop_id'])) {
            return [
                'success' => false,
                'message' => 'Remote shop is manadatory'
            ];
        }

        $appCode = $params['app_code'] ?? 'default';
        $marketplace = $params['marketplace'] ?? self::MARKETPLACE;
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($marketplace, true, $appCode)
            ->call('/shop',[],['shop_id'=> $params['remote_shop_id']], 'GET');
        $this->di->getLog()->logContent('isAppActive:Remote Response => ' . json_encode($remoteResponse), 'info', $logFile);
        if (!isset($remoteResponse['success']) || !$remoteResponse['success']) {
            return [
                'success' => false,
                'message' => 'Error in fetching data from API - ' . ($remoteResponse['message'] ?? '')
            ];
        }

        return ['success' => true, 'message' => 'Shop data fetched. App is active.'];
    }

     /**
     * checkConnectedTargetsOrSources
     * Function is checking if the all targets are disconnected and source shop is active
     * then unregistering webhooks and deleting data from product collections.
     * @param string $userId
     * @param array $shop
     * @return null
     */
    public function checkConnectedTargetsOrSources($userId, $shopId)
    {
        $shopData = $this->di->getObjectManager()->get(\App\Core\Models\User\Details::class)
            ->getShop($shopId, $userId, true);

        if (empty($shopData)) {
            return ['success' => false, 'message' => 'Shop not found'];
        }
        if ($shopData['apps'][0]['app_status'] == 'active') {
            $hasNoTargets = isset($shopData['targets']) && empty($shopData['targets']);
            $hasNoSources = isset($shopData['sources']) && empty($shopData['sources']);

            if ($hasNoTargets || $hasNoSources) {
                $appCode = $shopData['apps'][0]['code'] ?? 'default';
                //Removing app level webhooks
                $this->di->getObjectManager()->get(\App\Connector\Models\SourceModel::class)
                    ->routeUnregisterWebhooks($shopData, self::MARKETPLACE, $appCode, false);
                //Removing Data from Product Container and Refine Container on last target or source disconnection
                $this->di->getObjectManager()->get(\App\Shopifyhome\Models\SourceModel::class)
                     ->removeDatafromProductAndRefineContainer($userId, $shopData);
                // Removing Data from Specific Collections
                $this->di->getObjectManager()->get(\App\Shopifyhome\Models\SourceModel::class)
                    ->deleteDataFromSpecificCollections($userId, $shopData);
            }
        }

        return ['success' => true, 'message' => 'Process Completed!!'];
}
    /**
     * shopEraserAfter function
     * This function is responsible for performing operation
     * after the shop data has been deleted.
     * @param [array] $data
    */
    public function shopEraserAfter($data)
    {
        if (
            !empty($data['apps']) && !empty($data['email']) &&
            !empty($data['marketplace']) && $data['marketplace'] == self::MARKETPLACE
        ) {
            $apps = reset($data['apps']);
            $marketplace  = $data['marketplace'];
            if (!empty($apps['code'])) {
                $mailData['email'] = $data['email'];
                $appCode = $apps['code'];
                $configData = $this->di->getConfig()->get('apiconnector')->get($marketplace)->get($appCode);

                $this->di->getObjectManager()->get(Seller::class)->uninstallNew($mailData, $configData);
            }
        }

        if (!empty($data['marketplace']) && $data['marketplace'] == self::MARKETPLACE) {
            $this->deleteDataFromSpecificCollections($this->di->getUser()->id, $data);
        }
    }

    /**
     * deleteDataFromSpecificCollections function
     * This function is responsible data from specific
     * collection or custom collection
     * after the shop data has been deleted.
     * @param [string] $userId
     * @param [array] $shop
     * @return null
     */
    public function deleteDataFromSpecificCollections($userId,  $shop)
    {
        $this->di->getObjectManager()->get(\App\Shopifyhome\Models\SourceModel::class)
            ->deleteDataFromSpecificCollections($userId, $shop);
    }
}
