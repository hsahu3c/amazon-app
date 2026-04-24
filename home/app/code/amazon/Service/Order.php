<?php
/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */
namespace App\Amazon\Service;
use App\Connector\Models\Product\Marketplace;
use ZipArchive;
use App\Core\Models\Config\Config;
use App\Connector\Contracts\Sales\OrderInterface;
use App\Amazon\Components\Common\Helper;
use Aws\S3\S3Client;
use Exception;
use DateTime;
use DateTimeZone;

/**
 * Interface OrderInterface
 * @services
 */
class Order implements OrderInterface
{
    public const FULFILLED_BY_MERCHANT = 'merchant';

    public const FULFILLED_BY_OTHER = 'other';

    public const ORDER_CUSTOMISATION_IMAGE_BUCKET = 'order-customisation-image';

    public const ORDER_CUSTOMISATION_BUCKET_ACL = 'public-read';

    private const MIN_ORDER_IMPORT_CUTOFF_PERIOD = '-2 months';

    private const MARKETPLACE_COUNTRY_CODE = [
        'ATVPDKIKX0DER'  => 'US',
        'A2EUQ1WTGCTBG2' => 'CA',
        'A1AM78C64UM0Y8' => 'MX',
        'A1RKKUPIHCS9HS' => 'ES',
        'A1F83G8C2ARO7P' => 'UK',
        'A13V1IB3VIYZZH' => 'FR',
        'A1PA6795UKMFR9' => 'DE',
        'APJ6JRA9NG5V4'  => 'IT',
        'A2Q3Y263D00KWC' => 'BR',
        'A2VIGQ35RCS4UG' => 'AE',
        'A21TJRUUN4KGV'  => 'IN',
        'A1VC38T7YXB528' => 'JP',
        'A39IBJ37TRP1C6' => 'AU',
        'A19VAU5U5O7RUS' => 'SG',
        'A1805IZSGTT6HS' => 'NL',
        'A17E79C6D8DWNP' => 'SA',
        'A2NODRKZP88ZB9' => 'SE',
        'A1C3SOZRARQ6R3' => 'PL',
        'AMEN7PMS3EDWL'  => 'BE',
        'ARBP9OOSHTCHU'  => 'EG',
        'A33AVAJ2PDY3EV' => 'TR',
        'AE08WJ6YKNBMC'  => 'ZA',
        'A28R8C7NBKEWEA' => 'IE',
    ];

    private $shopId = null;

    private $uploadImageConfig = null;

    private ?S3Client $s3Client = null;

    private $customItemAttributesConfig = null;

    private $customAttributesConfig = null;

    private $taxRatesPrecisionConfig = null;

    private $useFboCustomerId = null;

    private $defaultCurrency = null;

    public function create($data): array
    {

    }

    public function update($filter , $data): array
    {

    }

    public function get($data): array
    {

    }

    public function getByField($data): array
    {

    }

    public function getAll($data): array
    {

    }

    public function archiveOrder($data):array
    {

    }

