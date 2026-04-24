<?php
/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */
namespace App\Shopifyhome\Service;
use App\Connector\Contracts\Sales\OrderInterface;
use DateTime;
use DateTimeZone;
use App\Connector\Contracts\Sales\Order\CancelInterface;
/**
 * Interface OrderInterface
 * @services
 */
class OrderCancel implements CancelInterface
{
    public const  SCHEMA_VERSION='2.0';

    public $settings_enabled = false;

    public $settings_data = null;

    public $attempt = 0;

    /**
    * to perform cancellation over shopify
    */
    public function cancel(array $order): array
    {
        if(empty($order)) {
            return [
                'success' => false,
                'message' => 'No data is provided!'
            ];
        }

        if(!isset($order['marketplace_reference_id']) || !isset($order['marketplace_shop_id']))  {
            return [
                'success' => false,
                'message' => 'Shop Id/Reference Order Id Not found!'
            ];
        }

        if(empty($order['cancel_reason'])) {
            return [
                'success' => false,
                'message' => 'Cancel reason not matched or unavailable'
            ];
        }

        //first check settings 
        if(isset($order['process']) && $order['process'] == "manual") {
            $refundData["reason"] = $order['cancel_reason'];
        } else {
            if(isset($order['cancel_reason'])) {
                $refundData["reason"] = $order['cancel_reason'];
            } else {
                return [
                    'success' => false,
                    'message' => 'Cancel reason not found'
                ];
            }

            //$refundData["reason"] = $this->getCancelReason($order['cancel_reason'], $order['source_marketplace']);
        }
        $callType = '';
        if (isset($order['settings_data']['restock_inventory_on_cancellation']['value']) && $order['settings_data']['restock_inventory_on_cancellation']['value']) {
            $refundData['restock'] = true;
            $callType = 'QL';
        }

        isset($order['currency']) && $refundData["currency"] = $order['currency'];
        $user = $this->shopifyUserDetails($order['marketplace_shop_id']);
        if(empty($user)) {
            return [
                'success' => false,
                'message' => 'User shop not found'
            ]; 
        }

        //making remote call to cancel order
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
        ->init($order['marketplace'], 'true')
        ->call('order/cancel', [], ['shop_id' => $user['remote_shop_id'], 'order_id'=> $order['marketplace_reference_id'], 'data' => $refundData, 'call_type' => $callType], 'POST');

        //$this->saveInLog($remoteResponse, 'order/order-cancel/' .$this->di->getUser()->id.'/'.date('d-m-y') .'/automatic-cancel/remote-response.log', 'Shopify remote response');
        if(isset($remoteResponse['success']) && $remoteResponse['success']) {
            if ($callType == 'QL' && isset($remoteResponse['data']['orderCancel']['job'])) {
                $jobId = $remoteResponse['data']['orderCancel']['job']['id'] ?? "";
                $this->attempt = 0;
                $jobOrdeData = $this->getJobData($jobId, $user['remote_shop_id'], $order['marketplace_reference_id'], $order['marketplace']);
                if ($jobOrdeData['success'] == false) {
                    $orders = [];
                    $getData = [
                        'shop_id'=> $order['marketplace_shop_id'],
                        'id'=> $order['marketplace_reference_id'],
                        'app_code' => $this->di->getAppCode()->getAppTag()
                    ];
                    $orderService = $this->di->getObjectManager()->get(OrderInterface::class, [], 'shopify');
                    $getOrderData = $orderService->get($getData);
                } else {
                    $getOrderData['data']['data'] = $jobOrdeData['data'];
                    $getOrderData['success'] = true;
                }
            } else {
                $orders = [];
                $getData = [
                    'shop_id'=> $order['marketplace_shop_id'],
                    'id'=> $order['marketplace_reference_id'],
                    'app_code' => $this->di->getAppCode()->getAppTag()
                ];
                $orderService = $this->di->getObjectManager()->get(OrderInterface::class, [], 'shopify');
                $getOrderData = $orderService->get($getData);
            }
            if($getOrderData['success'] && isset($getOrderData['data']['data'])) {
                $cancelOrder = $getOrderData['data']['data'];
                $cancelOrder['marketplace'] = $order['marketplace'];
                $cancelOrder['shop_id'] = $order['marketplace_shop_id'];
                $orders = $cancelOrder;
            } else {
                if ($callType !== 'QL') {
                    $remoteResponse['data']['marketplace'] = $order['marketplace'];
                    $remoteResponse['data']['shop_id'] = $order['marketplace_shop_id'];
                    if(!isset($remoteResponse['data']['order']['cancel_reason'])) {
                       $remoteResponse['data']['order']['cancel_reason'] =  $refundData["reason"];
                    }
                    $orders = $remoteResponse['data'];
                }
            }

            $responseData['success'] = true;
            $responseData['orders']  = $orders;
            return $responseData;
          }
        $err="";
        if(isset($remoteResponse['msg']) && !is_array($remoteResponse['msg'])) {
            $err = $remoteResponse['msg'];
        } elseif(isset($remoteResponse['message'])) {
            $err = $remoteResponse['message'];
        } else {
            if (empty($remoteResponse)) {
                $err = 'No response received from remote server!';
            } else {
                if(is_array($remoteResponse['msg'])) {
                    if(array_key_exists("errors", $remoteResponse['msg'])) {
                        $err = $remoteResponse['msg']['errors'];
                      } elseif( array_key_exists("error", $remoteResponse['msg'])) {
                       $err = $remoteResponse['msg']['error'];
                      }
                } else {
                    $err =  $remoteResponse['msg'];
                }
            }
        }

        return [
            'success'=> false,
            'message'=> 'Order Cancellation failed. Reason :'.$err
        ];
    }

