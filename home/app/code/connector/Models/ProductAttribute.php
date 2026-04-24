<?php

namespace App\Connector\Models;

use App\Core\Models\Base;
use Phalcon\Mvc\Model\Query;

class ProductAttribute extends Base
{
    protected $table = 'product_attribute';

    const IS_EQUAL_TO = 1;

    const IS_NOT_EQUAL_TO = 2;

    const IS_CONTAINS = 3;

    const IS_NOT_CONTAINS = 4;

    const START_FROM = 5;

    const END_FROM = 6;

    const RANGE = 7;

    /**
     * error in attribute saving
     *
     * @var []
     */
    public $errors = [];

    public function initialize(): void
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDb());
    }

    /**
     * @param $data
     * @return array
     */
    public function createAttribute($data)
    {
        if ($data && isset($data['code'])) {
            $attributeCode = strtolower($data['code']);
            $merchantId = $this->di->getUser()->id;
            $merchantIds = [1, $merchantId];
            $attributes = self::findFirst(["code='" . $attributeCode . "' AND merchant_id IN ({ids:array})", 'bind'=> ['ids' => $merchantIds]]);
            if (!$attributes) {
                $attribute = new ProductAttribute();
                $attribute->code = $attributeCode;
                $attribute->label = isset($data['label']) ? $data['label'] : ucfirst($attributeCode);
                $attribute->frontend_type = isset($data['frontend_type']) ? $data['frontend_type'] : 'textfield';
                $attribute->is_required = isset($data['is_required']) ? $data['is_required'] : 0;
                $attribute->is_unique = isset($data['is_unique']) ? $data['is_unique'] : 0;
                $attribute->apply_on = isset($data['apply_on']) ? $data['apply_on'] : 'base';
                $attribute->merchant_id = $merchantId;
                $attribute->source_model = isset($data['source_model']) ? $data['source_model'] : '';
                $attribute->is_config = isset($data['is_config']) ? $data['is_config'] : 0;
                $status = $attribute->save();
                if ($status === false) {
                    $error = [];
                    foreach ($attribute->getMessages() as $message) {
                        $error[] = $message->getMessage();
                    }

                    return [
                        'success' => false,
                        'code' => 'something_wrong',
                        'message' => 'Something went wrong',
                        'data' => $error
                    ];
                }
                $optionType = ['dropdown', 'checkbox', 'radio', 'multiselect'];
                if (in_array($data['frontend_type'], $optionType) && isset($data['options'])) {
                    foreach ($data['options'] as $option) {
                        if (isset($option['value'])) {
                            $attributeOpt = new ProductAttributeOption();
                            $attributeOpt->attribute_id = $attribute->id;
                            $attributeOpt->value = $option['value'];
                            $attributeOpt->save();
                        }
                    }
                }

                return [
                    'success' => true,
                    'message' => 'Attribute Created Successfully',
                    'data' => [$attribute->id]
                ];
            }
            return [
                'success' => false,
                'code' => 'code_already_exist',
                'message' => 'Code Already Exist',
                'data' => []
            ];
        }
        return [
            'success' => false,
            'code' => 'invalid_data',
            'message' => 'Invalid Data',
            'data' => []
        ];
    }

    /**
     * @param $data
     * @return array
     */
    public function updateAttribute($data)
    {
        if ($data && isset($data['id'])) {
            $attribute = self::findFirst($data['id']);
            if ($attribute) {
                $attribute->label = isset($data['label']) ? $data['label'] : ucfirst($data['code']);
                $attribute->is_required = isset($data['is_required']) ? $data['is_required'] : 0;
                $attribute->is_unique = isset($data['is_unique']) ? $data['is_unique'] : 0;
                $attribute->is_config = isset($data['is_config']) ? $data['is_config'] : 0;
                $status = $attribute->save();
                if ($status === false) {
                    $error = [];
                    foreach ($attribute->getMessages() as $message) {
                        $error[] = $message->getMessage();
                    }

                    return [
                        'success' => false,
                        'code' => 'something_wrong',
                        'message' => 'Something went wrong',
                        'data' => $error
                    ];
                }
                if (isset($data['options'])) {
                    foreach ($data['options'] as $option) {
                        if (isset($option['id'])) {
                            $attributeOpt = ProductAttributeOption::findFirst(
                                ["attribute_id='{$data['id']}' and id='{$option['id']}'"]
                            );
                            if ($attributeOpt && $attributeOpt->value != $option['value']) {
                                $attributeOpt->value = $option['value'];
                                $attributeOpt->save();
                            }
                        } else {
                            $attributeOpt = new ProductAttributeOption();
                            $attributeOpt->value = $option['value'];
                            $attributeOpt->attribute_id = $data['id'];
                            $attributeOpt->save();
                        }
                    }
                }

                return [
                    'success' => true,
                    'message' => 'Attribute Updated Successfully',
                    'data' => [$attribute->id]
                ];
            }
            return [
                'success' => false,
                'code' => 'attribute_id_does_not_exist',
                'message' => 'Attribute id does not exist',
                'data' => []
            ];
        }
        return [
            'success' => false,
            'code' => 'invalid_data',
            'message' => 'Invalid Data',
            'data' => []
        ];
    }

    /**
     * @param $data
     * @return array
     */
    public function deleteAttribute($data)
    {
        if ($data && isset($data['id'])) {
            $merchantId = $this->di->getUser()->id;
            $attribute = self::findFirst(["id='" . $data['id'] . "' AND merchant_id='" . $merchantId . "'"]);
            if ($attribute) {
                $deleteStatus = $attribute->delete();
                if ($deleteStatus === false) {
                    $errors = [];
                    foreach ($attribute->getMessages() as $message) {
                        $errors[] = $message->getMessage();
                    }

                    return [
                        'success' => false,
                        'code' => 'error_in_deleting',
                        'message' => 'Error occur in attribute deleting',
                        'data' => $errors
                    ];
                }
                return [
                    'success' => true,
                    'message' => 'Attribute Deleted Successfully',
                    'data' => []
                ];
            }
            return [
                'success' => false,
                'code' => 'attribute_id_does_not_exist',
                'message' => 'Attribute id does not exist',
                'data' => []
            ];
        }
        return [
            'success' => false,
            'code' => 'invalid_data',
            'message' => 'Invalid Data',
            'data' => []
        ];
    }

    /**
     * @param $data
     * @return array
     */
    public function getAttributeOptions($data)
    {
        if ($data && isset($data['id'])) {
            $attributeOpt = ProductAttributeOption::find(["attribute_id='{$data['id']}'"])->toArray();
            return [
                'success' => true,
                'message' => 'Attribute Option data',
                'data' => $attributeOpt
            ];
        }
        return [
            'success' => false,
            'code' => 'invalid_data',
            'message' => 'Invalid Data',
            'data' => []
        ];
    }

    /**
     * @param $data
     * @return array
     */
    public function deleteAttributeOption($data)
    {
        if ($data && isset($data['id'])) {
            $merchantId = $this->di->getUser()->id;
            $attributeOpt = ProductAttributeOption::findFirst($data['id']);
            if ($attributeOpt) {
                $attribute = self::findFirst(["id='" . $attributeOpt->attribute_id . "' AND merchant_id='" . $merchantId . "'"]);
                if (!$attribute) {
                    return [
                        'success' => false,
                        'code' => 'option_not_belong',
                        'message' => 'Option does not belong to merchant',
                        'data' => []
                    ];
                }

                $deleteStatus = $attributeOpt->delete();
                if ($deleteStatus === false) {
                    $errors = [];
                    foreach ($attributeOpt->getMessages() as $message) {
                        $errors[] = $message->getMessage();
                    }

                    return [
                        'success' => false,
                        'code' => 'error_in_deleting',
                        'message' => 'Error occur in option deleting',
                        'data' => $errors
                    ];
                }
                return [
                    'success' => true,
                    'message' => 'Option Deleted Successfully',
                    'data' => []
                ];
            }
            return [
                'success' => false,
                'code' => 'option_does_not_exist',
                'message' => 'Option does not exist',
                'data' => []
            ];
        }
        return [
            'success' => false,
            'code' => 'invalid_data',
            'message' => 'Invalid Data',
            'data' => []
        ];
    }

    public function getProductForm()
    {
        $metadata = $this->getModelsMetaData();
        $containerModel = new ProductContainer();
        $variantModel = new Product();
        $containerColumns = $metadata->getAttributes($containerModel);
        $variantColumns = $metadata->getAttributes($variantModel);
        $merchantId = $this->di->getUser()->id;

        $containerField = ['id' => 1, 'group' => 'Basic Product Details'];
        foreach ($containerColumns as $value) {
            if (in_array($value, ['id', 'source_product_id', 'merchant_id', 'type', 'profile_id', 'created_at', 'updated_at'])) {
                continue;
            }
            $label = ucwords(str_replace('_', ' ', $value));
            switch ($value) {
                case 'short_description':
                case 'long_description':
                    $containerField['formJson'][] = [
                        'attribute' => $label,
                        'key' => $value,
                        'field' => 'textarea',
                        'data' => [
                            "type" => "text",
                            "value" => "",
                            "placeholder" => $label,
                            "required" => true
                        ]
                    ];
                    break;
                case 'title':
                    $containerField['formJson'][] = [
                        'attribute' => $label,
                        'key' => $value,
                        'field' => 'textfield',
                        'data' => [
                            "type" => "text",
                            "value" => "",
                            "placeholder" => $label,
                            "required" => true
                        ]
                    ];
                    break;
            }
        }

        $variantFields = [];
        foreach ($variantColumns as $variantColumnValue) {
            if (in_array($variantColumnValue, ['id', 'product_id', 'source_variant_id', 'created_at', 'updated_at'])) {
                continue;
            }
            $label = ucwords(str_replace('_', ' ', $variantColumnValue));
            switch ($variantColumnValue) {
                case 'mpn':
                case 'sku':
                case 'asin':
                    $variantFields[] = [
                        'key' => $variantColumnValue,
                        'value' => '',
                        'title' => $label,
                        'required_group' => true,
                        'type' => 'text',
                        'hasOptions' => false
                    ];
                    break;
                case 'upc':
                case 'gtin':
                case 'isbn':
                case 'ean':
                    $variantFields[] = [
                        'key' => $variantColumnValue,
                        'value' => 0,
                        'title' => $label,
                        'required_group' => true,
                        'type' => 'text',
                        'hasOptions' => false
                    ];
                    break;                   
                case 'main_image':
                case 'additional_images':
                    $variantFields[] = [
                        'key' => $variantColumnValue,
                        'value' => '',
                        'title' => $label,
                        'required_group' => false,
                        'type' => 'text',
                        'hasOptions' => false
                    ];
                    break;
                case 'price':
                case 'weight':
                case 'position':
                    $variantFields[] = [
                        'key' => $variantColumnValue,
                        'value' => '',
                        'title' => $label,
                        'required_group' => false,
                        'type' => 'number',
                        'hasOptions' => false,
                    ];
                    break;
                case 'quantity':
                    $variantFields[] = [
                        'key' => $variantColumnValue,
                        'value' => 0,
                        'title' => $label,
                        'required_group' => false,
                        'type' => 'number',
                        'hasOptions' => false,
                        'disable' => true
                    ];
                    $warehouses = Warehouse::find(["merchant_id ='".$merchantId."'"]);
                    foreach ($warehouses as $warehouse) {
                        $variantFields[] = [
                            'key' => 'warehouse_qty_'.$warehouse->id,
                            'value' => 0,
                            'title' => 'Qty for Warehouse '.$warehouse->name,
                            'required_group' => false,
                            'type' => 'number',
                            'hasOptions' => false,
                            'warehouse_qty' => true
                        ];
                    }

                    break;
                case 'weight_unit':
                    $variantFields[] = [
                        'key' => $variantColumnValue,
                        'value' => '',
                        'title' => $label,
                        'required_group' => false,
                        'hasOptions' => true,
                        'options' => [
                            0 => 'gms',
                            1 => 'kg',
                            2 => 'lbs'
                        ]
                    ];
                    break;
            }
        }

        $optionsTable = new \App\Connector\Models\ProductAttributeOption;
        $varrAttrFields = ['id' => 2, 'group' => 'Basic variant attribute fields'];
        $varrAttrFields['formJson'] = [];
        $variantAttr = self::find(["merchant_id='" . $merchantId . "'"])->toArray();

        foreach ($variantAttr as $varainAttrValue) {
            switch ($varainAttrValue['frontend_type']) {
                case 'checkbox':
                    $options = $optionsTable::find(["attribute_id='{$varainAttrValue['id']}'"]);
                    $attrOptions = [];
                    foreach ($options->toArray() as $optionValue) {
                        $attrOptions[] = ['id' => $optionValue['value'], 'value' => $optionValue['value']];
                    }

                    array_push($varrAttrFields['formJson'], [
                        'attribute' => $varainAttrValue['label'],
                        'key' => $varainAttrValue['code'],
                        'field' => $varainAttrValue['frontend_type'],
                        'data' => [
                            "value" => [],
                            "values" => $attrOptions,
                            "required" => $varainAttrValue['is_required']
                        ]
                    ]);
                    break;
                case 'dropdown':
                    $options = $optionsTable::find(["attribute_id='{$varainAttrValue['id']}'"]);
                    $attrOptions = [];
                    foreach ($options->toArray() as $optionValue) {
                        $attrOptions[] = ['id' => $optionValue['value'], 'value' => $optionValue['value']];
                    }

                    array_push($varrAttrFields['formJson'], [
                        'attribute' => $varainAttrValue['label'],
                        'key' => $varainAttrValue['code'],
                        'field' => $varainAttrValue['frontend_type'],
                        'data' => [
                            "value" => "",
                            "values" => $attrOptions,
                            "required" => $varainAttrValue['is_required']
                        ]
                    ]);
                    break;
                case 'fileupload':
                    array_push($varrAttrFields['formJson'], [
                        'attribute' => $varainAttrValue['label'],
                        'key' => $varainAttrValue['code'],
                        'field' => $varainAttrValue['frontend_type'],
                        'data' => [
                            "value" => "",
                            "required" => $varainAttrValue['is_required']
                        ]
                    ]);
                    break;
                case 'multipleinputs':
                    $options = $optionsTable::find(["attribute_id='{$varainAttrValue['id']}'"]);
                    $attrOptions = [];
                    foreach ($options->toArray() as $optionValue) {
                        $attrOptions[] = ['id' => $optionValue['value'], 'value' => $optionValue['value']];
                    }

                    array_push($varrAttrFields['formJson'], [
                        'attribute' => $varainAttrValue['label'],
                        'key' => $varainAttrValue['code'],
                        'field' => $varainAttrValue['frontend_type'],
                        'data' => [
                            "value" => $attrOptions,
                            "required" => $varainAttrValue['is_required']
                        ]
                    ]);
                    break;
                case 'radio':
                    $options = $optionsTable::find(["attribute_id='{$varainAttrValue['id']}'"]);
                    $attrOptions = [];
                    foreach ($options->toArray() as $optionValue) {
                        $attrOptions[] = ['id' => $optionValue['value'], 'value' => $optionValue['value']];
                    }

                    array_push($varrAttrFields['formJson'], [
                        'attribute' => $varainAttrValue['label'],
                        'key' => $varainAttrValue['code'],
                        'field' => $varainAttrValue['frontend_type'],
                        'data' => [
                            "value" => "",
                            "display" => "inline",
                            "values" => $attrOptions,
                            "required" => $varainAttrValue['is_required']
                        ]
                    ]);
                    break;
                case 'textarea':
                    array_push($varrAttrFields['formJson'], [
                        'attribute' => $varainAttrValue['label'],
                        'key' => $varainAttrValue['code'],
                        'field' => $varainAttrValue['frontend_type'],
                        'data' => [
                            "value" => "",
                            "placeholder" => $varainAttrValue['label'],
                            "required" => $varainAttrValue['is_required'] ,
                            "maxLength" => 1000
                        ]
                    ]);
                    break;
                case 'textfield':
                    $fieldType = '';
                    if ($varainAttrValue['backend_type'] == 'varchar' || $varainAttrValue['backend_type'] == 'text') {
                        $fieldType = 'text';
                    } else {
                        $fieldType = 'number';
                    }

                    array_push($varrAttrFields['formJson'], [
                        'attribute' => $varainAttrValue['label'],
                        'key' => $varainAttrValue['code'],
                        'field' => $varainAttrValue['frontend_type'],
                        'data' => [
                            "type" => $fieldType,
                            "value" => "",
                            "placeholder" => $varainAttrValue['label'],
                            "required" => $varainAttrValue['is_required']
                        ]
                    ]);
                    break;
                case 'toggle':
                    array_push($varrAttrFields['formJson'], [
                        'attribute' => $varainAttrValue['label'],
                        'key' => $varainAttrValue['code'],
                        'field' => $varainAttrValue['frontend_type'],
                        'data' => [
                            "value" => "",
                            "required" => $varainAttrValue['is_required']
                        ]
                    ]);
                    break;
                case 'calendar':
                    array_push($varrAttrFields['formJson'], [
                        'attribute' => $varainAttrValue['label'],
                        'key' => $varainAttrValue['code'],
                        'field' => $varainAttrValue['frontend_type'],
                        'data' => [
                            "value" => "",
                            "required" => $varainAttrValue['is_required'] ,
                            "minYear" => 1970,
                            "maxYear" => 2045,
                            "displayFormat" => "DD-MM-YYYY"
                        ]
                    ]);
                    break;
            }
        }

        $configAttributes = [];
        $configAttributes = self::find(["columns" => "code", "merchant_id='" . $merchantId . "' AND is_config='1'"])->toArray();
        foreach ($configAttributes as $val) {
            $configAttributes[] = $val['code'];
        }

        $responseArray = [
            'product_form' => [$containerField],
            'config_attributes' => $variantFields,
            'variant_attributes' => $configAttributes
        ];
        if ($varrAttrFields['formJson'] !== []) {
            $responseArray['product_form'][] = $varrAttrFields;
        }

        return ['success' => true, 'message' => 'Product Form Data', 'data' => $responseArray];
    }

    /**
     * @param array $filterParams
     * @param null $fullTextSearchColumns
     * @return string
     */
    public static function search($filterParams = [], $fullTextSearchColumns = null)
    {
        $conditions = [];
        if (isset($filterParams['filter'])) {
            foreach ($filterParams['filter'] as $key => $value) {
                $key = trim($key);
                if (array_key_exists(ProductAttribute::IS_EQUAL_TO, $value)) {
                    $conditions[] = "" . $key . " = '" . trim(addslashes($value[ProductAttribute::IS_EQUAL_TO])) . "'";
                } elseif (array_key_exists(ProductAttribute::IS_NOT_EQUAL_TO, $value)) {
                    $conditions[] = "" . $key . " != '" . trim(addslashes($value[ProductAttribute::IS_NOT_EQUAL_TO])) . "'";
                } elseif (array_key_exists(ProductAttribute::IS_CONTAINS, $value)) {
                    $conditions[] = "" . $key . " LIKE '%" . trim(addslashes($value[ProductAttribute::IS_CONTAINS])) . "%'";
                } elseif (array_key_exists(ProductAttribute::IS_NOT_CONTAINS, $value)) {
                    $conditions[] = "" . $key . " NOT LIKE '%" . trim(addslashes($value[ProductAttribute::IS_NOT_CONTAINS])) . "%'";
                } elseif (array_key_exists(ProductAttribute::START_FROM, $value)) {
                    $conditions[] = "" . $key . " LIKE '" . trim(addslashes($value[ProductAttribute::START_FROM])) . "%'";
                } elseif (array_key_exists(ProductAttribute::END_FROM, $value)) {
                    $conditions[] = "" . $key . " LIKE '%" . trim(addslashes($value[ProductAttribute::END_FROM])) . "'";
                } elseif (array_key_exists(ProductAttribute::RANGE, $value)) {
                    if (trim($value[ProductAttribute::RANGE]['from']) && !trim($value[ProductAttribute::RANGE]['to'])) {
                        $conditions[] = "" . $key . " >= '" . $value[ProductAttribute::RANGE]['from'] . "'";
                    } elseif (trim($value[ProductAttribute::RANGE]['to']) && !trim($value[ProductAttribute::RANGE]['from'])) {
                        $conditions[] = "" . $key . " >= '" . $value[ProductAttribute::RANGE]['to'] . "'";
                    } else {
                        $conditions[] = "" . $key . " between '" . $value[ProductAttribute::RANGE]['from'] . "' AND '" . $value[ProductAttribute::RANGE]['to'] . "'";
                    }
                }
            }
        }

        if (isset($filterParams['search']) && $fullTextSearchColumns) {
            $conditions[] = " MATCH(" . $fullTextSearchColumns . ") AGAINST('" . trim(addslashes($filterParams['search'])) . "')";
        }

        $conditionalQuery = "";
        if (is_array($conditions) && count($conditions)) {
            $conditionalQuery = implode(' AND ', $conditions);
        }

        return $conditionalQuery;
    }

    /**
     * @param int $limit
     * @param int $offSet
     * @param array $filters
     * @return array
     */
    public function getAllAttributes($limit = 5, $offSet = 0, $filters = [])
    {
        $user = $this->di->getUser();
        if (count($filters) > 0) {
            $query = 'SELECT * FROM \App\Connector\Models\ProductAttribute WHERE merchant_id = ' . $user->id . ' AND ';
            $countQuery = 'SELECT COUNT(*) FROM \App\Connector\Models\ProductAttribute WHERE merchant_id = ' . $user->id . ' AND ';
            $conditionalQuery = $this->search($filters);
            $query .= $conditionalQuery . ' LIMIT ' . $limit . ' OFFSET ' . $offSet;
            $countQuery .= $conditionalQuery;
            $exeQuery = new Query($query, $this->di);
            $collection = $exeQuery->execute();
            $collection = $collection->toArray();

            $exeCountQuery = new Query($countQuery, $this->di);
            $collectionCount = $exeCountQuery->execute();
            $collectionCount = $collectionCount->toArray();
            $collectionCount[0] = json_decode(json_encode($collectionCount[0]), true);
            return [
                'success' => true,
                'message' => 'All attributes of merchant',
                'data' => ['rows' => $collection, 'count' => $collectionCount[0][0]]
            ];
        }
        $allAttributes = self::find(["merchant_id='{$user->id}'", 'limit' => $limit, 'offset' => $offSet]);
        $count = self::count(["merchant_id='{$user->id}'"]);
        return [
            'success' => true,
            'message' => 'All attributes of merchant',
            'data' => ['rows' => $allAttributes->toArray(), 'count' => $count]
        ];
    }

}
