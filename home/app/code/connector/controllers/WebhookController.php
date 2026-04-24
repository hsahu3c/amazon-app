<?php

/**
 * CedCommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_amazon
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright © 2018 CedCommerce. All rights reserved.
 * @license     EULA http://cedcommerce.com/license-agreement.txt
 */

namespace App\Connector\Controllers;

use App\Connector\Components\Webhook\SubscriptionService;

class WebhookController extends \App\Core\Controllers\BaseController

{
    public function triggerAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $params = $this->request->getJsonRawBody(true);
        } else {
            $params = $this->di->getRequest()->get();
        }

        $shopId = isset($params['source']['shopId']) ? $params['source']['shopId'] : $this->di->getRequester()->getSourceId();
        $sourceMarketplace = isset($params['source']['marketplace']) ? $params['source']['marketplace'] : $this->di->getRequester()->getSourceName();
        $destinationId = isset($params['data']['destination_id']) ? $params['data']['destination_id'] : false;
        $destinationWiseWebhooks = !empty($params['data']['selected_webhooks']) ? $params['data']['selected_webhooks'] : [];

        if (empty($sourceMarketplace)) {
            return $this->prepareResponse(['success' => true, 'code' => 'invalid_parameters', 'message' => 'Action Aborted. Please pass source value to create Webhooks.']);
        }

        $appCode = $this->di->getAppCode()->get()[$sourceMarketplace];
        $appTag = $this->di->getAppCode()->getAppTag();
        if (empty($appCode) || empty($appTag)) {
            return $this->prepareResponse(['success' => true, 'code' => 'invalid_parameters', 'message' => 'Action Aborted. Please pass AppTag & AppCode values to create Webhooks.']);
        }

        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shop = $userDetails->getShop($shopId, $this->di->getUser()->id);

        #TODO: handle register destination wise webhooks
        if (!empty($destinationWiseWebhooks) && !$destinationId) {
            $createWebhookRequest = $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel")->routeRegisterWebhooks($shop, $sourceMarketplace, $appCode, $destinationId, $destinationWiseWebhooks);
        } else
        /* Register Marketplace  Webhooks with Source */
        {
            $createWebhookRequest = $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel")->routeRegisterWebhooks($shop, $sourceMarketplace, $appCode, $destinationId);
        }

        return $this->prepareResponse($createWebhookRequest);
    }

    public function unregisterAction()
    {
        $contentType = $this->request->getHeader("Content-Type");
        if (strpos($contentType, 'application/json') !== false) {
            $params = $this->request->getJsonRawBody(true);
        } else {
            $params = $this->di->getRequest()->get();
        }

        $shopId = isset($params['source']['shopId']) ? $params['source']['shopId'] : $this->di->getRequester()->getSourceId();
        $sourceMarketplace = isset($params['source']['marketplace']) ? $params['source']['marketplace'] : $this->di->getRequester()->getSourceName();
        if (empty($sourceMarketplace)) {
            return $this->prepareResponse(['success' => true, 'code' => 'invalid_parameters', 'message' => 'Action Aborted. Please pass source value to unregister Webhooks.']);
        }

        $appCode = $this->di->getAppCode()->get()[$sourceMarketplace];
        $appTag = $this->di->getAppCode()->getAppTag();
        if (empty($appCode) || empty($appTag)) {
            return $this->prepareResponse(['success' => true, 'code' => 'invalid_parameters', 'message' => 'Action Aborted. Please pass AppTag & AppCode values to unregister Webhooks.']);
        }

        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shop = $userDetails->getShop($shopId, $this->di->getUser()->id);
        $webhookCodes = [];
        if (isset($params['data']['webhook_codes'])) {
            $webhookCodes = $params['data']['webhook_codes'];
        }

        $createWebhookRequest = $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel")->routeUnregisterWebhooks($shop, $sourceMarketplace, $appCode, false, $webhookCodes);
        return $this->prepareResponse($createWebhookRequest);
    }

    public function oldUnregisterAction()
    {
        $contentType = $this->request->getHeader("Content-Type");
        if (strpos($contentType, 'application/json') !== false) {
            $params = $this->request->getJsonRawBody(true);
        } else {
            $params = $this->di->getRequest()->get();
        }

        $remoteShopId = isset($params['source']['remoteShopId']) ? $params['source']['remoteShopId'] : $this->di->getRequester()->getSourceId();
        $sourceMarketplace = isset($params['source']['marketplace']) ? $params['source']['marketplace'] : $this->di->getRequester()->getSourceName();
        if (empty($sourceMarketplace)) {
            return $this->prepareResponse(['success' => true, 'code' => 'invalid_parameters', 'message' => 'Action Aborted. Please pass source value to unregister Webhooks.']);
        }

        $appCode = $this->di->getAppCode()->get()[$sourceMarketplace];
        $appTag = $this->di->getAppCode()->getAppTag();
        if (empty($appCode) || empty($appTag)) {
            return $this->prepareResponse(['success' => true, 'code' => 'invalid_parameters', 'message' => 'Action Aborted. Please pass AppTag & AppCode values to unregister Webhooks.']);
        }

        $data = [
            'user_id' => (string) $this->di->getUser()->id,
            'remote_shop_id' => $remoteShopId,
            'app_code' => $appCode,
            'marketplace' => $sourceMarketplace,
        ];

        $unregisterWebhookRequest = $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel")->oldUnregisterWebhooks($data);
        return $this->prepareResponse($unregisterWebhookRequest);
    }

    public function subscribeAction()
    {
        $contentType = $this->request->getHeader("Content-Type");
        if (strpos($contentType, 'application/json') === false) {
            return $this->prepareResponse([
                'success' => false,
                'code' => 'method_not_allowed',
                'message' => 'Method not allowed.',
            ]);
        }
        $request = $this->request->getJsonRawBody(true);
        $response = $this->di->getObjectManager()->get(SubscriptionService::class)->subscribe($request);
        return $this->prepareResponse($response);
    }
    public function unsubscribeAction()
    {
        $contentType = $this->request->getHeader("Content-Type");
        if (strpos($contentType, 'application/json') === false) {
            return $this->prepareResponse([
                'success' => false,
                'code' => 'method_not_allowed',
                'message' => 'Method not allowed.',
            ]);
        }
        $request = $this->request->getJsonRawBody(true);
        $response = $this->di->getObjectManager()->get(SubscriptionService::class)->unsubscribe($request);
        return $this->prepareResponse($response);
    }

    public function getAction()
    {

    }

    public function updateAction()
    {

    }
}