    public function checkSettings($data)
    {
        $settingsResponse = $this->getSettings($data);
        if(!$settingsResponse['success']) {
            return $settingsResponse;
        }

        if(!$this->isSettingEnabled()) {
            return [
                'success' => false,
                'message' => "'Enable Order Syncing from App' is disabled"
            ];
        }

        if(!$this->isCancellationEnabled()) {
            return [
                'success' => false,
                'message' => "Cancellation Not Allowed. 'Sync Order Cancellation status' setting disabled."
            ];
        }

        return [
            'success'=> true,
            'message' => 'Settings found'
        ];
    }

    public function shopifyUserDetails($shop_id)
    {
        $userShops = $this->di->getUser()->shops;
        if(empty($userShops)) {
            return [];
        }
        foreach($userShops as $shop) {
            if($shop['_id'] == $shop_id) {
                return $shop;
            }
        }

        return [];
    }

     /**
     * to map reasons according to marketplace
     *
     * @param [type] $reason
     * @param [type] $marketplace
     */
    public function getCancelReason($reason, $marketplace):array
    {
        $mappedReason  = [
            'amazon' => [
                "NoInventory"=> 'inventory',
                "CustomerReturn"=> 'customer',
                "GeneralAdjustment"=> 'other',
                "CouldNotShip" => 'inventory',
                "DifferentItem"=> 'fraud',
                "Abandoned"=> 'declined',
                "CustomerCancel"=> 'customer',
                "PriceError" => 'other',
                "ProductOutofStock"=> 'inventory',
                "CustomerAddressIncorrect"=> 'customer',
                "Exchange"=> 'declined',
                "CarrierCreditDecision" => 'other',
                "RiskAssessmentInformationNotValid"=> 'fraud',
                "CarrierCoverageFailure"=> 'other',
                "TransactionRecord"=> 'fraud',
                "Undeliverable" => 'customer',
                "RefusedDelivery"=> 'declined',
                "Other" => 'other',
                "BuyerCanceled" => 'customer'
            ],
            'arise' => [
                "seller" => "other",
                "18001" => "inventory",
                "18003" => "customer"
            ],
            'tiktok' => [
                "Buyer requested cancellation" => "Buyer requested cancellation",
                "Out of stock" => "Out of stock",
                "Pricing error" => "Pricing error",
                "Unable to deliver to buyer address" => "Unable to deliver to buyer address"
            ]
        ];
        if (isset($mappedReason[$marketplace],$mappedReason[$marketplace][$reason]))
        {
            return [
                'success' => true,
                'data' => $mappedReason[$marketplace][$reason]
            ];
        } /*else{
            return [
                'success' => true,
                'data' => 'other'
            ];
        }*/
        return [
            'success' => false,
            'message' => 'Unable to map reason "'.$reason.'" on Shopify'
        ];
    }

