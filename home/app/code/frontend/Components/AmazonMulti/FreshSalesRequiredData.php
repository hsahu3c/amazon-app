<?php

namespace App\Frontend\Components\AmazonMulti;

use App\Core\Components\Base;
class FreshSalesRequiredData extends Base
{
    public function getProductAndOrdersCount (): void{
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $product = $mongo->getCollectionForTable('product_container');
        $order = $mongo->getCollectionForTable('order_container');
        $user = $mongo->getCollectionForTable('user_details');

        $users = $user->find(['user_status'=>"active","shops"=>['$exists'=>true]])->toArray();
        foreach($users as $value){
            $product_count = $product->count(["marketplace.status"=>"Active","user_id"=>$value['user_id']]);
            $order_count = $order->count(['object_type'=>"source_order",'user_id'=>$value['user_id']]);
            $eventsManager = $this->di->getEventsManager();
            $eventsManager->fire('application:orderProductCount', $this, ['username'=>$value['username'],
                    'name'=>$value['name'],
                    'email'=>$value['email'],
                    'shops'=>$value['shops'],
                    'data'=>['products'=>$product_count,"orders"=>$order_count]]);
        }
    }

   
    public function sendGMVData(): void{
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $user = $mongo->getCollectionForTable('user_details');
        $sales = $mongo->getCollectionForTable('sales_container');
        $users = $user->find(['user_status'=>"active","shops"=>['$exists'=>true]])->toArray();
        foreach($users as $value){
            $aggregate = [];
            $aggregate[]= ['$match'=>['user_id'=>$value['user_id']]];
            $aggregate[] = ['$addFields' => ['order_date' => ['$substr' => ['$order_date', 0, 7]]]];
            $aggregate[]= ['$match'=>['order_date'=> date("Y-m",strtotime("-1 month"))]];
            $aggregate[] = ['$group' =>
            [
                '_id' => ['order_date' => '$order_date', 'user_id' => '$user_id', 'marketplace_currency' => '$marketplace_currency'],
                'totalOrderCount' => ['$sum' => '$order_count'],
                'totalRevenue' => ['$sum' => '$order_amount']
            ]];
            $aggregate[] = ['$addFields' => [
                'currency' => '$_id.marketplace_currency',
                'user_id' => '$_id.user_id',
                'order_date' => '$_id.order_date'
            ]];
            $eventsManager = $this->di->getEventsManager();
            $eventsManager->fire('application:gmvCount', $this, ['username'=>$value['username'],
                    'name'=>$value['name'],
                    'email'=>$value['email'],
                    'shops'=>$value['shops'],
                    'data'=>$sales->aggregate($aggregate)->toArray()]);
        }
        }
   
}