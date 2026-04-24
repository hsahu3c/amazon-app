<?php

namespace App\Connector\Components;

use Phalcon\Validation;
use Phalcon\Validation\Validator\Callback;
use App\Connector\Models\Product;

class ImportValidation extends \App\Core\Components\Base
{
  /**
   * Function to check simple or variant product then accordingly
   * call the respective validation function
   *
   * @param array $marketplaceProducts
   */
  public function validateProduct($marketplaceProducts): array
  {
    $allMessage = [];
    $validateResponse = [];
    if (!empty($marketplaceProducts['type']) && $marketplaceProducts['type'] == Product::PRODUCT_TYPE_SIMPLE) :
      $productIndexes = $this->SimpleRequiredField();
    elseif (!empty($marketplaceProducts['type']) && $marketplaceProducts['type'] == Product::PRODUCT_TYPE_VARIANT) :
      $productIndexes = $this->variantRequiredField();
    else :
      $validateResponse = [
        'message' => 'Product type undefined either simple or variant'
      ];
    endif;

    if(!empty($productIndexes)){
      $messages = $this->CallbackValidation($productIndexes, $marketplaceProducts);
      foreach($messages as $message){
        $allMessage[] = $message->getField().' : '.$message->getMessage();
      }
    }

    return !empty($allMessage)?['message'=> implode(' || ',$allMessage)]:$validateResponse;
  }

  /**
   * Required fields of simple product according to connector
   */
  public function SimpleRequiredField(): array
  {
    return array(
      "additional_images" => "Array",
      "app_codes" => "Array",
      "brand" => "String",
      "container_id" => "String",
      "created_at" => "DateTime",
      "currency_code" => "String",
      "default_locale" => "String",
      "description" => "String",
      "id" => "String",
      "low_sku" => "String",
      "marketplace" => 'Array', //array of object
      "shop_id" => "String",
      "sku" => "String",
      "source_marketplace" => "String",
      "source_product_id" => "String",
      "source_sku" => "String",
      "title" => "String",
      "type" => "String",
      "user_id" => "String",
      "variant_attributes" => "Json",
      "visibility" => "String"
    );
  }

  /**
   * Required fields of variant product according to connector
   */
  public function variantRequiredField(): array
  {
    return [
      "_id" => "String",
      "brand" => "String",
      "container_id" => "String",
      "created_at" => "String",
      "currency_code" => "String",
      "default_locale" => "String",
      "description" => "String",
      "id" => "String",
      "low_sku" => "String",
      "main_image" => "String",
      "price" => "String",
      "quantity" => "String",
      "shop_id" => "String",
      "sku" => "String",
      "source_marketplace" => "String",
      "source_product_id" => "String",
      "source_sku" => "String",
      "title" => "String",
      "type" => "String",
      "user_id" => "String",
      "visibility" => "String",
      "variant_attributes" => "Array",
      "marketplace" => "Array",
      "additional_images" => "Array",
      "app_codes" => "Array",
    ];
  }

  /**
   * Function to validate the product array using the Phalcon callback validation
   *
   * @param array $productIndexes
   * @param array $product
   * @return array
   */
  public function CallbackValidation($productIndexes, $product):object
  {
    $validation = new Validation();
    foreach ($productIndexes as $keyIndex => $valueType) :
      $validation->add(
        $keyIndex,
        new Callback(
          [
            'callback' => function (array $value) use ($keyIndex, $valueType) {
              if (isset($value[$keyIndex])) :
                $indexVal = $value[$keyIndex];
                switch ($valueType) {
                  case 'String':
                    if (is_string($indexVal)) {
                        return empty($indexVal) ? is_null($indexVal) : true;
                    }

                    if (is_null($indexVal)) {
                        return true;
                    }

                    return false;
                  case 'Array':
                    return is_array($indexVal);
                  case 'Json':
                    return is_string($indexVal) && is_array(json_decode($indexVal, true)) && (json_last_error() == JSON_ERROR_NONE) ? true : false;
                  case 'DateTime':
                    return strtotime($indexVal);
                }
              else :
                return true;
              endif;
            },
            'message'  => $keyIndex . ' is required to be ' . $valueType
          ]
        )
      );
    endforeach;

    return $validation->validate($product);
  }
}
