<?php


namespace App\Amazon\Components\Listings;

use App\Core\Components\Base;

class Price extends Base
{
    protected $userId;

    protected $priceTemplateData;

    public function getProductPriceDetails($product, $targetShopId, $targetMarketplace, $currencyConvert = null, $settingsOverride = false)
    {
        $userId = $product['user_id'];
        $sourceMarketplace = $product['source_marketplace'];
        $sourceShopId = $product['shop_id'];
        $priceTemplate = $this->getPriceTemplate($product, $userId, $targetShopId);
        if ($currencyConvert == null) { 
          
            $configObj = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
            $connector = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
            $currencyConvert = $connector->checkCurrency($userId, $targetMarketplace, $sourceMarketplace, $targetShopId, $sourceShopId);
            if (!$currencyConvert) {
                $configObj->setGroupCode('currency');
                $configObj->setUserId($userId);
                $configObj->setTarget($targetMarketplace);
                $configObj->setTargetShopId($targetShopId);
                $configObj->setSourceShopId($sourceShopId);
                $sourceCurrency = $configObj->getConfig('source_currency');
                $sourceCurrency = json_decode(json_encode($sourceCurrency, true), true);
                $amazonCurrency = $configObj->getConfig('target_currency');
                $amazonCurrency = json_decode(json_encode($amazonCurrency, true), true);
                if (isset($sourceCurrency[0]['value']) && isset($amazonCurrency[0]['value']) && $sourceCurrency[0]['value'] == $amazonCurrency[0]['value']) {
                    $currencyConvert = true;
                } else {
                    $amazonCurrencyValue = $configObj->getConfig('target_value');
                    $amazonCurrencyValue = json_decode(json_encode($amazonCurrencyValue, true), true);
                    if (isset($amazonCurrencyValue[0]['value'])) {
                        $currencyConvert = $amazonCurrencyValue[0]['value'];
                    }
                }
            }
    }
    $calculatedResponse=$this->di->getObjectManager()->get('\App\Amazon\Components\Product\Price')->calculate($product,$priceTemplate,$currencyConvert,true);
    return $calculatedResponse;
    //     $price = 0;
    //     $key = null;
    //     $editPriceRules = [];
    //     if (isset($product['edited']['price_rules']) && !empty($product['edited']['price_rules']) && is_array($product['edited']['price_rules'])) {
    //         $editPriceRules = $product['edited']['price_rules'];
    //     } elseif (!empty($product['parent_details']['edited']['price_rules']) && (is_array($product['parent_details']['edited']['price_rules']))) {
    //         $editPriceRules = $product['parent_details']['edited']['price_rules'];
    //     }

    //     if (!empty($product['edited']['price'])) {
    //         $price = $product['edited']['price'];
    //     } elseif (!empty($product['offer_price'])) {
    //         $price = $product['offer_price'];
    //     } elseif (!empty($product['price'])) {
    //         $price = $product['price'];
    //     }

    //     if (isset($product['edited']['price']) || !empty($editPriceRules)) {
    //         $key = null;
    //     } elseif (isset($product['offer_price'])) {
    //         $key = 'offer_price';
    //     } elseif (isset($product['price'])) {
    //         $key = 'price';
    //     }
    //     if (!empty($editPriceRules)) {
    //         $price = $product['price'] ?? 0;
    //         // $standardPrice = $product['price'] ?? 0;
    //         $standardPriceAttribute = $editPriceRules['attribute'] ?? false;
    //         if ($standardPriceAttribute != 'default') {
    //             $standardPriceChangeValue = $editPriceRules['value'];
    //             $price = $this->changePrice($price, $standardPriceAttribute, $standardPriceChangeValue);
    //             if (isset($editPriceRules['price_roundoff_value'])) {
    //                 $price = $this->priceRoundOff($price, $editPriceRules['price_roundoff_value']);
    //             }
    //         }
    //     }

    //     if (!is_null($key) && !empty($this->di->getConfig()->get('metafield_sync_user_feature')) && (!empty($product['parentMetafield']) || !empty($product['variantMetafield']))) {
    //         $metafieldSyncConfig = $this->di->getConfig()->get('metafield_sync_user_feature')->toArray();
    //         $metafieldType = isset($product['variantMetafield']) ? 'variantMetafield' : 'parentMetafield';
    //         if (isset($metafieldSyncConfig[$userId][$metafieldType][$key]) && $this->isMetafieldSyncEnable($userId, $product['shop_id'])) {
    //             $syncKey = $metafieldSyncConfig[$userId][$metafieldType][$key] ?? null;
    //             isset($product[$metafieldType][$syncKey]['value']) && $price = $product[$metafieldType][$syncKey]['value'];
    //         }
    //     }

    //     $customPriceSyncRules = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Price')->getPriceSyncRulesConfig($userId, $sourceShopId, $targetShopId);
    //     $applySettings = true;
    //     $customSalePrice = false;
    //     $customPriceRes = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Price')->getCustomPriceValues($product, $priceTemplate, $customPriceSyncRules);
    //     if (isset($customPriceRes['success']) && $customPriceRes['success']) {
    //         if (isset($customPriceRes['data']['custom_standard_price']) && !empty($customPriceRes['data']['custom_standard_price'])) {
    //             $price = $customPriceRes['data']['custom_standard_price'];
    //             if (!empty($editPriceRules)) {
    //                 $standardPriceAttribute = $editPriceRules['attribute'] ?? false;
    //                 if ($standardPriceAttribute != 'default') {
    //                     $standardPriceChangeValue = $editPriceRules['value'];
    //                     $price = $this->changePrice($price, $standardPriceAttribute, $standardPriceChangeValue);
    //                     if (isset($editPriceRules['price_roundoff_value'])) {
    //                         $price = $this->priceRoundOff($price, $editPriceRules['price_roundoff_value']);
    //                     }
    //                 }
    //             }
    //         }
    //         (isset($customPriceRes['data']['custom_sale_price']) && !empty($customPriceRes['data']['custom_sale_price'])) && $customSalePrice = $customPriceRes['data']['custom_sale_price'];
    //         (isset($customPriceRes['data']['apply_settings'])) && $applySettings = $customPriceRes['data']['apply_settings'];
    //         (isset($customPriceRes['data']['custom_start']) && !empty($customPriceRes['data']['custom_start'])) && $saleStartDate = $customPriceRes['data']['custom_start'];
    //         (isset($customPriceRes['data']['custom_end']) && !empty($customPriceRes['data']['custom_end'])) && $saleEndDate = $customPriceRes['data']['custom_end'];
    //     }
    //     $standardPrice = $price;
    //     $returnResult['StandardPrice'] = $standardPrice;
    //     if ($applySettings && (!isset($priceTemplate['settings_enabled']) || !$priceTemplate['settings_enabled'])) {
    //         // template price settings found disabled
    //         if (!$settingsOverride) {
    //             // called for price-sync
    //             return ['success' => false, 'message' => 'Template price-settings are disabled.'];
    //         }
    //         // called for product-upload / product-sync -> need standard_price
    //         return $returnResult;
    //     }

    //     // standard price
    //     if ($applySettings && isset($priceTemplate['standard_price']['attribute']) && empty($product['edited']['price']) && empty($editPriceRules)) {
    //         if ($priceTemplate['standard_price']['attribute'] !== 'default') {
    //             $standardPrice = $this->changePrice($standardPrice, $priceTemplate['standard_price']['attribute'], $priceTemplate['standard_price']['value']);
    //         }
    //         if ($standardPrice < 0) {
    //             $standardPrice = 0;
    //         }

    //     }
    //     $amazonProduct = $product['edited']??[];
    //     if ((!isset($amazonProduct['business_price_enabled']) || (isset($amazonProduct['business_price_enabled']) && !$amazonProduct['business_price_enabled'])) && isset($product['parent_details']['edited']['business_price_enabled']) && $product['parent_details']['edited']['business_price_enabled'] && !empty($product['parent_details']['edited']['business_price'])) {
    //         $amazonProduct['business_price_enabled'] = $product['parent_details']['edited']['business_price_enabled'];
    //         $amazonProduct['business_price'] = $product['parent_details']['edited']['business_price'];
    //     }
    //     if($applySettings && isset($amazonProduct['business_price_enabled'])&& $amazonProduct['business_price_enabled'] && !empty($amazonProduct['business_price']))
    //     {
    //         $businessPriceEnabled = $amazonProduct['business_price_enabled'];
    //             $businessPriceMapping = $amazonProduct['business_price'];
    //             $businessPrice = $standardPrice; 
    //             $businessPriceAttribute = $businessPriceMapping['attribute'];
    //             if(isset($businessPriceMapping['selection_type'])&& $businessPriceMapping['selection_type'] != "default")
    //             {
    //                 $businessPriceChangeValue = $businessPriceMapping['value'];
    //                 $businessPrice = $this->changePrice($businessPrice, $businessPriceAttribute, $businessPriceChangeValue);
    //         }

    //         if ($businessPrice < 0) {
    //             $businessPrice = 0;
    //         }
    //         if($currencyConvert){
    //             $businessPrice = $currencyConvert*$businessPrice;
    //         }
    //         $businessPrice =  number_format((float)$businessPrice, 2, '.', '');
    //         $businessPrice=isset($businessPriceMapping['price_roundoff_value']) ? $this->priceRoundOff($businessPrice,$businessPriceMapping['price_roundoff_value']):$businessPrice;
    //         $returnResult['businessPrice'] = (float)$businessPrice;
    //         if(isset($businessPriceMapping['quantity_discount']) && isset($businessPriceMapping['quantity_type']) ){
    //             $quantityPriceType = $businessPriceMapping['quantity_type'];
    //             $quantity = [];
    //             $quantityDiscount = $businessPriceMapping['quantity_discount'];
    //             if($quantityPriceType == "fixed"){
    //                 foreach($quantityDiscount as $key => $value){
    //                     if(!empty($value['price'])){
    //                         $quantity[$key]['lower_bound'] = (int) $value['quantity'];
    //                         $bp =  number_format((float)$businessPrice, 2, '.', '');
                            
    //                         $quantity[$key]['value'] = (float)($bp - $value['price']); 

    //                     }
    //                 }
    //             }
    //             else{
    //                 foreach($quantityDiscount as $key => $value){
    //                     if(!empty($value['price'])){
    //                         $quantity[$key]['lower_bound'] = (int) $value['quantity'];
                            
    //                         $quantity[$key]['value'] = (float)$value['price']; 

    //                     }
    //                 }
    //                 // $quantity = $quantityDiscount;
    //             }
    //             $returnResult['quantity'] = $quantity;
    //             $returnResult['quantityPriceType'] = $quantityPriceType;
    //         }
    //     }
    //     else{
    //         $businessPriceEnabled = $priceTemplate['settings_selected']['business_price'] ?? false;
    //         if ($applySettings && $businessPriceEnabled) {
    //         $businessPriceMapping = $priceTemplate['business_price'];
    //         $businessPrice = $standardPrice;
    //         $businessPriceAttribute = $businessPriceMapping['attribute'];
    //         $quantityPriceType = $businessPriceMapping['quantity_type']??[];
    //         // $quantity = $businessPriceMapping['quantity_discount']??[];
    //         $quantity = [];
    //         $quantityDiscount = $businessPriceMapping['quantity_discount']??[];
    //         if ($businessPriceAttribute != 'default') {
    //             $businessPriceChangeValue = $businessPriceMapping['value'];
    //             $businessPrice = $this->changePrice($businessPrice, $businessPriceAttribute, $businessPriceChangeValue);
    //         }

    //         if ($businessPrice < 0) {
    //             $businessPrice = 0;
    //         }
    //         if($currencyConvert){
    //             $businessPrice = $currencyConvert*$businessPrice;
    //         }
    //         $businessPrice =  number_format((float)$businessPrice, 2, '.', '');
    //         $businessPrice=isset($businessPriceMapping['price_roundoff_value']) ? $this->priceRoundOff($businessPrice,$businessPriceMapping['price_roundoff_value']):$businessPrice;
    //         $returnResult['businessPrice'] = (float)$businessPrice;
    //         if(!empty($quantityPriceType)){

    //             if($quantityPriceType == "fixed"){
    //                 foreach($quantityDiscount as $key => $value){
    //                     if(isset($value['price'])){
    //                         $quantity[$key]['lower_bound'] = (int) $value['quantity'];
    //                     $bp =  number_format((float)$businessPrice, 2, '.', '');
                      
    //                     $quantity[$key]['value'] = (float)($bp - $value['price']); 
                        
    //                 }
    //             }
    //         }
    //         else{
    //             foreach($quantityDiscount as $key => $value){
    //                 if(!empty($value['price'])){
    //                     $quantity[$key]['lower_bound'] = (int) $value['quantity'];
                        
    //                     $quantity[$key]['value'] = (float)$value['price']; 

    //                 }
    //             }
    //         }
            
    //         $returnResult['quantity'] = $quantity;
    //         $returnResult['quantityPriceType'] = $quantityPriceType;
    //     }
    //     }
    // }

    //     if ($applySettings && !$customSalePrice && isset($priceTemplate['settings_selected']['sale_price']) && $priceTemplate['settings_selected']['sale_price']) {
    //         // sale price
    //         if (isset($priceTemplate['sale_price']['attribute'])) {
    //             $salePrice = $standardPrice;
    //             if ($priceTemplate['sale_price']['attribute'] !== 'default') {
    //                 $salePrice = $this->changePrice($salePrice, $priceTemplate['sale_price']['attribute'], $priceTemplate['sale_price']['value']);
    //             }
    //         }
    //     }

    //     if ($applySettings && isset($priceTemplate['settings_selected']['minimum_price']) && $priceTemplate['settings_selected']['minimum_price']) {
    //         // minimum_price
    //         if (isset($priceTemplate['minimum_price']['attribute'])) {
    //             $minimumPrice = $standardPrice;
    //             if ($priceTemplate['minimum_price']['attribute'] !== 'default') {
    //                 $minimumPrice = $this->changePrice($minimumPrice, $priceTemplate['minimum_price']['attribute'], $priceTemplate['minimum_price']['value']);
    //             }
    //             if($this->di->getUser()->id == "660d12d2c57df226430244c3" && $priceTemplate['minimum_price']['attribute'] == "default" && isset($product['price'])){
    //                 $minimumPrice = $product['price'];
    //             }
    //             if ($minimumPrice < 0) {
    //                 $minimumPrice = 0;
    //             }
    //         }
    //     }
    //     if($standardPrice){
    //         if ($currencyConvert) {
    //             $standardPrice = (float)$currencyConvert * $standardPrice;
    //         }
    //         $standardPrice = number_format((float)$standardPrice, 2, '.', '');
    //         if ($applySettings && !empty($priceTemplate['standard_price']['price_roundoff_value'])) {
    //              $standardPrice = $this->priceRoundOff($standardPrice, $priceTemplate['standard_price']['price_roundoff_value']);
    //          }
    //         $returnResult['StandardPrice'] = $standardPrice;
    //     }
    //     if($minimumPrice){
    //         if ($currencyConvert) {
    //             $minimumPrice = (float)$currencyConvert * $minimumPrice;
    //         }
    //         $minimumPrice = number_format((float)$minimumPrice, 2, '.', '');
    //         if (!empty($priceTemplate['minimum_price']['price_roundoff_value'])) {
    //             $minimumPrice = $this->priceRoundOff($minimumPrice, $priceTemplate['minimum_price']['price_roundoff_value']);
    //         }
    //         $returnResult['MinimumPrice'] = (float)$minimumPrice;
    //     }
    //     if($salePrice){
    //         if ($currencyConvert) {
    //             $salePrice = (float)$currencyConvert * $salePrice;
    //         }
    //         $salePrice = number_format((float)$salePrice, 2, '.', '');
    //         if (!empty($priceTemplate['sale_price']['price_roundoff_value'])) {
    //             $salePrice = $this->priceRoundOff($salePrice, $priceTemplate['sale_price']['price_roundoff_value']);
    //         }

    //         if ($salePrice < 0) {
    //             $salePrice = 0;
    //         } else {
    //             $saleStartDate = date('Y-m-d', strtotime((string) $priceTemplate['sale_price']['start_date']));
    //             $saleEndDate = date('Y-m-d', strtotime((string) $priceTemplate['sale_price']['end_date']));
    //             $sale = [
    //                 'price' => (float)$salePrice,
    //                 'startDate' => $saleStartDate,
    //                 'endDate' => $saleEndDate
    //             ];
    //             $returnResult['sale'] = $sale;
    //         }
    //         }
    //         if ($customSalePrice) {
    //             if ($currencyConvert) {
    //                 $customSalePrice = (float)$currencyConvert * $customSalePrice;
    //             }
    //             $customSalePrice = number_format((float)$customSalePrice, 2, '.', '');
    //             if ($applySettings && isset($priceTemplate['sale_price']['price_roundoff_value'])) {
    //                 $customSalePrice = $this->priceRoundOff($customSalePrice, $priceTemplate['sale_price']['price_roundoff_value']);
    //             }
    //             $returnResult['sale'] = [
    //                 'price' => (float)$customSalePrice,
    //                 'startDate' => $saleStartDate,
    //                 'endDate' => $saleEndDate
    //             ];
    //         }
    //     return $returnResult;
    }

