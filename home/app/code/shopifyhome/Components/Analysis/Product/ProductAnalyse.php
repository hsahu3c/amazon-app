<?php
namespace App\Shopifyhome\Components\Analysis\Product;

use App\Core\Components\Base;
use App\Shopifyhome\Components\Product\Data;
use App\Shopifyhome\Components\Product\Vistar\Route\Requestcontrol;
use App\Shopifyhome\Components\Product\Helper;
use App\Shopifyhome\Components\Product\Vistar\Import;

#[\AllowDynamicProperties]
class ProductAnalyse extends Base
{
    public function _construct()
    {
        parent::_construct();
    }

    public function getTotalProductVariantsCount($sqsdata){
        $userid=(string)$sqsdata['user_id'];
        $userarr=['2745','3056','2789','2969','3116','1909','935','1086','1126','842','1749','1270','2894','2316','2701','2407','2339','3405'];
        if(in_array($userid,$userarr)){
            return true;
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->_collection = $mongo->setSource("product_container_".$userid)->getPhpCollection();
        $collection = $mongo->setSource("user_details")->getCollection();
        $result = $collection->find(["user_id"=>(string)$userid])->toArray();
        if(isset($sqsdata['data']['next'])){
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init('shopify', 'true')
                ->call('/productvariant',[],['shop_id'=> $result[0]['shops'][0]['remote_shop_id'],'next'=>$sqsdata['data']['next']], 'GET');
            $variantcount=$sqsdata['data']['variant_count']+$remoteResponse['count']['variant'];
        }else{
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init('shopify', 'true')
                ->call('/productvariant',[],['shop_id'=> $result[0]['shops'][0]['remote_shop_id']], 'GET');
            $variantcount=$remoteResponse['count']['variant'];
        }

        if(isset($remoteResponse['cursors']['next']) && $remoteResponse['cursors']['next']!=""){
            $handledata=[
                "shop"=>$result[0]['shops'][0]['myshopify_domain'],
                "type"=>"full_class",
                "handle_added"=>"1",
                "class_name"=>\App\Shopifyhome\Components\Analysis\Product\ProductAnalyse::class,
                "method"=>"getTotalProductVariantsCount",
                "user_id"=>(string)$userid,
                "code"=>"Shopifyhome",
                "action"=>"product_delete",
                "queue_name"=>"facebook_webhook_product_delete",
                "data"=>[
                    'next'=>$remoteResponse['cursors']['next'],
                    "variant_count"=>$variantcount,
                    "remote_shop_id"=>$result[0]['shops'][0]['remote_shop_id']
                ]
            ];
            $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Import::class)->pushToQueue($handledata);
            return true;
        }
        $collection = $mongo->getCollectionForTable('product_container_' .$userid);
        $aggregate = [];
        $aggregate[] = ['$unwind' => '$variants'];
        $aggregate[] = ['$group' => ['_id' =>null, 'count' => ['$sum' => 1]]];
        $pro = $collection->aggregate($aggregate)->toArray();
        $res=json_decode(json_encode($pro), true);
        if(isset($res[0]['count']) && $res[0]['count']!=$variantcount){
//                print_r($userid);
            $this->di->getLog()->logContent("User ID ==== ".$userid,'info','shopify' .DS.'DIFF'.DS.date("Y-m-d").DS. 'userid.log');
            $this->di->getLog()->logContent("User ID ==== ".$userid.' Shopify Variant Count = '.json_encode($variantcount)." And app variants Count = ".json_encode($res[0]['count']),'info','shopify' .DS.'DIFF'.DS.date("Y-m-d").DS. 'variant_count.log');
            $this->di->getLog()->logContent("*******************************************************************************************************************************",'info','shopify' .DS.'DIFF'.DS.date("Y-m-d").DS. 'variant_count.log');
            return true;
        }
        return true;
    }



    public function deleteVariants(): void{
        $userId = '202';
        $append='vistar_';
        $this->di->getObjectManager()->get("\App\Core\Components\Helper")->setUserToken($userId);
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("product_container_".$userId)->getPhpCollection();

        $query = ['details.is_imported' => 0]; $options = ['projection' => ['details.source_product_id' => 1 ]  ];
        $cursor = $collection->find($query, $options);

        $sourceProductId = [];
        foreach ($cursor as $document) {
            $sourceProductId[] = $document['details']['source_product_id'];
        }

        $mongoData = $this->di->getObjectManager()->get(Data::class);
        foreach ($sourceProductId as $id){
            $response = $mongoData->deleteIndividualVariants($id, [], true);
            print_r($response);
        }

/*        $options = [];
        $pipeline = [
            [
                '$unwind' => [
                    'path' => '$variants'
                ]
            ],
            [
                '$match' => [
                    'variants.is_imported' => 0
                ]
            ],
            [
                '$project' => [
                    'variants.source_variant_id' => 1,
                    'variants.container_id' => 1
                ]
            ]
        ];
        $cursor = $collection->aggregate($pipeline, $options);
        $sourceVariantId = [];
        $data = [];
        foreach ($cursor as $document) {
            $sourceVariantId[] = $document['variants']['source_variant_id'];
            $data[$document['variants']['source_variant_id']] = $document['variants']['container_id'];
        }
        if(count($sourceVariantId)){
            foreach ($data as $variantId => $containerId){
                $mongoData->deleteIndividualVariants($containerId, [(string)$variantId], false, $userId);
            }
            $fbResponse = $this->di->getObjectManager()->get('\App\Facebookhome\Components\Helper')->deleteVariant(['selected_variants' => $sourceVariantId]);
            print_r($fbResponse);
        }*/
    }