    public function prepareForDb($data):array
    {    

        if(isset($data['shop_id'])){
            $returnData = [];
            $order = $data['order'];
            $orderWrapper = $order['data'];
            if(isset($orderWrapper['FulfillmentChannel']) && $orderWrapper['FulfillmentChannel']=='AFN')
            {
                // return ['success'=>false,'message'=>'We are unable to process Amazon fulfilled Order'];
            }

            $returnData['fulfilled_by'] = isset($orderWrapper['FulfillmentChannel'])
                && $orderWrapper['FulfillmentChannel'] == 'AFN'
                ? self::FULFILLED_BY_OTHER
                : self::FULFILLED_BY_MERCHANT;

            if(empty($order['items']))
            {
                $userInfo = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $getDetails = $userInfo->getShop($data['shop_id'], $this->di->getUser()->id);

                $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                $params = [
                    'shop_id' => $getDetails['remote_shop_id'],
                    'amazon_order_id' => $orderWrapper['AmazonOrderId'],
                    'home_shop_id' => $data['shop_id'],
                ];
                $response = $commonHelper->sendRequestToAmazon('order', $params, 'GET');
                if(isset($response['success']) && $response['success'] && isset($response['orders']) && isset($response['orders'][0]) && isset($response['orders'][0]['items']) && !empty($response['orders'][0]['items']))
                {
                    $order['items'] =  $response['orders'][0]['items'];
                }
            }

            $items = $order['items'];

            $returnData['marketplace_reference_id'] = $orderWrapper['AmazonOrderId'];
            $returnData['marketplace'] = $data['marketplace'] ?? 'amazon';
            $returnData['marketplace_shop_id'] = $data['shop_id'];
            $returnData['user_id'] = $this->di->getUser()->id;
            $returnData['shop_id'] = $this->shopId =  $data['shop_id'];
            $returnData['marketplace_status'] = $orderWrapper['OrderStatus'];
            if (isset($orderWrapper['manually_imported']) && $orderWrapper['manually_imported']) {
                $returnData['manually_imported'] = true;
            }

            isset($orderWrapper['IsISPU']) && $returnData['is_ispu'] = $orderWrapper['IsISPU'];
            isset($orderWrapper['LatestDeliveryDate']) &&
                $returnData['last_delivery_date'] = $orderWrapper['LatestDeliveryDate'];

            if (isset($orderWrapper['LatestShipDate'])) {
                $returnData['last_ship_date'] = $orderWrapper['LatestShipDate'];
            }

            if (
                isset($orderWrapper['IsReplacementOrder'])
                && $orderWrapper['IsReplacementOrder']
                && !empty($orderWrapper['ReplacedOrderId'])
            ) {
                $returnData['parent_order_id'] = $orderWrapper['ReplacedOrderId'];
                $parentOrderResponse = $this->getParentOrderFromDb($orderWrapper['ReplacedOrderId'], $data);
                if ($parentOrderResponse['success']) {
                    $parentOrderData = $parentOrderResponse['data'];
                    $replacementOrderCurrency = $parentOrderData['marketplace_currency'] ?? null;
                }
            }

            if (isset($orderWrapper['LastUpdateDate'])) {
                $returnData['status_updated_at'] = $orderWrapper['LastUpdateDate'];
            }

            $lineItems = [];
            $marketplaceCurrency = "";
            $this->defaultCurrency = null;
            $totalTax = 0.00;
            $taxes = [];
            $totalDiscount = 0.00;
            $totalShipping = 0.00;
            $totalItemPrice = 0.00;
            $discounts = [];

            if (isset($returnData['is_ispu']) && $returnData['is_ispu']) {
                $returnData['filter_tags'][] = 'ISPU';
            }

            if (isset($orderWrapper['IsLocalizedSelfDelivery'])) {
                $returnData['filter_tags'][] = 'Local Delivery';
            }

            $returnData['filter_tags'][] = $returnData['fulfilled_by'] == 'other' ? 'FBA' : 'FBM';

            if (isset($orderWrapper['EasyShipShipmentStatus'])) {
                $returnData['filter_tags'][] = 'Easy Ship';
            }

            if (!empty($orderWrapper['IsBusinessOrder'])) {
                $returnData['is_business_order'] = $orderWrapper['IsBusinessOrder'];
            }

            $this->uploadImageConfig = $this->getConfig('upload_customisation_image');
            $this->customItemAttributesConfig = $this->getConfig('add_custom_item_attributes');
            $this->taxRatesPrecisionConfig = $this->getConfig('tax_rates_precision');

            $this->useFboCustomerId = null;
            if (isset($orderWrapper['FulfillmentChannel']) && $orderWrapper['FulfillmentChannel'] == 'AFN') {
                $this->useFboCustomerId = $this->getConfig('use_fbo_customer_id');
            }

            $fbaOrderImportCutoffConfig = $this->getConfig('fbo_order_import_cutoff_period');
            $fixedDeliveryMethodConfig = $this->getConfig('fixed_delivery_method');
            $marketplacePrefixConfig = $this->getConfig('marketplace_prefix_shipping_method');

            foreach($items as $item)
            {
                $lineItemTotalTax = 0.00;
                $lineItemTotalDiscount = 0.00;
                $lineItem = [];
                $lineItem['type'] = $this->getItemType($item);
                $lineItem['marketplace_item_id'] = $item['OrderItemId'];
                $lineItem['sku'] = $this->getSku($item);

                // isset($item['ASIN']) && $lineItem['product_identifier'] = $item['ASIN'];

                if($this->di->getUser()->id=="60eed74e6dbf1875d51d6e3a") {
                    if(isset($lineItem['sku'])){
                        $lineItem['sku']=str_replace("-LOCAL","",$lineItem['sku']);
                    }
                }

                $lineItem['qty'] = $item['QuantityOrdered'] ?? '0';
                $lineItem['cancelled_qty'] = $item['QuantityCanceled'] ?? '0';

                if ($lineItem['qty'] == 0) {
                    $logFile = "order/order-create/duplicate-sku-error/" . date('Y-m-d') . '.log';
                    $this->di->getLog()->logContent('Data => ' . json_encode([
                        'user_id' => $this->di->getUser()->id,
                        'data' => $data
                    ]), 'info', $logFile);
                    continue;
                }

                $itemPrice = 0;
                if (isset($item['ItemPrice']['Amount']) && $item['QuantityOrdered'] > 0) {
                    $itemPrice = $item['ItemPrice']['Amount'] / $item['QuantityOrdered'];
                    $totalItemPrice += $item['ItemPrice']['Amount'];
                }

                $lineItem['price'] = $itemPrice;
                if($orderWrapper['IsISPU']){
                    $lineItem['storechainId'] = $item['StoreChainStoreId'] ?? '';
                }

                $shippingPrice = 0;
                if (isset($item['ShippingPrice']['Amount'])) {
                    $shippingPrice = $item['ShippingPrice']['Amount'];
                    $totalShipping += $item['ShippingPrice']['Amount'];
                }

                $lineItem['shipping_charge'] = ['price'=>$shippingPrice];

                $lineItem['title'] =  $this->getTitle($item);

                if (isset($item['ItemPrice']['CurrencyCode'])) {
                    $marketplaceCurrency = $item['ItemPrice']['CurrencyCode'];
                } elseif (isset($replacementOrderCurrency) && !is_null($replacementOrderCurrency)) {
                    $marketplaceCurrency = $replacementOrderCurrency;
                } else {
                    $marketplaceCurrency = $this->fetchDefaultCurrencyForShop($data);
                }

                if(isset($item['ItemTax'])){
                    $totalTax += $item['ItemTax']['Amount'];
                    $lineItemTotalTax += $item['ItemTax']['Amount'];
                    $taxes[] = ['code'=>'ItemTax','price'=>$item['ItemTax']['Amount']];
                    $lineItem['taxes'][] = ['code'=>'ItemTax','price'=>$item['ItemTax']['Amount']];
                }

                if(isset($item['ShippingTax'])){
                    $lineItemTotalTax += $item['ShippingTax']['Amount'];
                    $totalTax += $item['ShippingTax']['Amount'];
                    $taxes[] = ['code'=>'ShippingTax','price'=>$item['ShippingTax']['Amount']];
                    $lineItem['taxes'][] = ['code'=>'ShippingTax','price'=>$item['ShippingTax']['Amount']];
                }

                $lineItem['tax'] = ['price'=>$lineItemTotalTax];



                if(isset($item['ShippingDiscount'])){
                    $totalDiscount += $item['ShippingDiscount']['Amount'];
                    $lineItemTotalDiscount += $item['ShippingDiscount']['Amount'];
                    $discounts[] = ['code'=>'ShippingDiscount','price'=>$item['ShippingDiscount']['Amount']];
                    $lineItem['discounts'][] = ['code'=>'ShippingDiscount','price'=>$item['ShippingDiscount']['Amount']];

                }

                if(isset($item['PromotionDiscount'])){
                    $totalDiscount += $item['PromotionDiscount']['Amount'];
                    $lineItemTotalDiscount += $item['PromotionDiscount']['Amount'];
                    $discounts[] = ['code'=>'PromotionDiscount','price'=>$item['PromotionDiscount']['Amount']];
                    $lineItem['discounts'][] = ['code'=>'PromotionDiscount','price'=>$item['PromotionDiscount']['Amount']];
                }

                $lineItem['discount'] = ['price'=>$lineItemTotalDiscount];
                if (isset($item['BuyerInfo']) && isset($item['BuyerInfo']['BuyerCustomizedInfo']) && isset($item['BuyerInfo']['BuyerCustomizedInfo']['CustomizedURL'])) {
                    $lineItem['attributes'] = $this->getItemExtraAttribute($item,$orderWrapper); 
                }

                if (isset($item['IossNumber'])) {
                    $lineItem['attributes'][] = ['key' => 'IossNumber', 'value' => $item['IossNumber']];
                }

                if (isset($item['BuyerInfo']['GiftMessageText'])) {
                    $lineItem['attributes'][] = [
                        'key' => 'GiftMessage',
                        'value' => $item['BuyerInfo']['GiftMessageText'],
                    ];
                    $additionalAttr[] = [
                        'key' => 'GiftMessage',
                        'value' => $item['BuyerInfo']['GiftMessageText']
                    ];
                }

                $this->addCustomItemAttributes($item, $lineItem);
                $lineItems[] = $lineItem;
            }


            if(!empty($lineItems))
            {
                $returnData['items'] = $lineItems;
                $returnData['tax'] = ['price'=>$totalTax];
                $returnData['shipping_charge'] = ['price'=>$totalShipping];
                $totalItemTax = 0;
                $totalShippingTax = 0;
                foreach($taxes as $tax){
                    if(isset($tax['code']) && $tax['code'] == 'ItemTax')
                    {
                        $totalItemTax +=$tax['price'];
                    } else {
                        $totalShippingTax +=$tax['price'];
                    }

                }

                $taxes = [];

                $isTaxIncluded = $this->isTaxIncluded($order);

                if($totalItemTax)
                {
                    $taxes[] = [
                        'code'=>'ItemTax',
                        'price'=>$totalItemTax,
                        'rate' => $this->getTaxRate($totalItemPrice - $totalDiscount, $totalItemTax, $order['region'], $isTaxIncluded)

                    ];
                }

                if($totalShippingTax)
                {
                    $taxes[] = [
                        'code'=>'ShippingTax',
                        'price'=>$totalShippingTax,
                        'rate'=> $this->getTaxRate($totalShipping, $totalShippingTax, $order['region'], $isTaxIncluded)

                    ];
                }

                $returnData['taxes'] = $taxes;

                $returnData['discount'] = ['price'=>$totalDiscount];
                $returnData['discounts'] = $discounts;
                $returnData['marketplace_currency'] = $marketplaceCurrency; 
                $returnData['customer'] = $this->getCustomer($orderWrapper);
                $returnData['default_ship_from_address'] = $this->getDefaultShipFromAddress($orderWrapper);
                $returnData['shipping_address'] = [$this->getShippingAddress($orderWrapper)];
                $returnData['billing_address'] = $this->getBillingAddress($orderWrapper);
                $returnData['attributes'] = $this->getExtraAttribute($orderWrapper); 
                if (isset($additionalAttr) && !empty($additionalAttr)) {
                    $returnData['attributes'] = array_merge($returnData['attributes'], $additionalAttr);
                }

                $returnData['total'] = $orderWrapper['OrderTotal']['Amount'] ?? 0;
                $returnData['total_weight'] =  0;
                $returnData['payment_info'] = ['payment_method'=>$orderWrapper['PaymentMethod'] ?? ''];
                $returnData['taxes_included'] = $this->isTaxIncluded($order);

                if (isset($orderWrapper['PurchaseDate'])) {
                    $returnData['source_created_at'] = $orderWrapper['PurchaseDate'];

                    if ($returnData['fulfilled_by'] == self::FULFILLED_BY_OTHER && !empty($fbaOrderImportCutoffConfig['value']['enabled']) && !empty($fbaOrderImportCutoffConfig['value']['period'])) {

                        //temp
                        $fbaOrderImportCutoffConfig['value']['period'] = '-' . $fbaOrderImportCutoffConfig['value']['period'];
                        //temp ends

                        $orderImportCutoffDate = strtotime($fbaOrderImportCutoffConfig['value']['period']);
                        $minOrderImportCutoffDate = strtotime(self::MIN_ORDER_IMPORT_CUTOFF_PERIOD);

                        $sourceCreatedAtUnixTimestamp = strtotime($returnData['source_created_at']);

                        if ($orderImportCutoffDate <= $minOrderImportCutoffDate && $sourceCreatedAtUnixTimestamp < $orderImportCutoffDate) {
                            return [
                                'success' => false,
                                'message' => 'Order was placed before the cutoff date and cannot be imported.'
                            ];
                        }
                    }
                }

                $shipmentServiceLevelCategory = '';
                if (isset($orderWrapper['ShipmentServiceLevelCategory'])) {
                    $shipmentServiceLevelCategory = $orderWrapper['ShipmentServiceLevelCategory'];
                } elseif (isset($orderWrapper['ShipServiceLevelCategory'])) {
                    $shipmentServiceLevelCategory = $orderWrapper['ShipServiceLevelCategory'];
                }

                $returnData['shipping_details'] = [
                    'method'=>$shipmentServiceLevelCategory
                ];


                if (!empty($orderWrapper['AutomatedShippingSettings']['HasAutomatedShippingSettings'])) {
                    $customShippingDetailsMethodConfig = $this->getConfig('custom_shipping_details_method');
                    if (!empty($customShippingDetailsMethodConfig['value']['enabled'])) {
                        $customKeyName = $customShippingDetailsMethodConfig['value']['key'] ?? null;
                        $customShippingDetailMethod = $orderWrapper['AutomatedShippingSettings'][$customKeyName]
                            ?? $orderWrapper['AutomatedShippingSettings']['AutomatedShipMethod']
                            ?? $orderWrapper['AutomatedShippingSettings']['AutomatedShipMethodName'];
                        if (!is_null($customShippingDetailMethod)) {

                            // Check if the shipment service level category should be appended
                            $shouldAppend = !empty($customShippingDetailsMethodConfig['value']['append_shipment_service_level_category']['enabled']);
                            if ($shouldAppend) {
                                if (!empty($customShippingDetailsMethodConfig['value']['append_shipment_service_level_category']['make_readable'])) {
                                    $shipmentServiceLevelCategory = $this->makeReadable($shipmentServiceLevelCategory);
                                }

                                $serviceLevelCategorySeparator = $customShippingDetailsMethodConfig['value']['append_shipment_service_level_category']['separator'] ?? ' ';

                                // Determine where to append (front or back)
                                $appendPosition = $customShippingDetailsMethodConfig['value']['append_shipment_service_level_category']['position'] ?? 'back';
                                $customShippingDetailMethod = ($appendPosition === 'front')
                                    ? $shipmentServiceLevelCategory . $serviceLevelCategorySeparator . $customShippingDetailMethod
                                    : $customShippingDetailMethod . $serviceLevelCategorySeparator . $shipmentServiceLevelCategory;
                            }

                            $returnData['shipping_details']['method'] = $customShippingDetailMethod;
                        }
                    }
                }

                if (!empty($fixedDeliveryMethodConfig['value']['enabled']) && !empty($fixedDeliveryMethodConfig['value']['method'])) {
                    $returnData['shipping_details']['method'] = $fixedDeliveryMethodConfig['value']['method'];
                }

                if (!empty($marketplacePrefixConfig['value']['enabled'])) {
                    $shopDetails = $this->di->getObjectManager()
                        ->get('\App\Core\Models\User\Details')
                        ->getShop($data['shop_id'], $this->di->getUser()->id);
                    $marketplaceId = $shopDetails['warehouses'][0]['marketplace_id'] ?? null;
                    $countryCode = self::MARKETPLACE_COUNTRY_CODE[$marketplaceId] ?? null;
                    if ($countryCode) {
                        $separator = $marketplacePrefixConfig['value']['separator'] ?? '-';
                        $position = $marketplacePrefixConfig['value']['position'] ?? 'front';
                        $currentMethod = $returnData['shipping_details']['method'];
                        $returnData['shipping_details']['method'] = ($position === 'front')
                            ? $countryCode . $separator . $currentMethod
                            : $currentMethod . $separator . $countryCode;
                    }
                }

            } else {
                return ["success"=>false,"message"=>"Order Item missing"];
            }

            $this->setConditionalTags($orderWrapper, $returnData);
            return ['success'=>true,'data'=>$returnData];

        }
        return ['success'=>false,'message'=>'Shop id not exist'];

    }