    /**
     * Data forming if refund allowed, this will not be used as refund will not be proceeded in cancellation
     *
     * @param [type] $data
     * @return boolean
     */
    public function getRefundData($data)
    {
        if($this->isRefundEnabled()) {
                $refundData = [
                    'amount'=>$data['cancellation_amount'],
                    'currency'=> $data['currency']
                ];
            return $refundData;
        }
        return [];
    }

    /**
     * Function to prepare data in cif format
     */
    public function prepareForDb(array $order): array
    {
        if(isset($order['error']))
        {
            return [
                'success' => false,
                'message' => $order['error']
            ];
        }

        if (empty($order['shop_id'])) {
            return [
                'success' => false,
                'message' => 'shop_id is not available in shopify cancel order'
            ];
        }

        $orderData = [];
        $orderData['marketplace_shop_id'] = (string)$order['shop_id'];
        $orderData['schema_version'] = self::SCHEMA_VERSION;
        $orderData['marketplace'] = $order['marketplace'] ?? 'shopify';
        $orderData['marketplace_warehouse_id'] = null;
        $orderData['user_id'] = $this->di->getUser()->id;
        if (isset($order['order'])) {
            $orderInfo = $order['order'];
        } else {
            $orderInfo = $order;
        }

        isset($orderInfo['customer']) && $orderData['customer'] = $this->getCustomerInfo($orderInfo['customer']);
        isset($orderInfo['id']) &&  $orderData['marketplace_reference_id'] = (string)$orderInfo['id'];
        isset($orderInfo['currency']) &&  $orderData['marketplace_currency'] = $orderInfo['currency'];
        isset($orderInfo['cancel_reason']) &&  $orderData['cancel_reason'] = $orderInfo['cancel_reason'];
        if(isset($orderInfo['cancelled_at']) && !is_null($orderInfo['cancelled_at']))  {
            $orderData['marketplace_status'] = 'Canceled';
        }

        $orderItems = [];
        $shopifyItems = [];
        if(isset($orderInfo['line_items'])) {
            $shopifyItems = $orderInfo['line_items'];
        }elseif(isset($orderInfo['items'])) {
            $shopifyItems = $orderInfo['items'];
        }

        if (!empty($shopifyItems)) {
            foreach ($shopifyItems as $items) {
                $orderItem = $this->getItems($items, $orderData);
                if(!empty($orderItem)){
                    array_push($orderItems, $orderItem);
                }
            }
        }

        if($orderItems === []){
            return [
                'success' => false,
                'message' => 'No Items found for cancellation'
            ];
        }

        $orderData['items'] = $orderItems;

        //discount - global
        if (!empty($orderInfo['current_total_discounts'])) {
            $orderData['discount'] = [
                'price' => (float)$orderInfo['current_total_discounts']
            ];
        }

        //tax - global
        if (!empty($orderInfo['current_total_tax'])) {
            $orderData['tax'] = [
                'price' => (float)$orderInfo['current_total_tax']
            ];
        }

        if (!empty($orderInfo['tax_lines'])) {
            $orderData['taxes']=[];
            foreach($orderInfo['tax_lines'] as $line) {
                array_push($orderData['taxes'], [
                    'code'=> $line['title'],
                    'price'=> (float)$line['price'],
                    'rate' => (float)$line['rate']
                ]);
            }
        }

        //sub_total price - global
        if (!empty((float)$orderInfo['current_subtotal_price']) && !empty((float)$orderInfo['current_subtotal_price'])) {
            $orderData['sub_total'] = (float)$orderInfo['current_subtotal_price'];
        } elseif (!empty($orderInfo['subtotal_price']) && !empty((float)$orderInfo['subtotal_price'])) {
            $orderData['sub_total'] = (float)$orderInfo['subtotal_price'];
        }

        //total price - global
        if (!empty($orderInfo['current_total_price']) && !empty((float)$orderInfo['current_total_price'])) {
            $orderData['total'] = (float)$orderInfo['current_total_price'];
        }elseif (!empty($orderInfo['total_price']) && !empty((float)$orderInfo['total_price'])) {
            $orderData['total'] = (float)$orderInfo['total_price'];
        }

        isset($orderInfo['created_at']) &&  $orderData['source_created_at'] = $orderInfo['created_at'];
        isset($orderInfo['updated_at']) &&  $orderData['source_updated_at'] = $orderInfo['updated_at'];
        return [
            'success' => true,
            'data' => $orderData
        ];
    }

