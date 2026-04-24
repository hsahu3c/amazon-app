<?php

namespace App\Amazon\Components\Order;

use DateTime;
use App\Amazon\Components\Common\Helper;
use Exception;
use App\Core\Components\Base as Base;

class Hook extends Base
{
    public const AMAZON_APP_ID = 5045527;

    public static function getCarrierCodes()
    {
        $codes = [
            "DHL eCommerce",
            "Hermes",
            "WanB Express",
            "JCEX",
            "USPS",
            "UPS",
            "UPSMI",
            "FedEx",
            "DHL",
            "Fastway",
            "GLS",
            "GO!",
            "Hermes Logistik Gruppe",
            "Royal Mail",
            "Self Delivery",
            "Parcelforce",
            "City Link",
            "TNT",
            "Target",
            "SagawaExpress",
            "NipponExpress",
            "YamatoTransport",
            "DHL Global Mail",
            "UPS Mail Innovations",
            "FedEx SmartPost",
            "OSM",
            "OnTrac",
            "Streamlite",
            "Newgistics",
            "Canada Post",
            "Blue Package",
            "Chronopost",
            "Deutsche Post",
            "DPD",
            "La Poste",
            "Parcelnet",
            "Poste Italiane",
            "SDA",
            "Smartmail",
            "FEDEX_JP",
            "JP_EXPRESS",
            "NITTSU",
            "SAGAWA",
            "YAMATO",
            "BlueDart",
            "AFL/Fedex",
            "Aramex",
            "India Post",
            "Professional",
            "DTDC",
            "Overnite Express",
            "First Flight",
            "Delhivery",
            "Lasership",
            "Yodel",
            "Other",
            "SF Express	",
            "Amazon Shipping",
            "Seur",
            "Correos",
            "MRW",
            "Endopack",
            "Chrono Express",
            "Nacex",
            "Otro",
            "Correios",
            "Australia Post-Consignment",
            "Australia Post-ArticleId",
            "China Post",
            "4PX",
            "Asendia",
            "Couriers Please",
            "Sendle",
            "SF express",
            "SFC",
            "Singapore Post",
            "Startrack-Consignment",
            "Startrack-ArticleID",
            "Yanwen",
            "YDH",
            "Yun Express",
            "Australia Post",
            "AT POST",
            "BRT"
        ];

        return $codes;
    }