    private function makeReadable(string $input, string $style = 'pascal'): string
    {
        $converted = match ($style) {
            'camel', 'pascal' => preg_replace('/(?<!^)([A-Z])/', ' $1', $input),
            'snake' => str_replace('_', ' ', $input),
            default => $input,
        };

        return ucwords($converted);
    }

    public function getTaxRate($amount, $tax, $region, $isTaxIncluded = false): int|float
    {
        if ($amount == 0 || $tax == 0) {
            return 0;
        }

        if ($isTaxIncluded) {
            $amount = $amount - $tax;
        }

        $taxPercent = ($tax / $amount);
        $taxP = $taxPercent * 100;
        $precision = empty($this->taxRatesPrecisionConfig['value']) ? 0 : (int)$this->taxRatesPrecisionConfig['value'];
        $taxPp = round($taxP, $precision);
        $taxPercent = $taxPp / 100;
        return $taxPercent;
    }

    // public function isTaxIncluded(array $data):bool
    // {
    //     if ($data['region'] == 'europe' || $data['region'] == 'EU') {
    //         return 1;
    //     } else {
    //         $hasTax = false;
    //         $itemPriceAndShippingPriceTotal = 0;
    //         foreach ($data['items'] as $item) {
    //             if ((isset($item['ItemTax']['Amount']) && $item['ItemTax']['Amount'] > 0)
    //                 || (isset($item['ShippingTax']['Amount']) && $item['ShippingTax']['Amount'] > 0)
    //             ) {
    //                 $hasTax = true;
    //             }
    //             $itemPriceAndShippingPriceTotal += ($item['ItemPrice']['Amount'] ?? 0)
    //                 + ($item['ShippingPrice']['Amount'] ?? 0);
    //         }
    //         $date = date('Y-m-d');
    //         $this->di->getLog()->logContent(
    //             PHP_EOL . __FILE__ . PHP_EOL . 'INFO = ' . print_r($data, true),
    //             'info',
    //             "order/non-eu-taxes-included/{$this->di->getUser()->id}/{$date}/{$data['data']['AmazonOrderId']}.log"
    //         );
    //         if ($hasTax && $itemPriceAndShippingPriceTotal == $data['data']['OrderTotal']['Amount']) {
    //             return 1;
    //         }
    //         return 0;
    //     }
    // }

    public function isTaxIncluded($data): bool
    {
        $taxIncluded = false;
        $hasTax = false;
        $totalOrderPrice = 0;

        foreach ($data['items'] as $item) {
            $itemTax = $item['ItemTax']['Amount'] ?? 0;
            $shippingTax = $item['ShippingTax']['Amount'] ?? 0;

            if ($itemTax > 0 || $shippingTax > 0) {
                $hasTax = true;
            }

            $totalOrderPrice += ($item['ItemPrice']['Amount'] ?? 0)
                + ($item['ShippingPrice']['Amount'] ?? 0)
                + ($item['ShippingDiscountTax']['Amount'] ?? 0)
                + ($item['PromotionDiscountTax']['Amount'] ?? 0)
                - ($item['ShippingDiscount']['Amount'] ?? 0)
                - ($item['PromotionDiscount']['Amount'] ?? 0);
        }

        $logContent = PHP_EOL . __FILE__ . PHP_EOL . 'INFO = ' . print_r($data, true);
        $logPath = "order/taxes-included/{$this->di->getUser()->id}/" . date('Y-m-d') . "/{$data['data']['AmazonOrderId']}.log";
        $this->di->getLog()->logContent($logContent, 'info', $logPath);

        if ($hasTax) {
            $taxIncluded = (string)$totalOrderPrice == (string)$data['data']['OrderTotal']['Amount'];
        } else {
            $taxIncluded = $data['region'] == 'europe' || $data['region'] == 'EU';
        }

        return $taxIncluded;
    }

    public function setConditionalTags($orderWrapper, &$preparedData): void
    {
        $conditionalTagsConfig = $this->getConfig('conditional_tags');
        if (empty($conditionalTagsConfig['value']['enabled']) || empty($conditionalTagsConfig['value']['keys'])) {
            return;
        }

        $tagKeys = $conditionalTagsConfig['value']['keys'];

        $conditionalTags = [];
        foreach ($tagKeys as $tagKey => $tagConfig) {
            if (empty($orderWrapper[$tagKey])) {
                continue;
            }

            if (!$tagConfig['enabled']) {
                continue;
            }
            $conditionalTag = match ($tagKey) {
                'FulfillmentChannel' => $orderWrapper['FulfillmentChannel'] == 'AFN' ? 'FBA' : "FBM",
                'IsPrime' => 'IsPrime',
                'EasyShipShipmentStatus' => 'EasyShip',
                'IsBusinessOrder' => 'BusinessOrder',
                default => null,
            };
            $customConditionalTag = $tagConfig['custom_tag'] ?? [];
            $conditionalTag = $customConditionalTag[$conditionalTag] ?? $conditionalTag;

            if (!is_null($conditionalTag)) {
                $conditionalTags[] = $conditionalTag;
            }
        }

        $preparedData['conditional_tags'] = $conditionalTags;
    }

