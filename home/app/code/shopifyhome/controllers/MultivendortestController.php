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
 * @package     Ced_amazon
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright © 2018 CedCommerce. All rights reserved.
 * @license     EULA http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Controllers;

use App\Core\Controllers\BaseController;
use App\Shopifyhome\Components\Product\Import;
use App\Shopifyhome\Models\SourceModel;
use App\Core\Models\User;
use function GuzzleHttp\json_decode;
use function GuzzleHttp\json_encode;
#[\AllowDynamicProperties]
class MultivendortestController extends BaseController
{
    public function getsellersAction(){
        return "Hello Test Controller";
        $filter['shop_id'] = 14;
        //$filter['next'] = 'bGltaXQ9MjUwJnBhZ2VfaW5mbz1leUprYVhKbFkzUnBiMjRpT2lKdVpYaDBJaXdpYkdGemRGOXBaQ0k2TXpVek9EWXhNVEkwTVRBek9Dd2liR0Z6ZEY5MllXeDFaU0k2SWxCc1lYa2dSRzlvSUV4dmJtY2dVMnhsWlhabElGUXRVMmhwY25RZ1ZtbHVkR0ZuWlNCTWIyZHZJRkpsWkNCVVpXVWlmUQ==';

        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify_vendor', 'true')
            ->call('/product',[],$filter, 'GET');

        $productResponse = $this->di->getObjectManager()->get(Import::class)->getShopifyProducts($remoteResponse);


        print_r($productResponse);
        die();
        /* $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
          $mongo->setSource("product_container");
          $mongoCollection = $mongo->getPhpCollection();
          $ids = $mongoCollection->distinct("variants.source_variant_id"
              , ['$and'=>[
                  ['details.user_id' => '167'],
                  ["details.source_product_id" => '6141573955775']
              ]]
          );
          var_dump($ids);
          die();*/
        //todo: code for enabling and disabling products
        /* $data=[];
         $data['user_id']='155';
         $data['source_product_id']='6095838642372';
         $data['flag']="enable";
         $res=$this->productEnableDisableAction($data);
         print_r($res);
         die("abc");*/

        //todo: Getting all the sellers corressponding to amdin
        $sellershop_ids = $this->di->getObjectManager()->get('\App\Connector\Components\ApiClient')->init('shopify', true)->call('/getallsellers', [], [
            'shop_id' => 15
        ]);
        foreach ($sellershop_ids['data'] as $shop) {
            $shopResponse = $this->di->getObjectManager()->get('\App\Connector\Components\ApiClient')->init('shopify_vendor', true)->call('/shop', [], [
                'shop_id' => $shop['_id']
            ]);
            echo "<pre>";
            print_r($shopResponse);
            echo "</pre>";
        }

        die();
    }

