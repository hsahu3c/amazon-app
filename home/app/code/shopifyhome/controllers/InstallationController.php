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

namespace App\Shopifyhome\Controllers;

use App\Core\Controllers\BaseController;
class InstallationController extends BaseController
{
    /**
     * Handle the installation action.
     */
    public function installAction()
    {
        // Get the Content-Type header.
        $contentType = $this->request->getHeader('Content-Type');

        // Check if the content is JSON.
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        // Create a MongoDB instance.
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        // Get the MongoDB collection.
        $collection = $mongo->getCollectionForTable("fromAmazonInstallation");

        // Check if required data is present in the request.
        if (!isset($rawBody['tokenData']['shop_id']) || !isset($rawBody['tokenData']['marketplace']) || !$rawBody['shopUrl']) {
            return $this->prepareResponse(["success" => false, "message" => "Missing information"]);
        }

        // Insert data into the MongoDB collection.
        $collection->insertOne([
            "shop_id" => $rawBody['tokenData']['shop_id'],
            "marketplace" => $rawBody['tokenData']['marketplace'],
            "shop_url" => $rawBody['shopUrl'],
            "fullRemoteData" => $rawBody['fullremoteData']
        ]);

        // Prepare and return the response.
        return $this->prepareResponse(['success' => true, 'url' => $this->di->getConfig()->get("frontend") . 'connector/request/shopifyCurrentRoute?shop=' . $rawBody['shopUrl'] . '&current_route=dashboard&sAppId=1']);
    }

    /**
     * Check for a shop action.
     */
    public function checkForShopAction()
    {
        // Get the Content-Type header.
        $contentType = $this->request->getHeader('Content-Type');

        // Check if the content is JSON.
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        // Create a MongoDB instance.
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        // Get the MongoDB collection.
        $collection = $mongo->getCollectionForTable("user_details");



        // Check if shop data was found.
        if (isset($rawBody['remote_shop_id'])) {
            // Search for a shop by remote_shop_id in MongoDB.
            $data = $collection->find(["shops.remote_shop_id" => (string) $rawBody['remote_shop_id']])->toArray();
            if(!empty($data)){
                return $this->prepareResponse([
                    'success' => true,
                    "shops" => $data,
                ]);
            }
            return $this->prepareResponse([
                'success' => false,
                "message" => "no shop found"
            ]);

        }
        return $this->prepareResponse([
            'success' => false,
            "message" => "please send remote_shop_id "
        ]);
    }
}