    public function addCustomItemAttributes($amazonItem, &$preparedItem): void
    {
        if (
            empty($this->customItemAttributesConfig['value']['enabled'])
            || empty($this->customItemAttributesConfig['value']['keys'])
        ) {
            return;
        }

        $extraItemAttributesKey = $this->customItemAttributesConfig['value']['keys'];

        foreach ($extraItemAttributesKey as $attributeKey => $attributeValue) {
            if (empty($amazonItem[$attributeKey])) {
                continue;
            }

            if (!$attributeValue['enabled']) {
                continue;
            }
            $itemAttribute = match ($attributeKey) {
                'OrderItemId' => $amazonItem['OrderItemId'],
                'ScheduledDeliveryEndDate' => $this->getCustomAttributeForScheduledDelivery($amazonItem, $attributeValue),
                default => null,
            };
            if (!is_null($itemAttribute)) {
                $extraItemAttributeKey = $attributeValue['custom_key'][$attributeKey] ?? $attributeKey;
                $preparedItem['attributes'][] = [
                    "key" => $extraItemAttributeKey,
                    "value" => $itemAttribute
                ];
            }
        }
    }

    public function addCustomAttributes($amazonOrder, &$attributes): void
    {
        if (
            empty($this->customAttributesConfig['value']['enabled'])
            || empty($this->customAttributesConfig['value']['keys'])
        ) {
            return;
        }

        $extraAttributesKeys = $this->customAttributesConfig['value']['keys'];

        foreach ($extraAttributesKeys as $attributeKey => $attributeValue) {
            if (!$attributeValue['enabled']) {
                continue;
            }

            if (empty($amazonOrder[$attributeKey]) && strpos($attributeKey, '.') === false) {
                continue;
            }

            $customAttributeValue = match ($attributeKey) {
                'AmazonOrderId' => $amazonOrder['AmazonOrderId'],
                default => $this->resolveCustomAttributeValueByPath($amazonOrder, $attributeKey),
            };

            if (!is_null($customAttributeValue)) {
                $customAttributeKey = $attributeValue['custom_key'] ?? $attributeKey;
                $attributes[] = [
                    "key" => $customAttributeKey,
                    "value" => $customAttributeValue
                ];
            }
        }
    }

