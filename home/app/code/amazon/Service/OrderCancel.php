<?php
/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */
namespace App\Amazon\Service;
use App\Connector\Contracts\Sales\Order\CancelInterface;
/**
 * Interface OrderInterface
 * @services
 */
#[\AllowDynamicProperties]
class OrderCancel implements CancelInterface
{

    public const  SCHEMA_VERSION='2.0';

    public $cancel_reason=[];

    public $isReasonSimilar = false;

    public $marketplaceCurrency = "";

    public function cancel($order): array
    {
        $feedContentRes = $this->createFeedContent($order);
        if (!$feedContentRes['success']) {
            return $feedContentRes;
        }

        $feedContent = $feedContentRes['data'];

        $remoteShopId = $this->getRemoteShopIdById($order['marketplace_shop_id']);
        if (!$remoteShopId) {
            return ['success' => false, 'message' => 'remote shop id not found'];
        }
        $messageId = array_key_first($feedContent);

        $saveFeedResponse = $this->saveFeedContent([
            'feedContent' => $feedContent,
            'user_id' => $order['user_id'] ?? $this->di->getUser()->id,
            'shop_id' => $order['marketplace_shop_id'],
            'remote_shop_id' => $remoteShopId,
            'feed_message_id' => $messageId,
            'amazon_order_id' => $order['marketplace_reference_id'],
        ]);

        if (!$saveFeedResponse['success']) {
            return $saveFeedResponse;
        }

        $returnPrepareData = $this->prepareResponseData($order, $messageId);

        return ['success' => true, 'orders' => $returnPrepareData];
    }

     /**
     * to create feed content for feed submit
     */
    public function createFeedContent($data): array
    {
        if (!isset($data['marketplace_reference_id'])) {
            return [
                'success' => false,
                'message' => 'marketplace_reference_id not found'
            ];
        }

        if (!isset($data['items'])) {
            return [
                'success' => false,
                'message' => 'Items not found'
            ];
        }

        $feedContent = [];
        $feedContent['Id'] = random_int(1000000, 10000000000);
        $feedContent['AmazonOrderID'] = $data['marketplace_reference_id'];
        $feedContent['StatusCode'] = 'Failure';

        foreach ($data['items'] as $item) {
            $feedItem = [];
            if (!isset($item['marketplace_item_id'], $item['cancel_reason'], $item['qty'])) {
                return [
                    'success' => false,
                    'message' => 'marketplace_item_id, cancel_reason and qty are required'
                ];
            }

            $feedItem = [
              'AmazonOrderItemCode' => $item['marketplace_item_id'],
              'CancelReason' => $item['cancel_reason'],
              'QuantityCancelled' => (int) $item['qty'],
            ];

            $feedContent['Item'][] = $feedItem;
        }

        $feed[$feedContent['Id']] = $feedContent;
        return [
            'success' => true,
            'data' => $feed
        ];
    }

