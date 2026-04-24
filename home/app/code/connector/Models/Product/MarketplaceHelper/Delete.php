<?php

namespace App\Connector\Models\Product\MarketplaceHelper;

use App\Core\Models\Base;


class Delete extends Base
{

    public $_userId = '';

    public $_productContainer = null;

    public $_refineProduct = null;

    public $_errors = null;

    public $_productContainerDeleteBulk = [];

    public $_refineProductDeleteBulk = [];


    public function init($data): void
    {
        $this->_errors = [];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->_productContainer = $mongo->getCollectionForTable('product_container');
        $this->_refineProduct = $mongo->getCollectionForTable('refine_product');

        if (isset($data['user_id'])) {
            $this->_userId = $data['user_id'];
        } else {
            $this->_userId = $this->di->getUser()->id;
        }
    }

    public function initiate($data)
    {
        if (!isset($data['deleteArray']) && !is_array($data['deleteArray'])) {
            return ['success' => false, 'message' => 'Delete Array either missing or invalid'];
        }

        $this->init($data);

        foreach ($data['deleteArray'] as $arr) {
            $this->deleteInitaite($arr);
        }

        $resRefine =  $this->executeBulk($this->_refineProduct, $this->_refineProductDeleteBulk);

        $resProduct = $this->executeBulk($this->_productContainer, $this->_productContainerDeleteBulk);

        return ['success' => true, 'error' => $this->_errors, 'bulk' => [
            'res' => $resRefine,
            'prod' => $resProduct,

        ]];
    }

    public function executeBulk($mongo, $bulk)
    {
        if (count($bulk) != 0) {
            return $mongo->BulkWrite($bulk);
        }

        return false;
    }

    public function isTypeValid($data)
    {
        return (isset($data['type']) && ($data['type'] == 'Catalog and Search' || $data['type'] == 'Not Visible Individually'));
    }


    public function validateData($data)
    {
        if (isset($data['source_product_id'])  && !$this->isTypeValid($data)) {
            return ['success' => false, 'message' => 'Type missing or invalid'];
        }

        if ($this->isTypeValid($data) && !isset($data['source_product_id'])) {
            return ['success' => false, 'message' => 'Source product id missing'];
        }

        if ((!isset($data['source_product_id'])) && !(isset($data['source_shop_id']) || isset($data['target_shop_id']))) {
            return ['success' => false, 'message' => 'Please provide source_shop_id or target_shop_id or source_product_id with type'];
        }

        return ['success' => true];
    }

    public function deleteInitaite($data)
    {
        $validate = $this->validateData($data);
        if ($validate['success'] == false) {
            $data['error_type'] = $validate;
            $this->_errors[] = $data;
            return;
        }

        if (isset($data['source_product_id'])) {
            $this->SPIDeleteQuery($data);
            return true;
        }

        if (isset($data['source_shop_id']) && !isset($data['source_product_id']) && !isset($data['target_shop_id'])) {
            $this->handleSourceDelete($data);
            return true;
        }

        if (isset($data['target_shop_id']) && !isset($data['source_product_id']) && !isset($data['source_shop_id'])) {
            $this->handleTargetIdDelete($data);
            return true;
        }

        if (isset($data['target_shop_id'], $data['source_shop_id']) && !isset($data['source_product_id'])) {
            $this->handleTargetIdDelete($data);
            return true;
        }
        
    }

    public function SPIDeleteQuery($data): void
    {
        if ($data['type'] == 'Not Visible Individually') {
            $this->_refineProductDeleteBulk[] = [
                'updateMany' => [
                    [
                        'user_id' => (string)$this->_userId,
                        'items.source_product_id' => (string)$data['source_product_id']
                    ] + $this->apendSourceshopId($data, 'source_shop_id') + $this->apendTargetShopId($data, 'target_shop_id') + $this->apendContainerId($data),
                    ['$pull' => ['items' => ['source_product_id' => (string)$data['source_product_id']] + $this->apendTargetShopId($data)]]
                ]
            ];
        }

        if ($data['type'] == 'Catalog and Search') {
            $this->_refineProductDeleteBulk[] = [
                'deleteMany' => [
                    [
                        'user_id' => (string)$this->_userId,
                        'source_product_id' => (string)$data['source_product_id']
                    ] + $this->apendSourceshopId($data, 'source_shop_id') + $this->apendTargetShopId($data, 'target_shop_id') + $this->apendContainerId($data)
                ]
            ];
        }
    }

    public function handleTargetIdDelete($data): void
    {
        $this->_refineProductDeleteBulk[] = [
            'deleteMany' => [
                [
                    'user_id' => (string)$this->_userId,
                    'target_shop_id' => (string)$data['target_shop_id']
                ] + $this->apendSourceshopId($data, 'source_shop_id') +$this->apendContainerId($data)
            ]
        ];
    }

    public function handleSourceDelete($data): void
    {
        $this->_refineProductDeleteBulk[] = [
            'deleteMany' => [
                [
                    'user_id' => (string)$this->_userId,
                    'source_shop_id' => (string)$data['source_shop_id']
                ]
            ]
        ];
    }

    public function apendTargetShopId($data, $shopIdKey = 'shop_id')
    {
        return isset($data['target_shop_id']) ? [$shopIdKey => (string)$data['target_shop_id']] : [];
    }


    public function apendContainerId($data)
    {
        return isset($data['container_id']) ? ['container_id' => (string)$data['container_id']] : [];
    }

    public function apendSourceshopId($data, $shopIdKey = 'shop_id')
    {
        return isset($data['source_shop_id']) ? [$shopIdKey => (string)$data['source_shop_id']] : [];
    }
}