    private function resolveCustomAttributeValueByPath(array $data, string $attributePath)
    {
        if ($attributePath === '' || strpos($attributePath, '.') === false) {
            return null;
        }

        if (array_key_exists($attributePath, $data)) {
            return $data[$attributePath];
        }

        $segments = explode('.', $attributePath);
        $current  = $data;

        foreach ($segments as $segment) {
            if (!is_array($current) || !isset($current[$segment])) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private function getCustomAttributeForScheduledDelivery($amazonItem, $attributeValue)
    {
        $startTime = $amazonItem['ScheduledDeliveryStartDate'] ?? null;
        $endTime = $amazonItem['ScheduledDeliveryEndDate'] ?? null;

        if (!empty($startTime) && !empty($endTime) && !empty($attributeValue['value_format']['date_format'])) {
            if (empty($attributeValue['value_format']['timezone'])) {
                $dateFormat = $attributeValue['value_format']['date_format'];
                $startTime = date($dateFormat, strtotime($startTime));
                $endTime = date($dateFormat, strtotime($endTime));
                return $startTime . ' to ' . $endTime;
            } else {
                $dateFormat = $attributeValue['value_format']['date_format'];
                $timezone = $attributeValue['value_format']['timezone'] ;
                try {
                    $startDate = new DateTime($startTime, new DateTimeZone('UTC'));
                    $endDate = new DateTime($endTime, new DateTimeZone('UTC'));
                    $startDate->setTimezone(new DateTimeZone($timezone));
                    $endDate->setTimezone(new DateTimeZone($timezone));
                    $startTime = $startDate->format($dateFormat);
                    $endTime = $endDate->format($dateFormat);
                    return "{$startTime} {$timezone} to $endTime {$timezone}";
                } catch (Exception $e) {
                    $startTime = date($dateFormat, strtotime($startTime));
                    $endTime = date($dateFormat, strtotime($endTime));
                    return $startTime . ' to ' . $endTime;
                }
            }
        }

        return null;
    }

    public function getParentOrderFromDb($parentOrderId, $requestData): array
    {
        if (empty($parentOrderId)) {
            return ['success' => false, 'message' => 'Parent order id required'];
        }

        $query = [
            'user_id' => $this->di->getUser()->id,
            'object_type' => 'source_order',
            'marketplace' => 'amazon',
            'marketplace_shop_id' => $requestData['shop_id'],
            'marketplace_reference_id' => $parentOrderId
        ];

        $orderContainer = $this->di->getObjectManager()
            ->create('\App\Core\Models\BaseMongo')
            ->getCollectionForTable('order_container');
        $parentOrderData = $orderContainer->findOne($query);
        if (!empty($parentOrderData)) {
            return ['success' => true, 'data' => $parentOrderData];
        }

        return ['success' => false, 'message' => 'Parent order not found'];
    }

    public function getItemType($item):string
    {
        return OrderInterface::ITEM_REAl;
    }

    public function getSku($item):string
    {
        return $item['SellerSKU'];
    }

    public function getProductIdentifier($sku):string
    {
        $getProductBySku = $this->di->getObjectManager()->get(Marketplace::class);

    }


    public function getTitle($item):string
    {
        return $item['Title'];
    }

    public function getCustomer($order):array
    {
        $customer = [];
        if(isset($order['ShippingAddress'],$order['ShippingAddress']['Name']))
        {
            $customer['name'] = $order['ShippingAddress']['Name'];
        }
        elseif(isset($order['BuyerInfo'],$order['BuyerInfo']['BuyerName']))
        {
            $buyerInfo = $order['BuyerInfo'];

            if(isset($buyerInfo['BuyerName'])){
                $customer['name'] = $buyerInfo['BuyerName'];
            }

            if(isset($buyerInfo['BuyerEmail'])){
                $customer['email'] = $buyerInfo['BuyerEmail'];
            }

        }
        elseif(isset($order['BuyerName']))
        {
            $customer['name'] = $order['BuyerName'];
        }

        if(isset($order['BuyerEmail']))
        {
            $customer['email'] = $order['BuyerEmail'];
        }

        if(isset($order['BuyerInfo']['BuyerEmail']))
        {
            $customer['email'] = $order['BuyerInfo']['BuyerEmail'];
        }

        if (!empty($this->useFboCustomerId['value']['enabled']) && !empty($this->useFboCustomerId['value']['id'])) {
            unset($customer);
            $customer['id'] = $this->useFboCustomerId['value']['id'];
        }

        return $customer;

    }

    /**
     * @return mixed[]
     */
    public function getDefaultShipFromAddress($order): array
    {
        $defaultShipFromAddress = [];
        if (!isset($order['DefaultShipFromLocationAddress'])) {
            return $defaultShipFromAddress;
        }

        $amazonShipFromAddress = $order['DefaultShipFromLocationAddress'];

        $defaultShipFromAddress['name'] = $amazonShipFromAddress['Name'] ?? null;
        $defaultShipFromAddress['address_line_1'] = $amazonShipFromAddress['AddressLine1'] ?? null;
        $defaultShipFromAddress['address_line_2'] = $amazonShipFromAddress['AddressLine2'] ?? null;
        $defaultShipFromAddress['address_line_3'] = $amazonShipFromAddress['AddressLine3'] ?? null;
        $defaultShipFromAddress['city'] = $amazonShipFromAddress['City'] ?? $amazonShipFromAddress['StateOrRegion'] ?? null;
        $defaultShipFromAddress['zip'] = $amazonShipFromAddress['PostalCode'] ?? null;
        $defaultShipFromAddress['state'] = $amazonShipFromAddress['StateOrRegion'] ?? null;
        $defaultShipFromAddress['country'] = $amazonShipFromAddress['Country'] ?? $amazonShipFromAddress['CountryCode'] ?? null;

        $defaultShipFromAddress = array_filter($defaultShipFromAddress, fn($value): bool => $value !== null);

        return $defaultShipFromAddress;
    }

    public function getShippingAddress($order):array
    {
        $shippingAddress = [];

        if(isset($order['ShippingAddress']))
        {
            $amazonShipping = $order['ShippingAddress'];

            $customerInfo =  $this->getCustomer($order);
            if(isset($customerInfo['name']))
            {
                $shippingAddress['name'] = $customerInfo['name'];
            }

            if (isset($amazonShipping['Name'])) {
                $shippingAddress['name'] = $amazonShipping['Name'];
            }

            if(isset($amazonShipping['AddressType'])){
                $shippingAddress['type'] = $amazonShipping['AddressType'];
            }

            if(isset($amazonShipping['StateOrRegion'])){
                $shippingAddress['state'] = $amazonShipping['StateOrRegion'];
            }

            if(isset($amazonShipping['AddressLine1'])){
                $shippingAddress['address_line_1'] = $amazonShipping['AddressLine1'];
            }

            if(isset($amazonShipping['AddressLine2'])){
                $shippingAddress['address_line_2'] = $amazonShipping['AddressLine2'];
            }

            if(isset($amazonShipping['AddressLine3'])){
                $shippingAddress['address_line_3'] = $amazonShipping['AddressLine3'];
            }

            if(isset($amazonShipping['City'])){
                $shippingAddress['city'] = $amazonShipping['City'];
            } else {
                if(isset($amazonShipping['StateOrRegion']))
                {
                    $shippingAddress['city'] = $amazonShipping['StateOrRegion'];
                }
            }

            if(isset($amazonShipping['PostalCode'])){
                $shippingAddress['zip'] = $amazonShipping['PostalCode'];
            }

            if(isset($amazonShipping['Country'])){
                $shippingAddress['country'] = $amazonShipping['Country'];
            } else {
                if(isset($amazonShipping['CountryCode']))
                {
                    $shippingAddress['country'] = $amazonShipping['CountryCode'];
                }
            }

            if(isset($amazonShipping['CountryCode'])){
                $shippingAddress['country_code'] = $amazonShipping['CountryCode'];
            }

            if(isset($amazonShipping['StateCode'])){
                $shippingAddress['state_code'] = $amazonShipping['StateCode'];
            }

            if(isset($amazonShipping['StateOrRegion'])){
                $shippingAddress['state'] = $amazonShipping['StateOrRegion'];
            }

            if(isset($amazonShipping['Phone'])){
                $shippingAddress['phone'] = $amazonShipping['Phone'];
            }
        }

        return $shippingAddress;


    }

    public function getBillingAddress($order):array
    {
        $billingAddress = [];

        if(isset($order['BillingAddress']))
        {
            $amazonBilling = $order['BillingAddress'];

            $customerInfo =  $this->getCustomer($order);
            if(isset($customerInfo['name']))
            {
                $billingAddress['name'] = $customerInfo['name'];
            }

            if(isset($amazonBilling['AddressType'])){
                $billingAddress['type'] = $amazonBilling['AddressType'];
            }

            if(isset($amazonBilling['StateOrRegion'])){
                $billingAddress['state'] = $amazonBilling['StateOrRegion'];
            }

            if(isset($amazonBilling['AddressLine1'])){
                $billingAddress['address_line_1'] = $amazonBilling['AddressLine1'];
            }

            if(isset($amazonBilling['AddressLine2'])){
                $billingAddress['address_line_2'] = $amazonBilling['AddressLine2'];
            }

            if(isset($amazonBilling['AddressLine3'])){
                $billingAddress['address_line_3'] = $amazonBilling['AddressLine3'];
            }

            if(isset($amazonBilling['City'])){
                $billingAddress['city'] = $amazonBilling['City'];
            }

            if(isset($amazonBilling['PostalCode'])){
                $billingAddress['zip'] = $amazonBilling['PostalCode'];
            }

            if(isset($amazonBilling['Country'])){
                $billingAddress['country'] = $amazonBilling['Country'];
            }

            if(isset($amazonBilling['CountryCode'])){
                $billingAddress['country_code'] = $amazonBilling['CountryCode'];
            }

            if(isset($amazonBilling['StateCode'])){
                $billingAddress['state_code'] = $amazonBilling['StateCode'];
            }

            if(isset($amazonBilling['Phone'])){
                $billingAddress['phone'] = $amazonBilling['Phone'];
            }
        }else {
            $billingAddress['same_as_shipping'] = "1";
        }

        return $billingAddress;

    }

    public function getExtraAttribute($order):array
    {
        $attributes = [];
        $attributes[] = ['key'=>'Source Order Id' , 'value'=>$order['AmazonOrderId']];

        if(isset($order['LatestDeliveryDate'])){
            $attributes[] = [
                'key' => 'Amazon LatestDeliveryDate',
                'value' => $order['LatestDeliveryDate']
            ];
        }

        if(isset($order['EarliestShipDate'])){
            $attributes[] = [
                'key' => 'Amazon EarliestShipDate',
                'value' => $order['EarliestShipDate']
            ];
        }

        if(isset($order['LatestShipDate'])){
            if($this->di->getUser()->id == '619f3afd61f4540bfb7cb3fe'){
                $attributes[] = [
                    'key' => 'Fulfill by',
                    'value' => $order['LatestShipDate']
                ];
            } else {
                $attributes[] = [
                    'key' => 'Amazon LatestShipDate',
                    'value' => $order['LatestShipDate']
                ];
            }

        }

        if(isset($order['EarliestDeliveryDate'])){
            $attributes[] = [
                'key' => 'Amazon EarliestDeliveryDate',
                'value' => $order['EarliestDeliveryDate']
            ];
        }

        if(isset($order['ShippingAddress']['AddressType'])){
            $attributes[] = [
                'key' => 'Amazon Address Type',
                'value' => $order['ShippingAddress']['AddressType']
            ];
        }

        if(isset($order['BuyerTaxInfo']))
        {
            $buyerTaxInfo = $order['BuyerTaxInfo'];

            if(isset($buyerTaxInfo['TaxClassifications']))
            {
                if(isset($buyerTaxInfo['TaxClassifications']['TaxClassification']))
                {
                    $buyerTaxInfo['TaxClassifications'][]['TaxClassification'] = $buyerTaxInfo['TaxClassifications']['TaxClassification'];
                    unset($buyerTaxInfo['TaxClassifications']['TaxClassification']);
                }

                foreach ($buyerTaxInfo['TaxClassifications'] as $taxClassification) {
                    $attributes[] = [
                        'key' =>$taxClassification['TaxClassification']['Name'],
                        'value' => trim((string) $taxClassification['TaxClassification']['Value'])
                    ];
                }
            }
        }

        if(isset($order['BuyerInfo']) && isset($order['BuyerInfo']['BuyerTaxInfo']))
        {
            $buyerTaxInfo = $order['BuyerInfo']['BuyerTaxInfo'];

            if(isset($buyerTaxInfo['TaxClassifications']))
            {
                if(isset($buyerTaxInfo['TaxClassifications']['TaxClassification']))
                {
                    $buyerTaxInfo['TaxClassifications'][]['TaxClassification'] = $buyerTaxInfo['TaxClassifications']['TaxClassification'];
                    unset($buyerTaxInfo['TaxClassifications']['TaxClassification']);
                }

                foreach ($buyerTaxInfo['TaxClassifications'] as $taxClassification) {
                    $attributes[] = [
                        'key' =>strtolower((string) $taxClassification['Name']),
                        'value' => trim((string) $taxClassification['Value'])
                    ];
                }
            }
        }

        if (isset($order['MarketplaceId'])) {
            $attributes[] = [
                'key' => 'MarketplaceId',
                'value' => $order['MarketplaceId'],
                'sync' => false,
            ];
        }

        $this->customAttributesConfig = $this->getConfig('add_custom_attributes');
        $this->addCustomAttributes($order, $attributes);

        return $attributes;
    }

    public function getItemExtraAttribute($orderItem, $orderData):array
    {

        $extraAttribute = $this->getItemCustomizationData($orderItem,$orderData);

        $attributes = [];
        if($extraAttribute['success']){
            if(!empty($extraAttribute['data'])){
                foreach($extraAttribute['data'] as $attribute)
                {
                    if(!empty($attribute))
                    {
                        $itemAttribute = [
                            'key' => $attribute['name'],
                            'value' => $attribute['value']
                        ];
                        if (isset($attribute['sync'])) {
                            $itemAttribute['sync'] = $attribute['sync'];
                        }

                        if (isset($attribute['type'])) {
                            $itemAttribute['type'] = $attribute['type'];
                        }

                        $attributes[] = $itemAttribute;
                    }
                }
            }
        }

        return $attributes;

    }

    public function getItemCustomizationData($itemData,$orderData): array
    {
        if (isset($itemData['BuyerInfo']['BuyerCustomizedInfo']['CustomizedURL'])) {
            $customizedURL = $itemData['BuyerInfo']['BuyerCustomizedInfo']['CustomizedURL'];
            $headers = @get_headers($customizedURL);
            // Use condition to check the existence of URL
            if (!($headers && strpos( (string) $headers[0], '200'))) {
                return ['success'=>false,'message'=>'File Url not exist in amazon'];
            }

            $file_name = $itemData['OrderItemId'];

            $fileLocation = BP . DS . 'var' . DS . 'file' . DS . $this->di->getUser()->id . DS . 'order_customization' . DS . $orderData['AmazonOrderId'] . DS . $file_name;
            $filePath = "{$fileLocation}.zip";

            if (!file_exists($filePath)) {
                $dirname = dirname($filePath);
                if (!is_dir($dirname)) {
                    mkdir($dirname, 0777, true);
                }

                file_put_contents($filePath, file_get_contents(realpath($customizedURL)));
            }

            if (file_exists($filePath)) {
                $zip = new ZipArchive;
                $res = $zip->open($filePath);
                if ($res === TRUE) {
                    $zip->extractTo("{$fileLocation}/");
                    $zip->close();

                    $customizationFilePath = "{$fileLocation}/{$file_name}.json";

                    $this->updateCustomisationPathIfNeeded(
                        $customizationFilePath,
                        $fileLocation,
                        $file_name
                    );

                    $customizationFilePath = realpath($customizationFilePath);
                    if (file_exists($customizationFilePath)) {
                        $customizationJson = file_get_contents($customizationFilePath);

                        if ($customizationJson) {
                            $customizationData = json_decode($customizationJson, true);
                            $firstKey = "customizationInfo";
                            $secondKey  = "version3.0";

                            if(isset($customizationData['version3.0']))
                            {
                                $secondKey = "customizationInfo";
                                $firstKey  = "version3.0";
                            }

                            if (isset($customizationData[$firstKey][$secondKey]['surfaces']) && is_array($customizationData[$firstKey][$secondKey]['surfaces'])) {
                                $itemCustomizations = [];

                                $customizationInfoArray = $customizationData[$firstKey][$secondKey]['surfaces'];

                                foreach ($customizationInfoArray as $customizationInfo) {
                                    $itemCustomizationName = $customizationInfo['name'];

                                    if (isset($customizationInfo['areas']) && is_array($customizationInfo['areas'])) {
                                        foreach ($customizationInfo['areas'] as $area) {
                                            $itemCustomizations[] = $this->getItemCustomizationValue($itemCustomizationName, $area);
                                        }
                                    } else {
                                        $itemCustomizations[] = $this->getItemCustomizationValue($itemCustomizationName, $customizationInfo);
                                    }
                                }

                                $this->uploadImageInS3IfAvailableAndAddImageUrl(
                                    $itemCustomizations,
                                    $fileLocation,
                                    $orderData,
                                    $customizationData
                                );
                                return ['success' => true, 'data' => $itemCustomizations];
                            }

                            return ['success' => false, 'message' => 'customization data not found.'];
                        }
                        return ['success' => false, 'message' => 'customization json file not found.'];
                    }
                    return ['success' => false, 'message' => 'customization file not found after extraction.'];
                }
                return ['success' => false, 'message' => 'file not extracted'];
            }
            return ['success' => false, 'message' => 'file not downloaded'];
        }
        return ['success' => false, 'message' => 'CustomizedURL not set'];
    }

    public function updateCustomisationPathIfNeeded(
        &$customizationFilePath,
        $fileLocation,
        $fileName
    ): void {
        if (file_exists($customizationFilePath)) {
            return;
        }

        $jsonFiles = array_values(array_filter(
            scandir($fileLocation),
            fn($file): bool => str_contains((string) $file, '.json')
        ));
        foreach ($jsonFiles as $jsonFile) {
            $filePath = "{$fileLocation}/{$jsonFile}";
            if (!file_exists($filePath)) {
                continue;
            }

            $fileData = json_decode(file_get_contents($filePath), true);
            if (!empty($fileData)) {
                $newFileName = $fileData['legacyOrderItemId'] ?? $fileName;
                if (file_exists("{$fileLocation}/{$newFileName}.json")) {
                    $customizationFilePath = "{$fileLocation}/{$newFileName}.json";
                    break;
                }
            }
        }
    }

    public function uploadImageInS3IfAvailableAndAddImageUrl(
        &$itemCustomizations,
        $folderPath,
        $orderData,
        $customizationData
    ): void {
        $this->di->getLog()->logContent(
            PHP_EOL . __FILE__ . PHP_EOL . 'INFO = user_id - ' . print_r($this->di->getUser()->id, true),
            'info',
            "order/order_customisation_analysis/{$this->di->getUser()->id}/{$orderData['AmazonOrderId']}.log"
        );

        $this->di->getLog()->logContent(
            PHP_EOL . __FILE__ . PHP_EOL . 'INFO = config response' . print_r($this->uploadImageConfig, true),
            'info',
            "order/order_customisation_analysis/{$this->di->getUser()->id}/{$orderData['AmazonOrderId']}.log"
        );

        if (empty($this->uploadImageConfig) || !$this->uploadImageConfig['value']) {
            $this->di->getLog()->logContent(
                PHP_EOL . __FILE__ . PHP_EOL . 'INFO = ' . print_r("upload_customisation_image -> disabled", true),
                'info',
                "order/order_customisation_analysis/{$this->di->getUser()->id}/{$orderData['AmazonOrderId']}.log"
            );

            $users = ['64ca8d977142585735094ef2','6407357d720f13f05f0d87f2'];
            if (!in_array($this->di->getUser()->id,$users)) {
                return;
            }

        }

        $this->di->getLog()->logContent(
            PHP_EOL . __FILE__ . PHP_EOL . 'INFO = ' . print_r("upload_customisation_image -> enabled", true),
            'info',
            "order/order_customisation_analysis/{$this->di->getUser()->id}/{$orderData['AmazonOrderId']}.log"
        );

        $imageExtensions = ['png', 'jpg', 'jpeg', 'gif'];

        $imageFiles = $this->getImagesFromFolder($folderPath, $imageExtensions);

        $this->di->getLog()->logContent(
            PHP_EOL . __FILE__ . PHP_EOL . 'INFO = images found in folder - ' . print_r($imageFiles, true),
            'info',
            "order/order_customisation_analysis/{$this->di->getUser()->id}/{$orderData['AmazonOrderId']}.log"
        );

        if (empty($imageFiles)) {
            return;
        }

        $clubbedImages = [];
        $clubbedImagesCount = [];
        $groupedImagesResponse = $this->groupImagesByExtension($imageFiles);
        $clubbedImages = $groupedImagesResponse['grouped_images'] ?? [];
        $clubbedImagesCount = $groupedImagesResponse['grouped_images_count'] ?? [];

        $extensionPreferenceOrder = ['png', 'jpg', 'jpeg'];
        $extensionToUpload = $this->getExtensionToUpload($clubbedImagesCount, $extensionPreferenceOrder);
        $imagesToUpload = $clubbedImages[$extensionToUpload] ?? [];

        $this->di->getLog()->logContent(
            PHP_EOL . __FILE__ . PHP_EOL . 'INFO = images to upload - ' . print_r($imagesToUpload, true),
            'info',
            "order/order_customisation_analysis/{$this->di->getUser()->id}/{$orderData['AmazonOrderId']}.log"
        );

        if (empty($imagesToUpload)) {
            return;
        }

        try {
            $this->setS3ClientObject();
            $counter = 1;
            foreach ($imagesToUpload as $image) {
                $pathOfImageToUpload = "{$folderPath}/{$image}";
                if (!file_exists($pathOfImageToUpload)) {
                    continue;
                }

                $awsResponse = $this->s3Client->putObject([
                    'Bucket' => self::ORDER_CUSTOMISATION_IMAGE_BUCKET,
                    'Key' => $orderData['AmazonOrderId'] . '_' . mt_rand() . '_' . $counter,
                    'Body'   => fopen($pathOfImageToUpload, 'r'),
                    'ACL'    => self::ORDER_CUSTOMISATION_BUCKET_ACL,
                ]);

                $fileUrl = $awsResponse->get('ObjectURL');
                $itemCustomizations[] = [
                    'name' => $this->getCustomisationImageAmazonLabel(
                        $image,
                        $customizationData['customizationData'] ?? []
                    ) ?? 'Uploaded_Image_' . mt_rand() . '_' . $counter,
                    'value' => $fileUrl,
                    'type' => 'image_url',
                ];
                $counter++;
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent(
                PHP_EOL . __FILE__ . PHP_EOL . 'ERROR = ' . print_r($e->getMessage(), true),
                'info',
                'order/order_customisation_error.log'
            );
        }
    }

    public function addImagePathToItemAttributesIfAvailable(
        &$itemCustomizations,
        $folderPath,
        $orderData,
        $customizationData
    ): void {
        $imageFiles = $this->getImagesFromFolder($folderPath);
        if (empty($imageFiles)) {
            return;
        }

        $groupedImagesResponse = $this->groupImagesByExtension($imageFiles);
        $groupedImages = $groupedImagesResponse['grouped_images'] ?? [];
        $groupedImagesCount = $groupedImagesResponse['grouped_images_count'] ?? [];
        $extensionToUpload = $this->getExtensionToUpload($groupedImagesCount);
        $imagesToUpload = $groupedImages[$extensionToUpload];
        if (empty($imagesToUpload)) {
            return;
        }

        $counter = 1;
        foreach ($imagesToUpload as $image) {
            $pathOfImageToUpload = "{$folderPath}/{$image}";
            if (!file_exists($pathOfImageToUpload)) {
                continue;
            }

            $amazonImageLabel = $this->getCustomisationImageAmazonLabel(
                $image,
                $customizationData['customizationData'] ?? []
            ) ?? 'Uploaded_Image_' . mt_rand() . '_' . $counter;
            $itemCustomizations[] = [
                'name' => $amazonImageLabel,
                'value' => $pathOfImageToUpload,
                'type' => 'image_path'
            ];
        }

    }

    public function getCustomisationImageAmazonLabel($imageName, $customizationData)
    {
        if (isset($customizationData['image']['imageName']) && $imageName == $customizationData['image']['imageName']) {
            return $customizationData['label'];
        }

        if (isset($customizationData['children']) && is_array($customizationData['children'])) {
            //todo - replace below foreach with inbuilt php array functions is possible
            foreach ($customizationData['children'] as $child) {
                $imageLabel = $this->getCustomisationImageAmazonLabel($imageName, $child);
                if ($imageLabel) {
                    return $imageLabel;
                }
            }
        }

        return null;
    }

    public function getConfig($key = null, $groupCode = 'order', $type = 'Target', $appTag = null)
    {
        $configModel = $this->di->getObjectManager()->get(Config::class);
        $configModel->reset();
        $configModel->setGroupCode($groupCode);
        $configModel->setTargetShopId($this->shopId);

        $setMarketplaceType = 'set' . $type;
        $configModel->$setMarketplaceType('amazon');
        $configModel->setAppTag($appTag);
        $configModel->setUserId($this->di->getUser()->id);

        $configResponse = $configModel->getConfig($key);

        $date = date('Y-m-d');

        $configRequestData = [
            'user_id' => $this->di->getUser()->id,
            'group_code' => $groupCode,
            'target_shop_id' => $this->shopId,
            'key' => $key,
            'app_tag' => $appTag,
        ];
        $this->di->getLog()->logContent(
            PHP_EOL . __FILE__ . PHP_EOL . 'INFO = config request - ' . print_r(json_encode($configRequestData), true),
            'info',
            "order/order_customisation_analysis/{$this->di->getUser()->id}/{$date}.log"
        );
        $this->di->getLog()->logContent(
            PHP_EOL . __FILE__ . PHP_EOL . 'INFO = config response' . print_r($configResponse, true),
            'info',
            "order/order_customisation_analysis/{$this->di->getUser()->id}/{$date}.log"
        );

        return count($configResponse) == 1 ? array_values($configResponse)[0] : $configResponse;
    }

    public function getImagesFromFolder($folderPath, $imageExtensions = ['png', 'jpg', 'jpeg']): array
    {
        return array_values(array_filter(
            scandir($folderPath),
            function (string $file) use ($folderPath, $imageExtensions): bool {
                $filePath = $folderPath . '/' . $file;
                return is_file($filePath)
                    && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $imageExtensions);
            }
        ));
    }

    public function groupImagesByExtension($imageFiles): array
    {
        $groupedImages = [];
        $groupedImagesCount = [];
        foreach ($imageFiles as $imageFile) {
            $extension = pathinfo((string) $imageFile, PATHINFO_EXTENSION);
            $groupedImages[$extension][] = $imageFile;
            $groupedImagesCount[$extension] = isset($groupedImagesCount[$extension])
                ? $groupedImagesCount[$extension] + 1
                : 1;
        }

        return ['grouped_images' => $groupedImages, 'grouped_images_count' => $groupedImagesCount];
    }

    public function getExtensionToUpload($clubbedImagesCount, $extensionPreferenceOrder = ['png', 'jpg', 'jpeg'])
    {
        $extensionToUpload = null;
        if (count(array_unique($clubbedImagesCount)) == 1) {
            foreach ($extensionPreferenceOrder as $extension) {
                if (isset($clubbedImagesCount[$extension])) {
                    $extensionToUpload = $extension;
                    break;
                }
            }
        }

        if ($extensionToUpload == null) {
            asort($clubbedImagesCount);
            $extensionToUpload = end($clubbedImagesCount);
        }

        return $extensionToUpload;
    }

    public function getItemCustomizationValue($surfaceName, $customization): array
    {
        if (isset($customization['customizationType'])) {
            $customizationValue = [];

            $type = $customization['customizationType'];

            $name = $customization['label'] ?? ($customization['name'] ?? '');

            $skip = ['customizationType', 'priceDelta', 'label', 'name'];
            $value = [];
            $key_value = [];
            foreach ($customization as $c_key => $c_value) {
                if (in_array($c_key, $skip)) {
                    continue;
                }
                if (!empty($c_value)) {
                    if (is_array($c_value)) {
                        $value[] = json_encode($c_value);
                        $key_value[] = $c_key . ' : ' . json_encode($c_value);
                    } else {
                        $value[] = $c_value;
                        $key_value[] = "{$c_key} : {$c_value}";
                    }
                }
            }

            $valCount = count($key_value);

            if ($valCount && $name) {
                if ($valCount == 1) {
                    $customizationValue = [
                        'name' => $name,
                        'value' => current($value)
                    ];
                } else {
                    $customizationValue = [
                        'name' => $name,
                        'value' => implode("\n", $key_value)
                    ];
                }
            }

            return $customizationValue;
        }
        return [];
    }

    public function validateForCreate($data):array 
    {
        //todo needed
        return [];

    }

    public function setS3ClientObject(): void
    {
        if (is_null($this->s3Client)) {
            $this->s3Client = new S3Client(include BP . '/app/etc/aws.php');
        }
    }

    public function acknowledgeOrder($allOrderAcknowledgements)
    {
        if (count($allOrderAcknowledgements) > 1) {
            //discuss what we have to do
            //like not sending anything
            //or comma separated order_ids
            //also handle this on target
        }

        $settings = $allOrderAcknowledgements[0]['order_settings'];
        if (!isset($settings['add_notes_on_source']['value'])
            || !$settings['add_notes_on_source']['value']
        ) {
            return ['success' => false, 'message' => 'Add notes not enabled on source'];
        }

        $params = [];
        $userId = $allOrderAcknowledgements[0]['source_data']['user_id'] ?? $this->di->getUser()->id;
        $homeShopId = $allOrderAcknowledgements[0]['source_data']['shop_id'] ?? false;
        $shops = $this->di->getUser()->shops ?? [];
        $remoteShopId = false;
        foreach ($shops as $shop) {
            if ($shop['_id'] == $homeShopId) {
                $remoteShopId = $shop['remote_shop_id'] ?? false;
                break;
            }
        }

        if (!$remoteShopId) {
            return ['success' => false, 'message' => 'Shop not found'];
        }

        $orderAcknowledgementFeedContent = [];
        foreach ($allOrderAcknowledgements as $acknowledgement) {
            $prepareFeedResponse = $this->prepareAcknowledgementFeedData($acknowledgement);
            if ($prepareFeedResponse['success']) {
                $orderAcknowledgementFeedContent += $prepareFeedResponse['data'];
            }
        }

        if (!empty($orderAcknowledgementFeedContent)) {
            $params = [
                'feedContent' => $orderAcknowledgementFeedContent,
                'user_id' => $userId,
                'home_shop_id' => $homeShopId,
                'operation_type' => 'Update',
                'type' => 'POST_ORDER_ACKNOWLEDGEMENT_DATA',
                'admin' => true,
                'shop_id' => $remoteShopId,
                'source_order_id' => $allOrderAcknowledgements[0]['source_data']['marketplace_reference_id'],
                'target_order_id' => $allOrderAcknowledgements[0]['target_data']['marketplace_reference_id'],
                'target_shop_id' => $allOrderAcknowledgements[0]['target_data']['shop_id']
            ];
            try {
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $collection = $mongo->getCollectionForTable('amazon_feed_data');
                $collection->insertOne($params);
            } catch (Exception $e) {
                $this->di->getLog()->logContent('Exception From acknowledgeOrder Function=> '
                    . json_encode($e, true), 'info', 'exception.log');
            }

            // $commonHelper = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper');
            // $commonHelper->sendRequestToAmazon(
            //     'order-acknowledge',
            //     [
            //         'shop_id' => $remoteShopId,
            //         'data' => $params
            //     ],
            //     'POST'
            // );
        }
    }

    public function prepareAcknowledgementFeedData($acknowledgement): array
    {
        $acknowledgeId = false;
        $attributes = $acknowledgement['target_data']['attributes'] ?? [];

        $acknowledgeIdKey = 'acknowledge_id';
        $index = array_search($acknowledgeIdKey, array_column($attributes, 'key'));

        if ($index !== false) {
            $acknowledgeId = $attributes[$index]['value'];
        }

        if (!$acknowledgeId) {
            $acknowledgeId = $acknowledgement['target_data']['marketplace_reference_id'] ?? false;
        }

        $feedData = [];
        $feedData['AmazonOrderID'] = $acknowledgement['source_data']['marketplace_reference_id'] ?? false;
        $feedData['MerchantOrderID'] = $acknowledgeId;
        if (!$feedData['AmazonOrderID'] || !$feedData['MerchantOrderID']) {
            return [
                'success' => false,
                'message' => 'marketplace_reference_id is required in both the source_data and target_data'
            ];
        }

        $feedData['StatusCode'] = 'Success';
        $feedData['Id'] = random_int(1000000, 10000000000);
        $preparedFeed[$feedData['Id']] = $feedData;
        return ['success' => true, 'data' => $preparedFeed];
    }

    public function updateAcknowledgedOrder($submitData, $responseData, $userId, $serverless = true): void
    {
        $allIds = [];
        if ($serverless) {
            if (isset($submitData['Message'])) {
                if (!isset($submitData['Message'][0])) {
                    $temp[] = $submitData['Message'];
                    unset($submitData['Message']);
                    $submitData['Message'] = $temp;
                    $temp = [];
                }

                foreach ($submitData['Message'] as $amazonId) {
                    $allIds[$amazonId['MessageID']] = $amazonId['OrderAcknowledgement']['AmazonOrderID'];
                }
            }
        } else {
            foreach ($submitData as $messageId => $data) {
                $allIds[$messageId] = $data['AmazonOrderID'];
            }
        }

        $recordsSuccessful = $responseData['Message']['ProcessingReport']['ProcessingSummary']['MessagesSuccessful'];
        if (isset($responseData['Message']) && isset($responseData['Message']['ProcessingReport']['Result'])) {
            $result = $responseData['Message']['ProcessingReport']['Result'];
            if (!isset($result[0])) {
                $temp[] = $result;
                unset($result);
                $result = $temp;
            }

            foreach ($result as $value) {
                if (isset($value['ResultCode']) && $value['ResultCode'] == 'Error') {
                    $messageId = $value['MessageID'];
                    $amzId = $allIds[$messageId];
                    $errorIds[] = $amzId;
                    $this->updateAcknowledgedOrderInDB($amzId, $userId, false, $value['ResultDescription']);
                }
            }
        }

        $allIdValues = array_values($allIds);
        if (!empty($errorIds)) {
            $validIds = array_diff($allIdValues, $errorIds);
        } else {
            $validIds = $allIdValues;
        }

        if ($recordsSuccessful > 0) {
            foreach ($validIds as $orderId) {
                $this->updateAcknowledgedOrderInDB($orderId, $userId, true);
            }
        }
    }

    public function updateAcknowledgedOrderInDB($orderId, $userId, $success = true, $error = ''): void
    {
        $query = [
            'user_id' => $userId,
            'object_type' => 'source_order',
            'marketplace' => 'amazon',
            'marketplace_reference_id' => $orderId
        ];
        $update = [];
        $orderContainer = $this->di->getObjectManager()
            ->create('\App\Core\Models\BaseMongo')
            ->getCollectionForTable('order_container');
        if ($success) {
            $update['acknowledged'] = true;
        } else {
            $update['acknowledgement_error'] = $error;
        }

        $orderContainer->updateOne($query, ['$set' => $update]);
    }

    public function afterTargetOrderCreated($event, $component, $data): void
    {
        $sourceOrder = $data['source_order'] ?? null;
        $targetOrder = $data['target_order'] ?? null;

        if (!$sourceOrder || !$targetOrder) {
            return;
        }

        if ($sourceOrder['marketplace'] != "amazon") {
            return;
        }

        if (isset($sourceOrder['fulfilled_by']) && $sourceOrder['fulfilled_by'] == self::FULFILLED_BY_OTHER) {
            $this->updateFBOStatus($sourceOrder, $targetOrder);
            $this->initiateVatInvoiceRequestAfterOrderCreated($sourceOrder);
        }

    }

    private function initiateVatInvoiceRequestAfterOrderCreated(array $sourceOrder): void
    {
        try {
            $userId = (string)($sourceOrder['user_id'] ?? '');
            $amazonOrderId = (string)($sourceOrder['marketplace_reference_id'] ?? '');
            $amazonShopId = (string)($sourceOrder['marketplace_shop_id'] ?? '');
            if ($userId === '' || $amazonOrderId === '' || $amazonShopId === '') {
                return;
            }

            // Fetch latest order document to ensure targets are present (important for FBA timing).
            $orderContainer = $this->di->getObjectManager()
                ->create('\App\Core\Models\BaseMongo')
                ->getCollectionForTable('order_container');
            $latest = $orderContainer->findOne(
                [
                    'user_id' => $userId,
                    'object_type' => 'source_order',
                    'marketplace' => 'amazon',
                    'marketplace_shop_id' => $amazonShopId,
                    'marketplace_reference_id' => $amazonOrderId,
                ],
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'projection' => [
                        'user_id' => 1,
                        'marketplace_reference_id' => 1,
                        'marketplace_shop_id' => 1,
                        'source_created_at' => 1,
                        'is_business_order' => 1,
                        'targets' => 1,
                    ]
                ]
            );
            if (!empty($latest)) {
                $sourceOrder = array_merge($sourceOrder, $latest);
            }

            $this->di->getObjectManager()
                ->get(\App\Amazon\Components\Report\VatInvoiceDataReportRequester::class)
                ->requestForAmazonOrder($sourceOrder);
        } catch (\Exception $e) {
            $this->di->getLog()->logContent('Exception From initiateVatInvoiceRequestAfterOrderCreated Function => '
                . json_encode($e->getMessage(), true), 'info', 'exception.log');
        }
    }

    public function updateFBOStatus($sourceOrder, $targetOrder): array
    {
        if (!isset($sourceOrder['fulfilled_by']) || $sourceOrder['fulfilled_by'] != self::FULFILLED_BY_OTHER) {
            return ['success' => false, 'message' => 'source_order is not fulfilled_by_other'];
        }

        if ($sourceOrder['marketplace_status'] != "Shipped") {
            return ['success' => false, 'message' => 'source_order is not shipped'];
        }

        $queryFilter = [
            'user_id' => $sourceOrder['user_id'],
            'object_type' => 'source_order',
            'marketplace' => $sourceOrder['marketplace'],
            'marketplace_shop_id' => $sourceOrder['marketplace_shop_id'],
            'marketplace_reference_id' => $sourceOrder['marketplace_reference_id'],
            'targets.order_id' => $targetOrder['marketplace_reference_id']
        ];

        $updateData = [
            'status' => 'Shipped',
            'targets.$.status' => 'Shipped',
        ];

        $targetQueryFilter = [
            'user_id' => $targetOrder['user_id'],
            'object_type' => 'target_order',
            'marketplace' => $targetOrder['marketplace'],
            'marketplace_shop_id' => $targetOrder['marketplace_shop_id'],
            'marketplace_reference_id' => $targetOrder['marketplace_reference_id']
        ];

        $targetUpdateData = [
            'status' => 'Shipped',
        ];

        $orderContainer = $this->di->getObjectManager()
            ->create('\App\Core\Models\BaseMongo')
            ->getCollectionForTable('order_container');

        $bulkOpArray[] = [
            'updateOne' => [
                $queryFilter,
                ['$set' => $updateData]
            ]
        ];

        $bulkOpArray[] = [
            'updateOne' => [
                $targetQueryFilter,
                ['$set' => $targetUpdateData]
            ]
        ];
        $orderContainer->bulkWrite($bulkOpArray, ['w' => 1]);
        return ['success' => true];
    }

    private function fetchDefaultCurrencyForShop($params): ?string
    {
        if ($this->defaultCurrency !== null) {
            return $this->defaultCurrency;
        }

        if (empty($params['shop_id'])) {
            return null;
        }

        $shopDetails = $this->di->getObjectManager()
            ->get('\App\Core\Models\User\Details')
            ->getShop($params['shop_id'], $params['user_id'] ?? null);

        if (!empty($shopDetails) && isset($shopDetails['currency'])) {
            $this->defaultCurrency = $shopDetails['currency'];
        }

        return $this->defaultCurrency;
    }
}