    public function create($order): array
    {

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

  /**
     * Function to mould data according to cif
     *
     * @param array $completeData
     */
    public function prepareForDb($order = []): array
    {
        if (!empty($order['is_prepared'])) {
            unset($order['is_prepared']);
            return [
                'success'=> true,
                'data'=> $order
            ];
        }

        $this->cancel_reason = [];
        $this->globalTaxes = [];
        $this->globalDiscounts = [];
        $this->attributes = [];
        if(isset($order['shop_id'])){
            $orderData = [];
            $orderData['schema_version'] = self::SCHEMA_VERSION;
            $orderData['marketplace_shop_id'] = $order['shop_id'];
            $orderData['marketplace'] = $order['marketplace'] ?? 'amazon';
            $orderData['marketplace_warehouse_id'] = null;   
            $orderData['user_id'] = $this->di->getUser()->id;
            if (isset($order['data'])) 
            {
                if (empty($order['data']['AmazonOrderId'])) {
                    return [
                        'success' => false,
                        'message' => 'Amazon Order Id not found'
                    ]; 
                }

                //$orderData['attributes']= $this->getExtraAttribute($order['data']);
                $orderData['marketplace_reference_id'] = $order['data']['AmazonOrderId'];
                isset($order['data']['OrderStatus']) && $orderData['marketplace_status'] = $order['data']['OrderStatus'];
                isset($order['data']['BuyerInfo']) &&  $orderData['customer'] = $this->getCustomer($order['data']['BuyerInfo']);
                $orderItems = [];
                if(empty($order['items'])) {
                    return [
                        'success' => false,
                        'message'=> 'Items not found in amazon data'
                    ];
                }

                foreach ($order['items'] as $item) {
                    $orderItem = $this->getItems($item);
                    if(!empty($orderItem)){
                        array_push($orderItems, $orderItem);
                    }                        
                }

                if($orderItems === []){
                    return [
                        'success' => false,
                        'message' => 'No Items found for cancellation'
                    ];
                }

                $orderData['items'] = $orderItems;
                if(!empty($this->cancel_reason) && count(array_unique($this->cancel_reason)) == 1) {
                    $orderData['cancel_reason'] = $this->cancel_reason[0];
                }

                if(isset($order['data']['OrderTotal'])) {
                    $this->marketplaceCurrency = $order['data']['OrderTotal']['CurrencyCode'];
                    $orderData['total'] = (float)$order['data']['OrderTotal']['Amount'];
                }

                $orderData['attributes']= $this->getExtraAttribute($order['data']);
                //there is no outer taxes and discounts for now this will be added in future
                $orderData = $this->getUpdatedPriceDivisions($orderData);
                isset($order['data']['LastUpdateDate']) &&  $orderData['source_updated_at'] = $order['data']['LastUpdateDate'];
                isset($order['data']['PurchaseDate']) && $orderData['source_created_at'] = $order['data']['PurchaseDate'];
                $orderData['marketplace_currency'] = $this->marketplaceCurrency;
            } else {
                return [
                    'success'=> false,
                    'message'=> 'Order data not available'
                ];
            }

            return [
                'success'=> true,
                'data'=> $orderData
            ];           
        }
        return [
            'success'=> false,
            'message'=> 'Shop Id not available in amazon order data'
        ];
    }

    public function getUpdatedPriceDivisions($data)
    {
        // if(!empty($this->globalTaxes)) {
        //     foreach($this->globalTaxes as $taxes) {

        //     }
        // }

        return $data;
    }

     /**
     * to mould customer information
     */
    public function getCustomer($data): array
    {
        isset($data['BuyerName']) &&  $customer['name'] = $data['BuyerName'];
        if (!empty($data['BuyerEmail'])) {
            $customer['email'] = (strpos((string) $data['BuyerEmail'], "mailto:") !== null) ? str_replace("mailto:", "", $data['BuyerEmail']) : $data['BuyerEmail'];
        }

        $customer['id'] = $data['BuyerId'] ?? null;
        return  $customer;
    }

    /**
     * Moulds a single item
     */
    public function getItems($item = []): array
    {
        if(empty($item)) {
            return [];
        }

        /**
         * CHECKING CANCELLED ORDER, only those items will be returned that are cancelled
         */
        $order = [];
        $attributes = [];
        if(isset($item['BuyerRequestedCancel']) && isset($item['BuyerRequestedCancel']['IsBuyerRequestedCancel'])) {
            if(is_string($item['BuyerRequestedCancel']['IsBuyerRequestedCancel']) && ($item['BuyerRequestedCancel']['IsBuyerRequestedCancel'] == "true")) {
                $order['cancel_reason'] = $item['BuyerRequestedCancel']['BuyerCancelReason'] ?? "N/A";
                //ADDITIONAL CODE FOR BUYER REQUESTED CANCEL
                $order['cancel_reason'] = 'BuyerCanceled';
                $attributes = ['key' => 'BuyerCancelReason', 'value' => $item['BuyerRequestedCancel']['BuyerCancelReason'] ?? "N/A"];
            } elseif(is_bool($item['BuyerRequestedCancel']['IsBuyerRequestedCancel']) && $item['BuyerRequestedCancel']['IsBuyerRequestedCancel']) {
                $order['cancel_reason'] = $item['BuyerRequestedCancel']['BuyerCancelReason'] ?? "N/A";
                //ADDITIONAL CODE FOR BUYER REQUESTED CANCEL
                $order['cancel_reason'] = 'BuyerCanceled';
                $attributes = ['key' => 'BuyerCancelReason', 'value' => $item['BuyerRequestedCancel']['BuyerCancelReason'] ?? "N/A"];
            }  else {
                $order['cancel_reason'] = 'N/A';
            }
        } else if(isset($item['CancelReason'])) {
            $order['cancel_reason'] = $item['CancelReason'];
        } else {
            $order['cancel_reason'] = 'N/A';
        }

        if(!empty($attributes)) {
            $order['attributes'] = [$attributes];
        }

        $this->cancel_reason[] = $order['cancel_reason'];
        $tax = 0;
        $discount = 0;
        $taxes = [];
        $discounts = [];

        isset($item['OrderItemId']) &&  $order['marketplace_item_id'] = $item['OrderItemId'];
        isset($item['Title']) && $order['title'] = $item['Title'];
        $order['sku'] = $item['SellerSKU'] ?? null;
        //$order['product_indentifier'] = $this->getProductIdentifier($item['SellerSKU']);
        $order['qty'] = (int)$this->getQuantity($item);
        if($order['qty'] == 0) {
            return [];
        }

        if (isset($item['ItemPrice'])) {
            $order['price'] = (float)$item['ItemPrice']['Amount'];
            $this->marketplaceCurrency = $item['ItemPrice']['CurrencyCode'];
        }

        if (isset($item['ShippingPrice'])) {
            $order['shipping_charge']['price'] = (float)$item['ShippingPrice']['Amount'];
            $this->marketplaceCurrency = $item['ItemPrice']['CurrencyCode'];
        }

        /** tax rate calculation */
        if (isset($item['ItemTax'])) {
            $tax += (float)$item['ItemTax']['Amount'];
            array_push($taxes, [
                "code" => "ItemTax",
                'price' => (float)$item['ItemTax']['Amount'],
            ]);
        }

        if (isset($item['ShippingTax'])) {
            $tax += (float)$item['ShippingTax']['Amount'];
            array_push($taxes, [
                "code" => "ShippingTax",
                'price' => (float)$item['ShippingTax']['Amount'],
            ]);
        }

        if (isset($item['ShippingDiscountTax'])) {
            $tax += (float)$item['ShippingDiscountTax']['Amount'];
            array_push($taxes, [
                "code" => "ShippingDiscountTax",
                'price' => (float)$item['ShippingDiscountTax']['Amount'],
            ]);
        }

        if (isset($item['PromotionDiscountTax'])) {
            $tax += (float)$item['PromotionDiscountTax']['Amount'];
            array_push($taxes, [
                "code" => "PromotionDiscountTax",
                'price' => (float)$item['PromotionDiscountTax']['Amount'],
            ]);
        }

        /** discount calculation */
        if (isset($item['ShippingDiscount'])) {
            $discount += (float)$item['ShippingDiscount']['Amount'];
            array_push($discounts, [
                "code" => "ShippingDiscount",
                'price' => $item['ShippingDiscount']['Amount'],
            ]);
        }

        if (isset($item['PromotionDiscount'])) {
            $discount += (float)$item['PromotionDiscount']['Amount'];
            array_push($discounts, [
                "code" => "PromotionDiscount",
                'price' => (float)$item['PromotionDiscount']['Amount']
            ]);
        }        

        /**
         * Assigning tax, discount, shipping and price
         */
        $order['discount']['price'] = $discount;
        $order['tax']['price'] = $tax;
        $order['taxes'] = $taxes;
        array_push($this->globalTaxes, $taxes);
        $order['discounts'] = $discounts;
        array_push($this->globalDiscounts, $discounts);
        return $order;    
    }

    public function getProductIdentifier($sku)
    {
        return $sku;
    }

    public function getQuantity($item)
    {
        return $item['QuantityCancelled'] ?? "0";
    }

    /**
     * @return array<mixed, array<'key'|'value', mixed>>
     */
    public function getExtraAttribute($order): array
    {
        $attributes = [];
        $attributes[] = ['key'=>'Amazon Order Id' , 'value'=>$order['AmazonOrderId']];
        // if(!empty($this->attributes)) {
        //     foreach($this->attributes as $attr) {
        //         $attributes[]  = $attr;
        //     }
        // }
        return $attributes;
    }

    public function getFormattedData($data): array
    {
        if(isset($data['data']['orders'])) {
            foreach($data['data']['orders'] as $key => $order) {
                if(!isset($order['shop_id']) && isset($data['shop_id'])) {
                    $data['data']['orders'][$key]['shop_id'] = $data['shop_id'];
                }

                if(!isset($order['marketplace']) && isset($data['marketplace'])) {
                    $data['data']['orders'][$key]['marketplace'] = $data['marketplace'];
                }
            }

            return $data['data'];
        }
        return $data;
    }

    public function prepareData($order): array
    {
        return [];
    }

    public function isPartialCancelAllowed():bool
    {
        return true;
    }

    /**
     * to map reasons according to marketplace
     *
     * @param string $marketplace
     */
    public function getCancelReason($reason, $marketplace):array
    {
        $path = BP . DS . 'app' . DS . 'code' . DS . 'amazon' . DS . 'utility' . DS . 'cancel_reasons.json';
        $cancelReasonsJson = file_get_contents($path);
        $mappedReason = json_decode($cancelReasonsJson, true);
        if(isset($mappedReason[$marketplace],$mappedReason[$marketplace][$reason]))
        {
            return [
                'success' => true,
                'data' => $mappedReason[$marketplace][$reason]
            ];
        }

        return [
            'success' => false,
            'message' => 'Unable to map reason "'.$reason.'" on Amazon'
        ];
    }

    public function saveFeedContent($params): array
    {
        $params = [
            'feedContent' => $params['feedContent'],
            'user_id' => $params['user_id'],
            'home_shop_id' => (string)$params['shop_id'],
            'type' => 'POST_ORDER_ACKNOWLEDGEMENT_DATA',
            'admin' => true,
            'shop_id' => (string)$params['remote_shop_id'],
            'feed_message_id' => (string)$params['feed_message_id'],
            'source_order_id' => $params['amazon_order_id'],
            'operation_type' => 'Update',
        ];

        try {
            $amzFeedCollection = $this->di->getObjectManager()
                ->create('\App\Core\Models\BaseMongo')
                ->getCollection('amazon_feed_data');

            $amzFeedCollection->insertOne($params);
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Something went wrong. Please try again.'];
        }
    }

    private function getRemoteShopIdById($shopId)
    {
        $shops = $this->di->getUser()->shops ?? [];
        foreach ($shops as $shop) {
            if ($shop['_id'] == $shopId && isset($shop['remote_shop_id'])) {
                return $shop['remote_shop_id'];
            }
        }

        return null;
    }

    private function prepareResponseData($order, $messageId)
    {
        $orderFieldsToRemove = [
            '_id',
            'object_type',
            'attributes',
            'billing_address',
            'conditional_tags',
            'customer',
            'default_ship_from_address',
            'discount',
            'discounts',
            'filter_tags',
            'is_ispu',
            'last_delivery_date',
            'payment_info',
            'shipping_address',
            'shipping_charge',
            'shipping_details',
            'status_updated_at',
            'taxes_included',
            'total_weight',
            'settings',
            'settings_data',
            'isPartial'
        ];

        foreach ($orderFieldsToRemove as $field) {
            unset($order[$field]);
        }

        $itemFieldsToRemove = [
            'type',
            'object_type',
            'cancelled_qty',
            'shipping_charge',
            'taxes',
            'tax',
            'discounts',
            'discount',
            'order_id',
            'id'
        ];

        foreach ($order['items'] as $index => $item) {
            foreach ($itemFieldsToRemove as $field) {
                unset($order['items'][$index][$field]);
            }
        }

        $order['is_prepared'] = 1;
        $order['feed_message_id'] = $messageId;
        $order['cancellation_inprogress'] = true;
        return $order;
    }
}
