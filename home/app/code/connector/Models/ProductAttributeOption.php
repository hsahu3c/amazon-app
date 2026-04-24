<?php

namespace App\Connector\Models;

use App\Core\Models\Base;

class ProductAttributeOption extends Base
{

    protected $table = 'product_attribute_option';

    public function initialize(): void
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDb());
    }

    public function addOption($data)
    {
        if ($data && isset($data['code']) && isset($data['attribute_id'])) {
            $merchantId = $this->di->getUser()->id;
            $attributes = ProductAttributeOption::find(["attribute_id='" . $data['attribute_id'] . "' AND value='" . $data['value'] . "'"]);
            if ($attributes) {
                $attribute = new ProductAttributeOption();
                $attribute->attribute_id = $data['attribute_id'];
                $attribute->value = $data['value'];
                $status = $attribute->save();
                if ($status === false) {
                    $error = [];
                    foreach ($attribute->getMessages() as $message) {
                        $error[] = $message->getMessage();
                    }

                    return ['success' => false, 'code' => 'something_wrong', 'message' => 'Something went wrong', 'data' => $error];
                }

                return ['success' => true, 'message' => 'Attribute Option Added Successfully', 'data' => [$attribute->id]];
            }
            return ['success' => false, 'code' => 'code_already_exists', 'message' => 'Code Already Exists', 'data' => []];
        }
        return ['success' => false, 'code' => 'invalid_data', 'message' => 'Invalid Data', 'data' => []];
    }
}