    public function create(array $order): array
    {

    }

    /**
     * to mould customer information
     */
    public function getCustomerInfo(array $customer): array
    {
        $customerInfo = [];
        isset($customer['id']) && $customerInfo['id'] = $customer['id'];
        isset($customer['email']) &&  $customerInfo['email'] =  $customer['email'];
        $first_name = $customer['first_name'] ?? "";
        $last_name = $customer['last_name'] ?? "";
        $customerInfo['name'] = $first_name." ".$last_name;
        return $customerInfo;
    }

    /**
     * to mould order items
     */
    public function getItems(array $item = [], array $orderData = []): array
    {
        $cancelledOrderItem = [];
        isset($item['id']) && $cancelledOrderItem['marketplace_item_id'] = (string)$item['id'];
        $cancelledOrderItem['sku'] =  $item['sku'] ?? null;
        isset($item['title']) && $cancelledOrderItem['title'] = $item['title'];
        isset($item['quantity']) && $cancelledOrderItem['qty'] = (int)$item['quantity'];
        if (!empty($item['price'])) {
            $cancelledOrderItem['price'] = (float)$item['price'];
        }

        $tax = 0;
        $taxes = [];

        if (!empty($item['tax_lines'])) {
            foreach($item['tax_lines'] as $line) {
                if(!empty($line['price'])) {
                    $taxes[] =['code'=> !empty($line['title']) ? $line['title']:"", 'price'=> (float)$line['price'], 'rate'=> (float)$line['rate']];
                    $tax += (float)$line['price'];
                }
            }
        }

        if(!empty($taxes)) {
            $cancelledOrderItem['taxes']=$taxes;
            $cancelledOrderItem['tax']['price']=$tax;
        }        

        $discount = 0;
        $discounts = [];
        if (!empty($item['discount_allocations'])) {
            foreach($item['discount_allocations'] as $line) {
                if(!empty($line['price'])) {
                    $discounts[] =  [
                        'code'=> !empty($line['title']) ? $line['title'] : "",
                        'price'=> (float)$line['price'],
                    ];
                    $discount += (float)$line['price'];
                }
            }
        } elseif(!empty($item['total_discount'])) {
            $discount = (float)$item['total_discount'];
        }

        if(!empty($discounts)) {
            $cancelledOrderItem['discounts']=$discounts;
        }

        $cancelledOrderItem['discount']['price'] = $discount;

        // if (isset($item['total_tax'])) {
        //     $cancelledOrderItem['tax'] = [
        //         'price' => $tax
        //     ];
        // }
        isset($orderData['cancel_reason']) && $cancelledOrderItem['cancel_reason']= $orderData['cancel_reason'];
        if(empty($cancelledOrderItem)) {
            return [];
        }

        return $cancelledOrderItem;
    }