    public function validateDate($date, $format = 'Y-m-d H:i:s P')
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) == $date;
    }

    public function generateDate($date, $outputFormat = 'c', $inputFormat = 'Y-m-d H:i:s P')
    {
        $d = DateTime::createFromFormat($inputFormat, $date);
        if ($d) {
            return $d->format($outputFormat);
        }

        return false;
    }

    public function createQueueToCreateOrder($webhook)
    {
        $date = date('d-m-Y');
        $logFile = "amazon/order_create_webhook/{$date}.log";
        try {
            if (isset($webhook['data']['app_id']) && $webhook['data']['app_id'] == self::AMAZON_APP_ID) {
                $this->di->getLog()->logContent('order create webhook data : ' . json_encode($webhook), 'info', $logFile);

                $amazonOrderId = '';
                $noteAttributes = $webhook['data']['note_attributes'];
                foreach ($noteAttributes as $value) {
                    // if($value['name']=='Source Order ID' || $value['name']=='Amazon Order ID') {
                    $attr_name = strtolower((string) $value['name']);
                    if ($value['name'] == 'Source Order ID' || $value['name'] == 'Amazon Order ID' || $attr_name == 'source order id' || $attr_name == 'amazon order id') {
                        $amazonOrderId = $value['value'];
                        break;
                    }
                }

                if ($amazonOrderId) {
                    $targetOrderId = $webhook['data']['id'];

                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $orderCollection = $mongo->getCollectionForTable(Helper::ORDER_CONTAINER);
                    $orderComponent = $this->di->getObjectManager()->get(Order::class);

                    $order = $orderCollection->findOne(['source_order_id' => (string)$amazonOrderId], ['projection' => ['target_order_id' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']]);

                    if ($order && (!isset($order['target_order_id']) || empty($order['target_order_id']))) {
                        $query = ['_id' => $order['_id']];

                        $updateData = ['$set' => [
                            // 'target_status'         => 'paid',
                            'target_status' => 'pending',
                            'target_order_id' => (string)$targetOrderId,
                            'shopify_order_name' => (string)$webhook['data']['name'],
                            'imported_at' => $webhook['data']['created_at'],
                            'target_error_message' => '',
                            'target_errors' => '',
                            'target_order_data' => $webhook['data']
                        ]];

                        $orderCollection->updateOne($query, $updateData);

                        $this->di->getLog()->logContent('shopify order details updated in the app', 'info', $logFile);
                        return json_encode(['success' => true, 'message' => 'shopify order details updated in the app.']);
                    }
                    // $this->di->getLog()->logContent('order details already updated', 'info', $logFile);
                    // return json_encode(['success' => true, 'message' => 'order details already updated.']);
                    if ($order['target_order_id'] != $targetOrderId) {
                        // this order is created again on shopify, so delete this targetOrderId from shopify in order to remove duplicate orders.

                        // $target = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Shop\Shop')->getUserMarkeplace();
                        $target = 'shopify';

                        $user_details = [];

                        if (is_object($this->di->getUser()) && property_exists($this->di->getUser(), 'username')) {
                            $userName = $this->di->getUser()->username;

                            if (!in_array($userName, ['admin', 'app'])) {
                                $userShops = $this->di->getUser()->shops;

                                foreach ($userShops as $shop) {
                                    if ($shop['marketplace'] === $target) {
                                        $user_details = $shop;
                                    }
                                }
                            }
                        } elseif (isset($webhook['user_id'])) {
                            $userId = $webhook['user_id'];

                            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                            $user_details = $user_details->getDataByUserID($userId, $target);
                        }

                        if ($user_details) {
                            // var_dump($targetOrderId);
                            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                                ->init($target, 'true')
                                ->call('/order', [], ['id' => $targetOrderId, 'shop_id' => $user_details['remote_shop_id']], 'DELETE');

                            // var_dump($remoteResponse);die;
                            $this->di->getLog()->logContent("duplicate order {$targetOrderId} deleted", 'info', $logFile);
                            return json_encode(['success' => true, 'message' => "duplicate order {$targetOrderId} deleted"]);
                        }
                        $this->di->getLog()->logContent("duplicate order {$targetOrderId} not deleted, due to user_details not found", 'info', $logFile);
                        return json_encode(['success' => true, 'message' => "duplicate order {$targetOrderId} not deleted, due to user_details not found"]);
                    }
                    $this->di->getLog()->logContent('order details already updated', 'info', $logFile);
                    return json_encode(['success' => true, 'message' => 'order details already updated.']);
                }
                return json_encode(['success' => false, 'message' => 'Amazon order id does not exists in the order.']);
            }
            return json_encode(['success' => false, 'message' => 'Order doesn\'t belongs to amazon app.']);
        } catch (Exception $e) {
            return json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function manualOrderShipment($params = []): void
    {
        $userId = '60e5da314d06097a2f1385d5';
        $orders = [""];

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $orderCollection = $mongo->getCollectionForTable(Helper::ORDER_CONTAINER);
        $orderComponent = $this->di->getObjectManager()->get(Order::class);
        $aggregation = [];
        $aggregation[] = ['$match' => ['source_order_id' => ['$in' => $orders]]];
        $options = ['typeMap'   => ['root' => 'array', 'document' => 'array']];
        $result = $orderCollection->aggregate($aggregation, $options)->toArray();
        $sentToDynamo = [];

        foreach ($result as $value) {
            $sentToDynamo['home_shop_id'] = $value['shop_id'];
            $sentToDynamo['shopify_order_id'] = $value['target_order_id'];
            $sentToDynamo['amazon_order_id'] = $value['source_order_id'];
            $sentToDynamo['user_id'] = $value['user_id'];
            $sentToDynamo['remote_shop_id'] = '219';
            // $order = $orderCollection->findOne(['target_order_id' => (string)$orders[0]], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
            $fulfillmentResponses = $orderComponent->init(['user_id' => $userId])->prepareShipment($value['fulfillments'], $value);
            // $this->prepareFeed($fulfillmentResponses, $value['source_order_data']['data']['MarketplaceId'], $sentToDynamo, false);
        }

        // $res = $dynamoObj->save($sentToDynamo);
        $this->di->getLog()->logContent('data: ' . json_encode($result), 'info', 'order_test.log');
        // print_r($res);die;
    }

    /*public function createQueueToCreateOrder($webhook)
    {
        $date = date('d-m-Y');
        $logFile = "amazon/order_create_webhook/{$date}.log";
        try {
            if(isset($webhook['data']['app_id']) && $webhook['data']['app_id']==self::AMAZON_APP_ID)
            {
                $this->di->getLog()->logContent('order create webhook data : '.json_encode($webhook), 'info', $logFile);

                $amazonOrderId = '';
                $noteAttributes = $webhook['data']['note_attributes'];
                foreach ($noteAttributes as $key => $value) {
                    // if($value['name']=='Source Order ID' || $value['name']=='Amazon Order ID') {
                    $attr_name = strtolower($value['name']);
                    if($value['name']=='Source Order ID' || $value['name']=='Amazon Order ID' || $attr_name=='source order id' || $attr_name=='amazon order id') {
                        $amazonOrderId = $value['value'];
                        break;
                    }
                }

                if($amazonOrderId)
                {
                    $targetOrderId = $webhook['data']['id'];

                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $orderCollection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::ORDER_CONTAINER);
                    $orderComponent = $this->di->getObjectManager()->get('\App\Amazon\Components\Order\Order');

                    $order = $orderCollection->findOne(['source_order_id' => (string)$amazonOrderId], ['projection' => ['target_order_id' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']]);

                    if($order && (!isset($order['target_order_id']) || empty($order['target_order_id'])))
                    {
                        $query = ['_id'  => $order['_id']];

                        $updateData = ['$set' => [
                            // 'target_status'         => 'paid',
                            'target_status'         => 'pending',
                            'target_order_id'       => (string)$targetOrderId,
                            'shopify_order_name'    => (string)$webhook['data']['name'],
                            'imported_at'           => $webhook['data']['created_at'],
                            'target_error_message'  => '', 
                            'target_errors'         => '',
                            'target_order_data'     => $webhook['data']
                        ]];

                        $orderCollection->updateOne($query, $updateData);

                        $this->di->getLog()->logContent('shopify order details updated in the app', 'info', $logFile);
                        return json_encode(['success' => true, 'message' => 'shopify order details updated in the app.']);
                    }
                    else
                    {
                        $this->di->getLog()->logContent('order details already updated', 'info', $logFile);
                        return json_encode(['success' => true, 'message' => 'order details already updated.']);
                    }
                }
                else
                {
                    return json_encode(['success' => false, 'message' => 'Amazon order id does not exists in the order.']);
                }
            }
            else
            {
                return json_encode(['success' => false, 'message' => 'Order doesn\'t belongs to amazon app.']);
            }
        }
        catch (\Exception $e) {
            return json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }*/
}
