<?php
/**
 * Created by PhpStorm.
 * User: cedcoss
 * Date: 16/11/22
 * Time: 5:05 PM
 */

namespace App\Shopifyhome\Components\Product\Webhooks;

use App\Shopifyhome\Components\Core\Common;

class Helper extends Common
{

    public function prepareProductData($sqsData)
    {
        $formattedData = [];
        $productData = $sqsData['data']['product_listing'];
        $fixedProductData = $productData;
        unset($fixedProductData['variants']);
        unset($sqsData['data']);

        $productDataImages = [];
        $additionalImages = [];
        $variantAttributes = [];
        $variantAttributesValue = [];

        $productsMainImage = '';
        if(isset($fixedProductData['images']) && !empty($fixedProductData['images']))
        {
            foreach ($fixedProductData['images'] as $im_value)
            {
                $productDataImages[$im_value['id']] = $im_value;
                if(isset($im_value['variant_ids']) && empty($im_value['variant_ids']))
                {
                    $additionalImages[] = $im_value['src'];
                }

                if(isset($im_value['position']) && $im_value['position'] == 1)
                {
                    $productsMainImage = $im_value['src'];
                }
            }

        }

        /*if(isset($fixedProductData['options']) && !empty($fixedProductData['options']))
        {
            $options = $fixedProductData['options'] ?? [];
            if ($options[0]['name']==='Title' && $options[0]['value']==='Default Title') {
                $variantAttributes = [];
            }
            else {
                foreach ($options as $option) {
                    $variantAttributes[$option['name']] = $option['name'];
                    //$fixedProductData['variant_attributes_values'][ $option['name']] = $option['values'] ?? [];
                }
            }
        }*/
        $fixedProductData['main_image'] = $productsMainImage;
        $fixedProductData['images'] = $productDataImages;
        $fixedProductData['additional_images'] = $additionalImages;
//        $fixedProductData['variant_attributes'] = array_values($variantAttributes);

        if(isset($productData['variants']) && !empty($productData['variants']))
        {
            $variantsInData = $productData['variants'];
            foreach ($variantsInData as $value)
            {
                $existing_variant_db_data = $sqsData['existing_db_data'][$value['id']] ?? [];
                $sqsData['existing_variant_db_data'] = $existing_variant_db_data;
                $formattedData[] = $this->formatData($value, $fixedProductData, $sqsData);
            }
        }

//        print_r($formattedData);die;

        return $formattedData;
    }

    public function formatData($variantData, $productData, $sqsData)
    {
        $userId = $sqsData['user_id'];
        $shopId = $sqsData['shop_id'];
        $requiredKeys = $this->di->getConfig()->get('required_child_fields')->toArray();

        $temp = [];
        /*foreach ($requiredKeys as $key => $keyName)
        {

            if(isset($variantData[$key] ))
            {

                $temp[] = $key;
            }
        }*/

        $prepareArray = [
            'type' => 'simple',
            'shop_id' => (string)$shopId ?? '',
            'source_marketplace' => $sqsData['marketplace'],
            'user_id' => $userId,

            'container_id' => (string)$productData['product_id'],
            'product_type' => $productData['product_type'],
            'published_at' => $productData['published_at'],
            'brand' => $productData['vendor'],
            'description' => $productData['body_html'],
            'handle' => $productData['handle'],
            'title' => $productData['title'],
            'main_image' => $productData['main_image'],

            'source_product_id' => (string)$variantData['id'],
            'id' => (string)$variantData['id'],
            'created_at' => $variantData['created_at'],
            'updated_at' => $variantData['updated_at'],
            'quantity' => $variantData['inventory_quantity'],
            'fulfillment_service' => $variantData['fulfillment_service'],
            'inventory_policy' => $variantData['inventory_policy'],
            'inventory_management' => $variantData['inventory_management'],
            'source_sku' => $variantData['sku'],
            'variant_title' => $variantData['title'],
            'compare_at_price' => $variantData['compare_at_price'],
            'barcode' => $variantData['barcode'],
            'position' => $variantData['position'],
            'requires_shipping' => $variantData['requires_shipping'],
            'taxable' => $variantData['taxable'],
            'price' => (float)number_format($variantData['price'],'2','.',''),

            'tags' => $this->getTags($productData),
            'weight' => $this->getWeight($variantData),
            'weight_unit' => $this->getWeightUnit($variantData),
            'grams' => $this->getWeightInGrams($variantData),
            'variant_attributes' => $this->getVariantAttributesValue($variantData,$productData),
//            'variant_attributes' => $productData['variant_attributes'],
            //'variant_attributes_values' =>  $this->getVariantAttributesValue($variantData,$productData),
            'sales_price' => $this->getSalesPrice($variantData),
            'additional_images' => $this->getAdditionalImages($productData),
            'inventory_item_id' => $this->getInventoryItemId($sqsData['existing_variant_db_data'] ?? []),
            'inventory_tracked' => $this->getInventoryTracked($variantData),
            //'created_at' => '',
            //'updated_at' => '',
            //'marketplace' => '',
            'locations' => $this->getLocations($sqsData['existing_variant_db_data'] ?? []),
            'sku' => $variantData['sku'],
            'low_sku' => strtolower((string) $variantData['sku']),
            'variant_image' => $this->getVariantMainImage($variantData, $productData),

        ];

//        return $prepareArray;
        $prepareVariantAttr = $this->getSpecificVariantAttributes($variantData);

        return array_merge($prepareArray,$prepareVariantAttr);
    }