    public function getProductIdentifier($sku)
    {
        return $sku;
    }

    /**
     * validate settings from config
     */
    public function getSettings(array $data): array
    {
        $user_details = $this->di->getUser()->getConfig();
        $userId = $user_details['user_id'];
        if(!isset($user_details['shops'])) {
            return [
                'success' => false,
                'message' => 'Shops not found'
            ];
        }

        foreach ($user_details['shops'] as $shop) {
            if ($shop['_id'] == $data['source_shop_id']) {       
                $source_shop_id = $shop['_id'];
            }

            if ($shop['_id'] == $data['target_shop_id']) {
                $target_shop_id = $shop['_id'];
            }
        }

        if (!isset($source_shop_id) || !isset($target_shop_id)) {
            return [
                'success' => false,
                'message' => 'source_shop_id or target_shop_id not matched with user shops'
            ];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection =  $mongo->getCollectionForTable('config');
        $pipeline = [];
        $filter = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        array_push($pipeline, ['$match' => ['user_id' => $userId]]);
        array_push($pipeline, ['$match' => ['group_code' => 'order']]);
        array_push($pipeline, ['$match' => ['source_shop_id' => (string)$target_shop_id]]);
        array_push($pipeline, ['$match' => ['target_shop_id' => (string)$source_shop_id]]);
        $settings = $collection->aggregate($pipeline, $filter)->toArray();
        if (empty($settings)) {
            return [
                'success' => false,
                'message' => 'Settings not found in config. Defaulting to false.'
            ];
        }

        $this->settings_data = $settings;
        return [
            'success' => true,
            'message' => 'settings set successfully'
        ];
    }

     /**
     * to check if settings are enabled or not
     *
     * @return boolean
     */
    public function isSettingEnabled()
    {
        $settingResponse = $this->checkSpecificSetting('order_sync');
        return $settingResponse;
    }

    /**
     * to check if cancellation is enabled
     *
     * @return boolean
     */
    public function isCancellationEnabled()
    {
        $settingResponse = $this->checkSpecificSetting('sync_order_cancellation_status');
        return $settingResponse;
    }

    /**
     * to check if cancellation is enabled
     *
     * @return boolean
     */
    public function isRefundEnabled()
    {
        $settingResponse = $this->checkSpecificSetting('refund_enabled');
        return $settingResponse;
    }

    /**
     * to check if specific setting exists
     *
     * @param [type] $setting
     * @return void
     */
    public function checkSpecificSetting($setting = null)
    {
        if (empty($setting)) {
            return false;
        }

        foreach ($this->settings_data as $value) {
            if ($value['key'] == $setting) {
                return $value['value'];
            }
        }

        return false;
    }

    /**
     * to varify if refund allowed or not
     */
    public function isPartialCancelAllowed():bool
    {
        return false;
    }

     /**
     * to notify merchant in case of partial refund
     *
     * @return void
     */
    public function notifyMerchantForPartialCancel($order)
    {
        $shop = $this->shopifyUserDetails($order['marketplace_shop_id']);

        if(empty($shop)) {
            return [
                'success' => false,
                'message' => 'Invalid user!! Shop Not Found'
            ];
        }

        $this->notifyMail($shop, $order);
        //$this->shopifyNotification($shop);
        return [
            'success' => true,
            'message' => 'Mail send successfully!'
        ];
    }

    /**
     * to notify mail notification
     */
    public function notifyMail($shop, $data): void
    {
        $user_details = $this->di->getUser()->getConfig();
        $appTag = $this->di->getAppCode()->getAppTag();
        $appName = $this->di->getObjectManager()->get("\App\Connector\Components\Order\Helper")->getAppName($appTag);
        $data['app_name'] = $appName;
        $path = 'connector' . DS . 'views' . DS . 'email' . DS . 'Cancel-order.volt';
        $data['email'] = $user_details['email'];
        $data['username'] = $shop['name'] ?? "there";
        $data['path'] = $path;
        $data['subject'] = 'Cancel Order Sync Failure';
        $data['link']= $shop['domain'];
        $response = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($data);
    }

    /**
     * to send notification to shopify
     *
     * @return void
     */
    public function shopifyNotification($shop)
    {
        $data = [
            'feedback_generated_at' => date_format(date_create('now'), 'c'),
            'messages' => ['Cancellation failed in automatic process. Cancel the order sent to your mail accordingly.'],
            'state' => 'requires_action'
        ];
        $response = $this->di->getObjectManager()->get('\App\Connector\Components\ApiClient')
            ->init("shopify", true)
            ->call('saleschannel/resource_feedback', [], ['shop_id' => $shop['remote_shop_id'] , 'data' => $data], "POST");
        return $response;
    }

    public function getFormattedData($data): array
    {
        $orders = [];
        if(isset($data['data'])) {
            $orders['orders'] = [$data['data']];
            isset($data['shop_id']) && $orders['shop_id'] = $data['shop_id'];
            isset($data['marketplace']) && $orders['marketplace'] = $data['marketplace'];
            foreach ($orders['orders'] as $key => $order) {
                isset($data['shop_id']) && $order['shop_id'] = $data['shop_id'];
                isset($data['marketplace']) && $order['marketplace'] = $data['marketplace'];
                $orders['orders'][$key] = $order;
            }
        } else {
            $orders = $data;
        }

        return $orders;
    }

    public function prepareData(array $order): array
    {
        return [];
    }

    public function validateForCancellation($data): array
    {
        if(isset($data['settings'])) {
            $settings = $data['settings'];
            if(isset($settings['sync_order_cancellation_status'])  && $settings['sync_order_cancellation_status']['value']) {
                $data['settings'] = true;
            } else {
                $data['settings'] = false;
            }
        } else {
            $data['settings'] = false;
        }

        return $data;
    }

    public function saveInLog($data, $file, $msg = ""): void
    {
        $time = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
        $path = __FILE__;
        $this->di->getLog()->logContent('-------------------------------------------------------------------------', 'info', $file);
        $this->di->getLog()->logContent($time . PHP_EOL . $path . PHP_EOL . $msg . PHP_EOL . '  ' . print_r($data, true), 'info', $file);
        $this->di->getLog()->logContent('-------------------------------------------------------------------------', 'info', $file);
    }

    public function getJobData($jobId, $remoteShopId, $orderId, $marketplace)
    {
        $callType = 'QL';
        $this->attempt++;
        $jobResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($marketplace, 'true')
            ->call('job', [], ['shop_id' => $remoteShopId, 'order_id'=> $orderId, 'job_id' => $jobId, 'call_type' => $callType], 'GET');
        if (!empty($jobResponse)) {
            if (isset($jobResponse['success'], $jobResponse['data']) && $jobResponse['success']) {
                $jobCompleted = $jobResponse['data']['job']['done'] ?? false;
                if ($jobCompleted) {
                    $orderData = $this->di->getObjectManager()->get('App\Shopifyhome\Components\RestFormatter\Order\Order')->formatData($jobResponse['data']['job']['query']['order'] ?? []);
                    return [
                        'success' => true,
                        'data' => $orderData
                    ];
                } else {
                    if ($this->attempt > 3) {
                        return [
                            'success' => false,
                            'message' => 'Job data not found!'
                        ];
                    }
                    sleep(1);
                    $this->getJobData($jobId, $remoteShopId, $orderId, $marketplace);
                }
            } else {
                return [
                    'success' => false,
                    'message' => $jobResponse['message'] ?? 'Error occur when fetching job data!'
                ];
            }
        } else {
            return [
                'success' => false,
                'message' => 'Remote response not found when getting job response!'
            ];
        }
    }
}