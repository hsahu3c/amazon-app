<?php
namespace App\Connector\Components;

use Phalcon\Logger\Logger;
class Webhook extends \App\Core\Components\Base
{
    public static $appList = null;

    public static $appList1 = null;

    public function initialise(): void
    {
        self::$appList = [
            [
                'app_name' => 'shopify',
                'api_key' => $this->di->getConfig()->security->shopify->auth_key,
                'api_secret' => $this->di->getConfig()->security->shopify->secret_key
            ]
        ];

        self::$appList1 = [
            [
                'app_name' => 'bigcommerce',
                'api_key' => $this->di->getConfig()->security->bigcommerce->auth_key,
                'api_secret' => $this->di->getConfig()->security->bigcommerce->secret_key
            ]
        ];
    }


    public function registerWebhook($shop, $appCode, $webhookId)
    {

        $userId = $this->di->getUser()->id;
        $awsConfig = include(BP . DS . 'app' . DS . 'etc' . DS . 'aws.php');

        $webhook = $this->di->getConfig()->webhook->get($shop['marketplace'])->get($webhookId) ? $this->di->getConfig()->webhook->get($shop['marketplace'])->get($webhookId)->toArray() : [];

        $appWebhook = $this->di->getConfig()->webhook->get($appCode)->get($webhookId) ? $this->di->getConfig()->webhook->get($appCode)->get($webhookId)->toArray() : [];
        $defaultWebhook = $this->di->getConfig()->webhook->get('default')->get($webhookId) ? $this->di->getConfig()->webhook->get('default')->get($webhookId)->toArray() : [];

        $webhook = array_merge($defaultWebhook, $webhook);
        $webhook = array_merge($webhook, $appWebhook);

        $webhookData = [
            'type' => 'sqs',
            'queue_config' => [
                'region' => $awsConfig['region'],
                'key' => $awsConfig['credentials']['key'],
                'secret' => $awsConfig['credentials']['secret']
            ],
            'webhook_code' => $webhook['code'],
            'queue_config_id' => $webhook['queue_config_id'] ?? 'sqs-' . $this->di->getConfig()->app_code, // new index
            'format' => 'json',
            'queue_name' => isset($webhook['queue_name']) ? $this->di->getConfig()->app_code . '_' . $webhook['queue_name'] : $this->di->getConfig()->app_code . "_" . $webhookId,
            'queue_data' => [
                'type' => 'full_class',
                'class_name' => $webhook['class_name'] ?? '\App\Connector\Models\SourceModel',
                'method' => $webhook['method'] ?? 'triggerWebhooks',
                'user_id' => $userId,
                'action' => $webhook['action'],
                'queue_name' => isset($webhook['queue_name']) ? $this->di->getConfig()->app_code . '_' . $webhook['queue_name'] : $this->di->getConfig()->app_code . "_" . $webhookId,
                'app_code' => $appCode,
                'marketplace' => $shop['marketplace'] ?? ''
            ]
        ];
        $model = $this->di->getObjectManager()->get($this->di->getConfig()->connectors->get($shop['marketplace'])->get('source_model'));

        if (method_exists($model, 'prepareWebhookData')) {
            $model->prepareWebhookData($shop, $appCode, $webhook, $webhookData);
        }

        $responseWbhook = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($shop['marketplace'], 'true', $appCode)
            ->call('/webhook/register', [], ['shop_id' => $shop['remote_shop_id'], 'data' => $webhookData, 'webhook' => $webhook], 'POST');

        return $responseWbhook;
    }


    public function consumerCreateWebhook($data)
    {
        return $this->createWebhooks($data['data']['shop'], $data['data']['user_id']);
    }