    public function getSpecificVariantAttributes($data)
    {
        $variantAttributes = [];
        $tempArray = [];

        $optionValues = $data['option_values'] ?? [];
        if(!empty($optionValues))
        {
            foreach ($optionValues as $opValue)
            {
                $optionName = $opValue['name'];
                $optionValue = $opValue['value'];

                if($optionName != 'Title' && $optionValue != 'Default Title')
                {
                    $tempArray[$optionName] = $optionValue;
                }
            }
        }

        return $tempArray;
    }

    public function getTags($data)
    {
        $tags = $data['tags'] ?? '';
        $tags = explode(', ', (string) $tags);
        return $tags;
    }

    public function getWeight($data)
    {
        return $data['weight'] ?? '';
    }

    public function getWeightUnit($data)
    {
        return $data['weight_unit'] ?? '';
    }

    public function getWeightInGrams($data)
    {
        $weight = $data['weight'] ?? '';
        $unit = $data['weight_unit'] ?? '';

        $grams = null;

        switch ($unit) {
            case 'kg':
                $grams = $weight * 1000;
                break;

            case 'g':
                $grams = $weight;
                break;

            case 'lb':
                $grams = $weight * 453.6;
                break;

            case 'oz':
                $grams = $weight * 28.3;
                break;
        }

        return $grams;
    }

    public function getVariantAttributes($productData)
    {
        $variantAttributes = $productData['variant_attributes'] ?? [];

        return $variantAttributes;
    }

    public function getVariantAttributesValue($variant_data, $productData)
    {
        $var_attr = [];
        $option_values = $variant_data['option_values'];
        if(!empty($option_values))
        {
            if ($option_values[0]['name'] !== 'Title' && $option_values[0]['value'] !== 'Default Title')
            {
                foreach ($option_values as $_value)
                {
                    //$var_attr[$_key] = ['key'=>$_value['name'], 'value' => $_value['value']];
                    $var_attr[] = $_value['name'];
                }
            }


        }

        //$variantAttributes = $productData['variant_attributes_values'] ?? [];
//        return $variantAttributes;
        return $var_attr;

    }

    public function getSalesPrice($data)
    {
        return '';
    }

    public function getAdditionalImages($product_data)
    {
        $additional_images = [];
        if(isset($product_data['additional_images']))
        {
            $additional_images = $product_data['additional_images'];
        }

        return $additional_images;
    }

    public function getInventoryItemId($data)
    {
        $inventory_item_id = '';
        if(!empty($data) && (isset($data['inventory_item_id']) && !empty($data['inventory_item_id'])))
        {
            $inventory_item_id = $data['inventory_item_id'];
        }

        return $inventory_item_id;
    }

    public function getInventoryTracked($data)
    {
        if(isset($data['inventory_management']) && $data['inventory_management'] == 'shopify')
        {
            return true;
        }

        return false;
    }

    public function getLocations($data){
        $locations = [];
        if(isset($data['locations']))
        {
            $locations = $data['locations'];
        }

        return $locations;
    }

    public function getVariantMainImage($variant_data, $product_data)
    {
        $main_image = '';
        $image_id = $variant_data['image_id'] ?? '';
        if($image_id != '')
        {
            $imagesArray = $product_data['images'];
            $main_image = $imagesArray[$image_id]['src'];
        }

        return $main_image;
    }
}