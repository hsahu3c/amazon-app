<?php
/**
 * CedCommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_Shopifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Components\Product\Quickimport;

use App\Shopifyhome\Components\Product\Vistar\Helper;
use Exception;
use App\Shopifyhome\Components\Core\Common;

class Import extends Common
{
    public function saveProduct($products, $sqsData)
    {
        $returnParam = [];
        try {
            $vistarHelper = $this->di->getObjectManager()->get(Helper::class);
            $remote = $vistarHelper->shapeProductResponse($products);
            $inserted = $updated = 0;
            $error = [];

            $canImportCollection = $this->getDi()->getConfig()->get('can_import_collection') ?? true;

            foreach ($remote['data'] as $key=>$productData) {
                if($canImportCollection === true) {
                    $remote['data'][$key]['add_collection'] = true;
                }
                $remote['data'][$key]['add_locations'] = true;
                $remote['data'][$key]['shop_id'] = $sqsData['shop_id'];
                $remote['data'][$key]['user_id'] = $sqsData['user_id'];
                if(isset($remote['data'][$key]['status']))
                {
                    $remote['data'][$key]['source_status'] = $remote['data'][$key]['status'];
                    unset($remote['data'][$key]['status']);
                }
            }

            $app_codes = $sqsData['app_codes'];

            foreach ($app_codes as $a_value)
            {
                $additional_data = [];
                $additional_data['app_code'] = $a_value;
                $additional_data['shop_id'] = $sqsData['shop_id'];
                $additional_data['marketplace'] = $sqsData['marketplace'];
                $additional_data['feed_id'] = '';
                $additional_data['target_marketplace'] = '';
                $additional_data['target_shop_id'] = '';

                $connectorHelper = $this->di->getObjectManager()->get('\App\Connector\Components\Route\ProductRequestcontrol');

                $import = $connectorHelper->pushToProductContainer($remote['data'], $additional_data);
            }

            //$addDataInRefineTable = $this->addProductInRefineTable($remote, $sqsData);

            $returnParam = ['success' => true, 'requeue' => true, 'data' => $import['stats'] ?? ''];
        }
        catch (Exception $e) {
            $this->di->getLog()->logContent('PROCESS X00400 | Process - Get Product Count from Shopify | Bulkimport | buildProgressBar | Error Message  = '.$e->getMessage().' File Path = '. $sqsData['data']['file_path'].' | Probable Error : JSONL file does not exist.','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'quick_product_import.log');
            return [ 'success' => false, 'requeue'=> false];
        }

        return $returnParam;
    }

    public function addProductInRefineTable($products, $sqsData)
    {
        //$data = [
        //     'source_product_id' => '43130733789414',
        //     'childInfo' => [
        //         'source_product_id' => '43130733789414',
        //         'shop_id' => '222',
        //         'target_marketplace' => 'amazon',
        //         'title' => 'update',
        //         "process_tags" => [
        //             "Upload product in progress",
        //             "Upload product in progress"
        //         ]
        //         // 'error'=>[
        //         //     'product'=>['bohot_error'],
        //         //     'update'=>['kuch jada hi error']
        //         // ]
        //     ],
        // ];

        $shop_id = $sqsData['shop_id'];
        $source_marketplace = $sqsData['marketplace'];
        $prepareData = [];
        $variants = $products['data'];

        $filters = $this->di->getConfig()->allowed_filters ? $this->di->getConfig()->allowed_filters->toArray() : [];
        // $filters[] = 'target_marketplace';

        foreach ($variants as $value)
        {
            $childInfo = [];

            foreach ($filters as $f_value) {
                if (isset($value[$f_value])) {
                    $childInfo[$f_value] = $value[$f_value];
                }
            }

            $childInfo['source_marketplace'] = $source_marketplace;
            $childInfo['direct'] = true;

            $prepareData[] = ['source_product_id' => $value['source_product_id']] + ['childInfo' => $childInfo];
        }

        if($prepareData !== [])
        {
            $connectorObj = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
            $intoRefine = $connectorObj->marketplaceSaveAndUpdate($prepareData);

        }

        return true;
    }

    public function fetchIds($userId, $type='inventory_item_id')
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        // $mongo->setSource("product_container");
        // $collection = $mongo->getPhpCollection();
        $collection = $mongo->getCollection("product_container");

        // $target = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Shop\Shop')->getUserMarkeplace();

        if($type == 'product_id') {
            $response = $collection->distinct(
                'container_id', ['$and'=>[
                ['user_id' => $userId],
                ["add_collection" => true]
                // ['collection' => 'import_via_quickimport']
            ]]);
        }
        else {
            $response = $collection->distinct(
                'inventory_item_id', ['$and'=>[
                ['user_id' => $userId],
                ["add_locations" => true]
                // ['locations' => 'import_via_quickimport']
            ]]);
        }

        if(count($response)) {
            return [
                'success' => true,
                'ids' => $response,
                'total' => count($response)
            ];
        }
        return [
            'success' => true,
            'total' => 0
        ];
    }

    public function updateProductInventory($userId, $inventoryItemId, $inventoryLevels)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productContainerCollection = $mongo->getCollection("product_container");

        $query = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Helper::class)->getAndQuery(["add_locations"=>true, 'inventory_item_id'=>$inventoryItemId], $userId);

        return $productContainerCollection->updateMany($query,['$unset' => ['add_locations' => 1], '$set' => ["locations" => $inventoryLevels]]);

        // $query = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Helper')->getAndQuery(['locations'=>'import_via_quickimport', 'inventory_item_id'=>$inventoryItemId], $userId);
        // return $productContainerCollection->updateOne($query,['$set' => ['locations' => $inventoryLevels]]);
    }

    public function updateProductCollection($userId, $productId, $collection)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productContainerCollection = $mongo->getCollection("product_container");

        $query = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Helper::class)->getAndQuery(["add_collection"=>true, 'container_id'=>$productId], $userId);
        return $productContainerCollection->updateMany($query,['$unset' => ['add_collection' => 1], '$set' => ["collection" => $collection]]);

        // $query = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Helper')->getAndQuery(['collection'=>'import_via_quickimport', 'container_id'=>$productId], $userId);
        // return $productContainerCollection->updateMany($query,['$set' => ['collection' => $collection]]);
    }
}