    public function getPriceTemplate(&$product, $userId, $targetShopId)
    {
        $priceTemplateData = [];
        if (!empty($product['profile_info']['data'])) {
            foreach ($product['profile_info']['data'] as $value) {
                if ($value['data_type'] == 'pricing_settings') {
                    $priceTemplateData = $value['data'];
                }
            }
        }

        $helper = $this->di->getObjectManager()->get(Helper::class);
        if (empty($priceTemplateData)) {
            $priceConfig = $helper->getConfigObject('price', $userId, $targetShopId);
            if (!empty($priceConfig)) {
                foreach ($priceConfig as $value) {
                    $priceTemplateData[$value['key']] = $value['value'];
                }
            }
        }

        if (empty($priceTemplateData)) {
            $priceConfig = $helper->getConfigObject('price');
            foreach ($priceConfig as $value) {
                $priceTemplateData[$value['key']] = $value['value'];
            }
        }

        return $priceTemplateData;
    }

    public function priceRoundOff($initialPrice, $action)
    {
        $price = (float)$initialPrice;
        switch ($action) {
            case 'higherEndWith9':
                $initialPrice = ceil($initialPrice);
                $price = (int)($initialPrice - $initialPrice % 10) + 9;
                break;
            case 'lowerEndWith9':
                $initialPrice = ceil($initialPrice);
                $price = (int)($initialPrice - $initialPrice % 10) - 1;
                break;
            case 'higherWholeNumber':
                $price = (int)$initialPrice + 1;
                break;
            case 'higherEndWith0.49':
                $initialPrice = (int)$initialPrice;
                $price = (float)$initialPrice + 0.49;
                break;
            case 'lowerEndWith0.49':
                $initialPrice = (int)$initialPrice;
                $price = (float)$initialPrice - 0.51;
                break;
            case 'lowerWholeNumber':
                $price = (int)$initialPrice;
                $price = $price - 1;
                break;
            case 'higherEndWith0.99':
                $price = (int)$initialPrice;
                $price = $price + 0.99;
                break;
            case 'lowerEndWith0.99':
                $price = (int)$initialPrice;
                $price = $price - 0.01;
                break;
            case 'higherEndWith10':
                $initialPrice = ceil($initialPrice);
                $price = (int)($initialPrice - $initialPrice % 10) + 10;
                break;
            case 'lowerEndWith10':
                $initialPrice = ceil($initialPrice);
                $price = (int)($initialPrice - $initialPrice % 10);
                if (!($initialPrice % 10)) {
                    $price = $price - 10;
                }

                break;
            default:
                $price = $initialPrice;
                break;
        }

        if ($price < 0) {
            $price = $initialPrice;
        }

        return $price;
    }

    public function changePrice($price, $action, $changeValue)
    {
        switch ($action) {
            case 'value_increase':
                $price = $price + $changeValue;
                break;
            case 'value_decrease':
                $price = $price - $changeValue;
                break;
            case 'percentage_increase':
                $price = $price + (($changeValue / 100) * $price);
                break;
            case 'percentage_decrease':
                $price = $price - (($changeValue / 100) * $price);
                break;
            case 'custom':
                $price = $changeValue;
                break;
            default:
                return $price;
        }

        return $price;
    }

    public function isMetafieldSyncEnable($userId, $sourceShopId)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $config = $mongo->getCollectionForTable('config');
        $query = [
            'group_code' => 'product',
            'key' => 'metafield',
            'value' => true,
            'user_id' => $userId,
            'source_shop_id' => $sourceShopId
        ];
        return $config->countDocuments($query);
    }
}