    public function testAction()
    {
//        print_r(phpinfo());
//        die("test");
       /* $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('shopify_product');
        $filter=['$and'=>[
            ['user_id'=>'282'],
            ['source_product_id'=>'37767960756420']
        ]];
        $query = ['$push' => ['edited_fields.description' => "Text"]];
        $status=$collection->updateOne($filter, $query);
        print_r($status->getModifiedCount());
        die();*/
        $mid=['604c6f2aa13bdb06041aaca5'];
        $fbData  = array (
            'source' => 'vidaxl',
            'source_shop' => '',
            'source_shop_id' => '',
            'target' => 'shopify',
            "selected_products" => ['33213','33212','33212'],
            'target_shop' => '',
            'target_shop_id' => '11',
            'selected_profile' => '0',
            'profile_type' => 'default_profile',
            'marketplace' => 'vidaxl',
            'type' =>
                array (
                    'create' => true,
                    'update' => true,
                ),
        );

        foreach($mid as $id){
//            print_r($id);
//            $id = (string)$id;
//            $this->di->getObjectManager()->get("\App\Core\Components\Helper")->setUserToken($id);
//            $sqsResponse = $this->di->getObjectManager()->get("\App\Shopifyhome\Models\SourceModel")->initiateUpload($fbData);
            $sqsResponse = $this->di->getObjectManager()->get(SourceModel::class)->selectProductAndUpload($fbData);
            return $sqsResponse;
        }

        die('test fb 000');

        /*$mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("product_container");
        $collection = $mongo->getPhpCollection();
        $invResponse = $collection->distinct(
            'inventory_item_id',['$and'=>[
            ['user_id' => '291'],
            ["source_marketplace" => 'shopify_vendor']
        ]]);
        $arrayChunks = array_chunk($invResponse,80);
        print_r($arrayChunks);
        print_r("arrayChunks");*/

       /* $filter['shop_id'] = 77;
        $userid='291';
        $this->di->getObjectManager()->get("\App\Core\Components\Helper")->setUserToken($userid);
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify_vendor', 'true')
            ->call('/product',[],$filter, 'GET');

        $productResponse = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Import')->getShopifyProducts($remoteResponse);
        print_r($productResponse);
        die();*/

        $rawBody=[];
        $rawBody['id']="37771730616516";
        $rawBody['user_id']='282';
        $rawBody['source_marketplace']='shopify';
        $mid='282';
        $this->di->getObjectManager()->get("\App\Core\Components\Helper")->setUserToken($mid);
        $product = $this->di->getObjectManager()->create('\App\Connector\Models\ProductContainer');
        return $this->prepareResponse($product->getProductById($rawBody));
        die("Product Data");


       /* $rawBody=[];
        $rawBody['type']="admin";
//        $rawBody['user_id']='283';
        $mid='283';
        $this->di->getObjectManager()->get("\App\Core\Components\Helper")->setUserToken($mid);
        $product = $this->di->getObjectManager()->create('\App\Connector\Models\ProductContainer');
        return $this->prepareResponse($product->getAllProducts($rawBody));
        die("Product Data");*/


        $rawBody=[];
        $rawBody['username']="Shubh";
        $rawBody['email']="Shubh@cedcommerce.com";
        $rawBody['password']="123456";
        $rawBody['mobno']="89045675452";
        $rawBody['marketplace']="shopify";
        $rawBody['confirmation_link']="http://madmin.local.cedcommerce.com:3001/auth/confirmation";
        $user = new User;
        $res=$this->prepareResponse($user->createUser($rawBody, true));
        echo "<pre>";
        print_r(\GuzzleHttp\json_decode(\GuzzleHttp\json_encode($res),true));
        echo "</pre>";
        die("Created");

        $sellershop_ids = $this->di->getObjectManager()->get('\App\Connector\Components\ApiClient')->init('shopify', true)->call('/getallsellers', [], [
            'shop_id' => 41
        ]);
        $shopids=[];
        foreach ($sellershop_ids['data'] as $shop) {
           $shopids[]=$shop['_id'];
        }

        $newshopids=[];
        foreach ($shopids as $shopid){
            $newshopids[]=(string)$shopid;
        }

        $mongoCollection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $phpMongoCollection = $mongoCollection->setSource('user_details')->getPhpCollection();
        $sellersData = $phpMongoCollection->find(['shops.0.remote_shop_id'=>['$in'=>$newshopids]])->toArray();
        $sellersData=\GuzzleHttp\json_decode(\GuzzleHttp\json_encode($sellersData),true);
        print_r($sellersData);
        die();
        return ['success'=>true,'sellersData'=>$sellersData];
    }

    public function productEnableDisableAction($data){
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->_collection = $mongo->setSource("product_container_".$data['user_id'])->getPhpCollection();
        if(isset($data['allproducts'])){
            if($data['allproducts']=="enable"){
                $this->_collection->updateMany([],['$set' => ['details.flag' =>'enable']]);
                $response=['success'=>true,'msg'=>"All products are enabled"];
            }

            if($data['allproducts']=="disable"){
                $this->_collection->updateMany([],['$set' => ['details.flag' =>'disable']]);
                $response=['success'=>true,'msg'=>"All products are disabled"];
            }
        }

        if(isset($data['source_product_id']) && isset($data['flag'])){
            if($data['flag']=="enable"){
                $this->_collection->updateOne(
                    ['details.source_product_id' =>(string)$data['source_product_id']],
                    ['$set' => ['details.flag' =>'enable']]
                );
                $response=['success'=>true,'msg'=>"Product is enabled"];
            }

            if($data['flag']=="disable"){
                $this->_collection->updateOne(
                    ['details.source_product_id' =>(string)$data['source_product_id']],
                    ['$set' => ['details.flag' =>'disable']]
                );
                $response=['success'=>true,'msg'=>"Product is disabled"];
            }
        }

        if(isset($response)){
            return $response;
        }
    }
}