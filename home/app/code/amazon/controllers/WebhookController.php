<?php

namespace App\Amazon\Controllers;

use App\Core\Controllers\BaseController;
use App\Amazon\Components\Notification\Helper;
use App\Core\Models\User;
use Exception;
class WebhookController extends BaseController
{

    public function registerAmazonAppWebhooksUserWiseAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (!empty($rawBody['userIds'])) {
            $notificationObject = $this->di->getObjectManager()->get(Helper::class);
            foreach ($rawBody['userIds'] as $userId) {
                $response = $this->setDiForUser($userId);
                if (isset($response['success']) && $response['success']) {
                    $shops = $this->di->getUser()->shops;
                    foreach ($shops as $shopData) {
                        if (
                            $shopData['marketplace'] == 'amazon'
                            && isset($shopData['apps'])
                        ) {
                            $webhookData = $shopData['apps'][0]['webhooks'] ?? [];
                            if (empty($webhookData) || count($webhookData) < 5) {
                                $sources = $shopData['sources'][0] ?? [];
                                if (!empty($sources)) {
                                    $dataToSent['user_id'] = $userId;
                                    $dataToSent['source_marketplace'] = [
                                        'marketplace' => $sources['marketplace'],
                                        'shop_id' => $sources['shop_id']
                                    ];
                                    $dataToSent['target_marketplace'] = [
                                        'marketplace' => $shopData['marketplace'],
                                        'shop_id' => $shopData['_id']
                                    ];
                                    $notificationObject->createSubscription($dataToSent);
                                }
                            }
                        }
                    }
                }
            }
        }

        return $this->prepareResponse(['success' => true, 'message' => 'Process completed successfully!!']);
    }

    public function setDiForUser($userId)
    {
        if ($this->di->getUser()->id == $userId) {
            return [
                'success' => true,
                'message' => 'user already set in di'
            ];
        }

        try {
            $getUser = User::findFirst([['_id' => $userId]]);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        if (empty($getUser)) {
            return [
                'success' => false,
                'message' => 'User not found'
            ];
        }

        $getUser->id = (string) $getUser->_id;
        $this->di->setUser($getUser);
        if ($this->di->getUser()->getConfig()['username'] == 'admin') {
            return [
                'success' => false,
                'message' => 'user not found in DB. Fetched di of admin.'
            ];
        }

        return [
            'success' => true,
            'message' => 'user set in di successfully'
        ];
    }
}