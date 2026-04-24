<?php

namespace App\Connector\Models;

use App\Core\Models\BaseMongo;
use Phalcon\Validation;
use Phalcon\Validation\Validator\InvalidValue;

class MarketplaceProduct extends BaseMongo
{
    const RANGE = 7;

    const END_FROM = 6;

    const START_FROM = 5;

    const IS_EQUAL_TO = 1;

    const IS_CONTAINS = 3;

    const IS_NOT_EQUAL_TO = 2;

    const IS_NOT_CONTAINS = 4;

    protected $sqlConfig;

    protected $implicit = false;

    protected $table = '_product';

    public static $defaultPagination = 20;

    /**
     * Initialize the model
     */
    public function onConstruct(): void
    {
        $this->di = $this->getDi();
        $this->sqlConfig = $this->di->getObjectManager()->get('\App\Connector\Components\Data');
        $this->userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $this->di->getRegistry()->getDecodedToken();
        $this->setSource($this->table);
        $this->initializeDb($this->getMultipleDbManager()->getDb());
    }

    /**
     * @return mixed
     */
    public function validation()
    {
        $validator = new Validation();
        return $this->validate($validator);
    }

    public function createMarketplaceProduct($products, $marketplace, $warehouseId, $shopId = false, $userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        if (!$shopId) {
            $shopId = $this->userDetails->getDataByUserID($userId, $marketplace, $warehouseId)['_id'] ?? "";
        }

        if ($shopId === "") {
            return ['success' => false, 'message' => 'Shop Id Not Found'];
        }

        $this->setSource($marketplace."_product");
        $collection = $this->getCollection();

        $bulkOpArray = [];
        foreach ($products as $product) {
            if ( !isset($product['target_product_id']) ) continue;

            $filter = [
                'user_id' => $userId,
                'target_product_id' => $product['target_product_id']
            ];
            $product['update_at'] = date('c');
            $queryData = [
                '$set' => $product,
                '$addToSet' => [
                    'warehouse_id' => $warehouseId
                ],
                '$setOnInsert' => [
                    'created_at' => date('c'),
                ],
            ];
            $bulkOpArray[] = [
                'updateOne' => [
                    $filter,
                    $queryData,
                    ['upsert' => true],
                ],
            ];
        }

        $this->addMarketplaceStatusInContainer($products, $marketplace, $warehouseId, $shopId, $userId);

        $bulkObj = $collection->BulkWrite($bulkOpArray, ['w' => 1]);
        return $bulkObj;
    }

    public function addMarketplaceStatusInContainer($products, $marketplace, $warehouseId, $shopId = false, $userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        if (!$shopId) {
            $shopId = $this->userDetails->getDataByUserID($userId, $marketplace, $warehouseId);
        }

        $this->setSource("product_container");
        $collection = $this->getCollection();
        $bulkOpArray= [];
        foreach ($products as $product) {
            $product['status']['shop_id'] = $shopId;
            $product['status']['warehouse_id'] = $warehouseId;
            $product['status']['target_product_id'] = $product['target_product_id'] ?? '';
            $product['status']['status'] ??= 'error';

            $bulkOpArray[] = [
                'updateOne' => [
                    [
                        'user_id' => $userId,
                        'source_product_id' => $product['source_product_id'],
                        'marketplace.' . $marketplace => ['$exists' => false]
                    ],
                    [
                        '$set' => [
                            'marketplace.' . $marketplace => [
                                $product['status']
                            ],
                        ]
                    ]
                ],
            ];
            $bulkOpArray[] = [
                'updateOne' => [
                    [
                        'user_id' => $userId,
                        'source_product_id' => $product['source_product_id'],
                        'marketplace.' . $marketplace . '.warehouse_id' => ['$ne' => $warehouseId],
                    ],
                    [
                        '$push' => [
                            'marketplace.' . $marketplace =>
                            $product['status']
                        ]
                    ]
                ],
            ];

            $bulkOpArray[] = [
                'updateOne' => [
                    [
                        'user_id' => $userId,
                        'source_product_id' => $product['source_product_id'],
                        'marketplace.' . $marketplace . '.warehouse_id' =>  $warehouseId
                    ],
                    [
                        '$set' => [
                            'marketplace.' . $marketplace . '.$[warehouseID]' =>
                            $product['status']
                        ]
                    ],
                    [
                        'arrayFilters' => [
                            [ 'warehouseID.warehouse_id' => $warehouseId]
                        ]
                    ],
                ],
            ];
        }

        return $collection->BulkWrite($bulkOpArray, ['w' => 1]);
    }

    /**
     * Delete the product from marketplace specific product table
     * @param $userId
     * @param $marketplace
     * @param $condition
     */
    public function deleteProduct($userId, $marketplace, $condition): void
    {
        $this->setSource($marketplace."_product");
        $collection = $this->getCollection();
        if ($condition) {
            $status = $collection->deleteOne($condition, ['w' => true]);
            if ($status->isAcknowledged()) {
                $this->fireEvent("afterDelete");
            }
        }
    }

    /**
     * @param $userId
     * @param $marketplace
     */
    public function getProductIds($userId, $marketplace): void
    {
        $this->setSource($marketplace."_product_".$userId);
        $collection = $this->getCollection();
        $collection->find([]);
    }
}