    public function startImportFromGetStatus($userId,$feed_id,$id): void{
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("user_details")->getCollection();
        $result = $collection->find(["user_id"=>$userId])->toArray();
        $remote_shop_id=$result[0]['shops'][0]['remote_shop_id'];
        $handlerData= ['type' => 'full_class', 'class_name' => Requestcontrol::class, 'method' => 'handleImport', 'queue_name' => 'shopify_product_import', 'own_weight' => 100, 'user_id' => $userId, 'data' => [
            'user_id' => $userId,
            'individual_weight' => 1,
            'feed_id'=>$feed_id,
            'operation' => 'get_status',
            'remote_shop_id' => $remote_shop_id,
            'sqs_timeout'=>240,
            'id' => $id
        ]];
        $this->di->getObjectManager()->get(Requestcontrol::class)->handleImport($handlerData);
    }

    public function startImportFromBuildProgress($userId,$feed_id,$id){
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("user_details")->getCollection();
        $result = $collection->find(["user_id"=>$userId])->toArray();
        $remote_shop_id=$result[0]['shops'][0]['remote_shop_id'];
        $handlerData= ['type' => 'full_class', 'class_name' => Requestcontrol::class, 'method' => 'handleImport', 'queue_name' => 'shopify_product_import', 'own_weight' => 100, 'user_id' => $userId, 'data' => [
            'user_id' => $userId,
            'individual_weight' => 3,
            'feed_id'=>$feed_id,
            'operation' => 'build_progress',
            'remote_shop_id' => $remote_shop_id,
            'id' => $id,
            'file_path' => "/var/www/html/home/var/file/{$userId}/product.jsonl",
            'sqs_timeout'=>240
        ]];
        return $this->di->getObjectManager()->get(Import::class)->init()->buildProgressBar($handlerData);
    }

    public function startImportFromImportProduct($userId,$feed_id,$id,$total): void{
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("user_details")->getCollection();
        $result = $collection->find(["user_id"=>$userId])->toArray();
        $remote_shop_id=$result[0]['shops'][0]['remote_shop_id'];
        $handlerData= ['type' => 'full_class', 'class_name' => Requestcontrol::class, 'method' => 'handleImport', 'queue_name' => 'shopify_product_import', 'own_weight' => 100, 'user_id' => $userId, 'data' => [
            'user_id' => $userId,
            'individual_weight' => 3,
            'feed_id'=>$feed_id,
            'operation' => 'import_product',
            'remote_shop_id' => $remote_shop_id,
            'id' => $id,
            'file_path' => "/var/www/html/home/var/file/{$userId}/product.jsonl",
            'total'=>$total,
            'limit'=> 500,
            'total_variant'=>0,
            'sqs_timeout'=>240
        ]];
       $this->di->getObjectManager()->get(Import::class)->init()->importControl($handlerData);
    }

    public function startImportFromDelete_nexisting($userId,$feed_id,$id,$total): void{
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("user_details")->getCollection();
        $result = $collection->find(["user_id"=>$userId])->toArray();
        $remote_shop_id=$result[0]['shops'][0]['remote_shop_id'];
        $handlerData= ['type' => 'full_class', 'class_name' => Requestcontrol::class, 'method' => 'handleImport', 'queue_name' => 'shopify_product_import', 'own_weight' => 100, 'user_id' => $userId, 'data' => [
            'user_id' => $userId,
            'individual_weight' => 3,
            'feed_id'=>$feed_id,
            'operation' => 'delete_nexisting',
            'remote_shop_id' => $remote_shop_id,
            'id' => $id,
            'total'=>$total
        ]];
        $this->di->getObjectManager()->get(Requestcontrol::class)->handleImport($handlerData);
    }

    public function deleteProduct(): void
    {

        $this->di->getObjectManager()->get("\App\Core\Components\Helper")->setUserToken('157');
        $filter['shop_id'] = 43;
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify', 'true')
            ->call('/product',[],$filter, 'GET');

        $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Import::class)->getShopifyProducts($remoteResponse);

        die('tes 000');

        $userId = '157';
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("product_container_".$userId)->getPhpCollection();

        $query = ['details.is_imported' => 0]; $options = ['projection' => ['details.source_product_id' => 1 ]  ];
        $cursor = $collection->find($query, $options);

        $sourceProductId = [];
        foreach ($cursor as $document) {
            $sourceProductId[] = $document['details']['source_product_id'];
        }

        print_r($sourceProductId);

        die;


        $this->di->getObjectManager()->get(Helper::class)->deleteNotExistingProduct('157');
        die;



        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("product_container_157")->getPhpCollection();
        $res = $collection->updateOne(
            [ 'variants.type' => 'tset'],
            [ '$set' => [
                'variants.$' => ["type" => "tset", 'source_variant_id' => '1234567890', 'is_imported' => 1],
                'details.is_imported' => 1
            ] /*,
                '$set' => [
                    'details.imported' => 1
                ]*/
            ]
        );

        echo 'modified count = '.$res->getModifiedCount();die;

        //$response = $collection->updateMany([],['$set' => ['details.is_imported' => 1, 'variants.$[].is_imported' => 1]]);

        $response = $collection->find();

        echo 'Matched Count = '.$response->getMatchedCount().'<br />';
        echo 'Modified Count = '.$response->getModifiedCount();

        die('test');

        /*$helper = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Data');
        $helper->deleteIndividualVariants();*/
    }
}