    public function createWebhooks($shop, $userId = false)
    {
        try {
            if (!$userId) {
                $userId = $this->di->getUser()->id;
            }

            self::initialise();
            $this->deleteExistingWebhook($shop, $userId);
            $baseUrl = $this->di->getConfig()->backend_base_url;
            $secureWebUrl = $baseUrl . "shopify/webhook/";

            $urls = [
                $secureWebUrl . "productCreate",
                $secureWebUrl . "productUpdate",
                $secureWebUrl . "productDelete",
                $secureWebUrl . "createOrder",
                $secureWebUrl . "createShipment",
                $secureWebUrl . "createShipment",
                $secureWebUrl . "cancelled",
                $secureWebUrl . "uninstall"
            ];

            $topics = [
                "products/create",
                "products/update",
                "products/delete",
                "orders/create",
                "orders/partially_fulfilled",
                "orders/fulfilled",
                "orders/cancelled",
                "app/uninstalled"
            ];
            $created = [];
            foreach (self::$appList as $key => $appKeys) {
                $token = $this->getAppToken($shop, $userId);
                $shopifyClient = $this->di->getObjectManager()->get('App\Shopify\Components\ShopifyClientHelper', [
                    ['type' => 'parameter', 'value' => $shop],
                    ['type' => 'parameter', 'value' => $token],
                    ['type' => 'parameter', 'value' => $appKeys['api_key']],
                    ['type' => 'parameter', 'value' => $appKeys['api_secret']]
                ]);
                foreach ($topics as $key => $topic) {
                    $charge = [
                        'webhook' =>
                            [
                                'topic' => $topic,
                                'address' => $urls[$key]
                            ]
                    ];
                    $created[] = $shopifyClient->call('POST', '/admin/webhooks.json', $charge);
                }
            }

            return true;
        } catch (\Exception $e) {
            $this->di->getLog()->logContent('Errors : ' . $e->getMessage(), Logger::CRITICAL, 'testing_webhook_create.log');
            return false;
        }
    }

    public function getAppToken($shop = '', $userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        // Will have to remove this below 3 line of codes, they are for test only
        $allShopDetails = \App\Shopify\Models\Shop\Details::find();
        $allShopDetails->toArray();

        $token = \App\Shopify\Models\Shop\Details::findFirst("user_id = '{$userId}' AND shop_url = '{$shop}'");

        if ($token) {
            $token = $token->toArray();
            return $token['token'];
        }

        $this->di->getLog()->logContent('Did not got token', Logger::CRITICAL, 'testing_webhook_create.log');
        return false;
    }

    public function deleteExistingWebhook($shop, $userId = false): void
    {
        $this->initialise();
        $token = $this->getAppToken($shop, $userId);
        foreach (self::$appList as $appKeys) {
            $shopifyClient = $this->di->getObjectManager()->get('App\Shopify\Components\ShopifyClientHelper', [
                ['type' => 'parameter', 'value' => $shop],
                ['type' => 'parameter', 'value' => $token],
                ['type' => 'parameter', 'value' => $appKeys['api_key']],
                ['type' => 'parameter', 'value' => $appKeys['api_secret']]
            ]);
            $response = $shopifyClient->call('GET', '/admin/webhooks.json');
            $delresponse = [];
            foreach ($response as $value) {
                if (!isset($value["errors"])) {
                    $delresponse[] = $shopifyClient->call('DELETE', '/admin/webhooks/' . $value["id"] . '.json');
                }
            }
        }
    }

    public function getExistingWebhooks($shop, $userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $token = $this->getAppToken($shop, $userId);
        $this->initialise();
        $allWebhooks = [];
        foreach (self::$appList as $appKeys) {
            $shopifyClient = $this->di->getObjectManager()->get('App\Shopify\Components\ShopifyClientHelper', [
                ['type' => 'parameter', 'value' => $shop],
                ['type' => 'parameter', 'value' => $token],
                ['type' => 'parameter', 'value' => $appKeys['api_key']],
                ['type' => 'parameter', 'value' => $appKeys['api_secret']]
            ]);
            $allWebhooks[] = $shopifyClient->call('GET', '/admin/webhooks.json');
        }

        if (isset($allWebhooks[0]['errors'])) {
            return false;
        }

        return $allWebhooks;
    }

