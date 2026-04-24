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

namespace App\Shopifyhome\Components\Refund;

use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Components\Product\Inventory\Import;
use App\Shopifyhome\Components\Core\Common;

class Data extends Common
{
    public $_collection = '';

    public $_error = false;

    public $_userId = 1;

    public function prepare($userId = null): void{
        $userId = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("product_container_" . $userId);

        $this->_collection = $mongo->getPhpCollection();
        $this->_userId = $userId;
    }

    public function handleProductData($data, $userId = null){
        $this->prepare($userId);
        if($this->_error) return $this->_error;

        switch($data['db_action'])
        {
            case 'variant_update' :
                unset($data['db_action']);
                $this->updateProductVariant($data);
                break;

            case 'delete' :
                break;

            default :
                return [
                    'success' => false,
                    'message' => "No action defained"
                ];
        }
    }

    public function deleteProducts($userId = null , $locationId = []): void{
        $this->prepare($userId);
        $this->_collection->drop();
        /*
        $shopifyMongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $shopifyMongo->setSource("product_container_" . $userId);
        $shopifyMongo->getCollection()->drop();
        */
    }

    public function deleteIndividualVariants($containerId, $products = [], $deleteParent = false, $userId = null): void{
        $this->prepare($userId);
        if($deleteParent){
            $this->_collection->deleteOne(
                ['details.source_product_id' => $containerId]
            );
        } else {
            foreach ($products as $variantId){
                $this->_collection->updateMany(
                    ['details.source_product_id' => $containerId],
                    ['$pull' => [
                        "variants" => [
                            "source_variant_id" => $variantId
                        ]
                    ]
                    ]
                );
            }
        }
    }

    public function getallVariantIds($id, $userId = null){
        $this->prepare($userId);
        $ids = $this->_collection->distinct("variants.source_variant_id"
            , ["details.source_product_id" => $id]
            );
        return $ids;
    }

    public function updateProductVariant($data): void{
        $out = $this->_collection->findOne(['variants.source_variant_id' => $data['source_variant_id']], ['typeMap' => ['root' => 'array', 'document' => 'array']]);

        if(empty($out)){
            $this->_collection->updateOne(
                [ 'details.source_product_id' => $data['container_id']],
                [ '$push' => [
                    'variants' => $data
                    ]
                ]
            );
        } else {
            $this->_collection->updateOne(
                [ 'variants.source_variant_id' => $data['source_variant_id'] ],
                [ '$set' => [
                    'variants.$' => $data
                ]
                ]
            );
        }
    }

    public function updateWebhookProductDetails($data, $userId = null){
        $this->prepare($userId);

        $productExists = $this->_collection->findOne(
            ['details.source_product_id' => $data['source_product_id']]);

        if($productExists){
            $updateVariant = $this->_collection->updateOne(
                ['details.source_product_id' => $data['source_product_id']],
                ['$set' => [
                    'details' => $data
                ]
                ]
            );
            return [
                'success' => true
            ];
        }
        return [
            'success' => false,
            'msg' => 'product does not exist'
        ];

    }

    public function updateWebhookProducts($data, $userId = null){
        $this->prepare($userId);
        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $out = $this->_collection->aggregate([['$unwind' => '$variants'],['$match' => ['variants.source_variant_id' => ['$eq' =>$data['source_variant_id']]]], ['$project' => ['variants' => 1 ]] ])->toArray();

        if(empty($out)){

            $addVariant = $this->_collection->updateOne([ 'details.source_product_id' => $data['container_id']],[ '$push' => ['variants' => $data]]);

            if($addVariant->getModifiedCount() == 1){
                //$remoteShopId = 12; // todo : add remote shop id
                $usr = $this->di->getObjectManager()->get('App\Core\Models\User\Details')->getDataByUserID($this->_userId, $target);
                $this->di->getObjectManager()->get(Import::class)->getProductInventory([$data['inventory_item_id']], $usr['remote_shop_id']);
                return true;
            }
        } else {
            $locationInv = [];
            if(isset($out[0]['variants']['locations']) && count($out[0]['variants']['locations']) > 0){
                foreach ($out[0]['variants']['locations'] as $location){
                    $locationInv[] =  json_decode(json_encode($location), true);
                }

                $data['locations'] = $locationInv;
                $updateVariant = $this->_collection->updateOne(
                    ['variants.source_variant_id' => $data['source_variant_id']],
                    ['$set' => [
                        'variants.$' => $data
                        ]
                    ]
                );
            } else {
                $updateVariant = $this->_collection->updateOne(
                    ['variants.source_variant_id' => $data['source_variant_id']],
                    ['$set' => [
                        'variants.$' => $data
                    ]
                    ]
                );
                if($updateVariant->getModifiedCount() == 1){
                    //$remoteShopId = 12; // todo : add remote shop id
                    $usr = $this->di->getObjectManager()->get('App\Core\Models\User\Details')->getDataByUserID($this->_userId, $target);
                    $this->di->getObjectManager()->get(Import::class)->getProductInventory([$data['inventory_item_id']], $usr['remote_shop_id']);
                    return true;
                }
            }
        }
    }
}