    /*********Function to create BigCommerce webhooks********/
    public function createBigcomWebhooks($shop, $userId = false)
    {
        try {
            if (!$userId)
                $userId = $this->di->getUser()->id;

            self::initialise();
            $this->deleteExistingBigcomWebhook($shop, $userId);
            $baseUrl = $this->di->getConfig()->backend_base_url; //http://connector.com/phalcon/engine/public/
            $secureWebUrl = $baseUrl . "bigcommerce/webhook/";

            $urls = [
                $secureWebUrl . "productcreate",
                $secureWebUrl . "productcreate",
                $secureWebUrl . "productupdate",
                $secureWebUrl . "productupdate",
                $secureWebUrl . "productdelete",
                $secureWebUrl . "productdelete",
                $secureWebUrl . "inventoryupdate",
                $secureWebUrl . "inventoryupdate",
                $secureWebUrl . "inventoryupdate",
                $secureWebUrl . "inventoryupdate",
                $secureWebUrl . "createshipment",
                $secureWebUrl . "uninstall"
            ];

            $topics = [
                "store/product/created",
                "store/sku/created",
                "store/product/updated",
                "store/sku/updated",
                "store/product/deleted",
                "store/sku/deleted",
                "store/product/inventory/updated",
                "store/sku/inventory/updated",
                "store/product/inventory/order/updated",
                "store/sku/inventory/order/updated",
                "store/shipment/created",
                "store/app/uninstalled",
            ];
            $created = [];
            foreach (self::$appList1 as $key => $appKeys) {
                list($token, $storehash) = $this->getBigcomAppToken($shop, $userId);

                $bigcomClient = $this->di->getObjectManager()->get('App\bigcommerce\Components\BigcommerceClientHelper', [
                    ['type' => 'parameter', 'value' => $appKeys['api_key']],
                    ['type' => 'parameter', 'value' => $token],
                    ['type' => 'parameter', 'value' => $storehash]
                ]);
                foreach ($urls as $key => $url) {
                    $charge = ['scope' => $topics[$key], 'destination' => $url];
                    $response = $bigcomClient->call1('POST', 'hooks', $charge);
                }
            }

            return true;
        } catch (\Exception $e) {
            $this->di->getLog()->logContent('Errors : ' . $e->getMessage(), Logger::CRITICAL, 'testing_webhook_create.log');
            return false;
        }
    }

    /*********** fetch BigCommerce API Credentials *****************/
    public function getBigcomAppToken($shop = '', $userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $token = \App\BigCommerce\Models\Shop\Details::findFirst("user_id = '{$userId}' AND shop_url = '{$shop}'");
        if ($token) {
            $token = $token->toArray();
            return [$token['token'], $token['store_hash']];
        }

        $this->di->getLog()->logContent('Did not got token', Logger::CRITICAL, 'testing_webhook_create.log');
        return false;
    }

    /*********** Delet Existing BigCommerce Webhooks *****************/
    public function deleteExistingBigcomWebhook($shop, $userId = false): void
    {
        $this->initialise();
        list($token, $storehash) = $this->getBigcomAppToken($shop, $userId);
        foreach (self::$appList1 as $appKeys) {
            $bigcomClient = $this->di->getObjectManager()->get('App\bigcommerce\Components\BigcommerceClientHelper', [
                ['type' => 'parameter', 'value' => $appKeys['api_key']],
                ['type' => 'parameter', 'value' => $token],
                ['type' => 'parameter', 'value' => $storehash]
            ]);
            $response = $bigcomClient->call1('GET', 'hooks');
            $delresponse = [];
            $delresponse[] = $bigcomClient->call1('DELETE', 'hooks/14576466');
            foreach ($response as $value) {
                if (!isset($value["errors"])) {
                    $delresponse[] = $bigcomClient->call1('DELETE', 'hooks/' . $value['id']);
                }
            }
        }
    }
}
