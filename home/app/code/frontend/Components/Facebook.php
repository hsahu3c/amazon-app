<?php

namespace App\Frontend\Components;

use App\Core\Components\Base;
class Facebook extends Base
{
    public function getconfig($uid){
        $u=$uid['uid'];
        $obj = $this->di->getObjectManager()->get('App\Core\Models\User\Config');

        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $facebook_details = $user_details->getDataByUserID($uid['uid'], 'facebook');
        $shopify_details = $user_details->getDataByUserID($uid['uid'], 'shopify');
        $store_type = $shopify_details['form_details']['catalog_sync_only'];

        if ( isset($store_type) ) {
            $store_type = (string)$store_type;
        } else {
            $store_type = "0";
        }

        $ar = $obj->get($u);
        $step =(int)$ar[0]['value']-1;
        if($step < 0){
            $step = 0;
        }

       $config=[
//           [
//               'code' => 'text_field',
//               'title' => 'Reason',
//               'help' => "To move merchant to desired step.",
//               'value' => "vfhdbjhbvfj" ,
//               'required' => false,
//               'type' => 'textfield',
//               'helptext' => "you can mention the cause",
//           ],
           [
            'code' => 'Step_back',
            'title' => 'Step back',
            'help' => "To move merchant to desired step.",
            'value' => $step,
            'required' => false,
            'type' => 'select',
            'options' => [
                '0' => 'step 1',
                '1' => 'step 2',
                '2' => 'step 3',
                '3' => 'step 4',
                '4' => 'step 5',
                '5' => 'COMPLETED'
            ],
           ],
           [
               'code' => 'catalog_sync_only',
               'title' => 'Store Type',
               'help' => "Set the store to marketplace ot catalog only",
               'value' => $store_type,
               'required' => false,
               'type' => 'select',
               'options' => [
                   '0' => 'FB & Insta Checkout',
                   '1' => 'catalog only',
               ],
           ],
//           [
//               'code' => 'button_test',
//               'title' => 'BUtton',
//               'help' => "To move merchant to desired step.",
//               'value' => "TEST" ,
//               'required' => false,
//               'type' => 'button',
//           ],
//           [
//               'code' => 'buttonfft',
//               'title' => 'BUtton',
//               'help' => "To move merchant to desired step.",
//               'value' => "hfdvhjc" ,
//               'required' => false,
//               'type' => 'button',
//           ]
       ];
       return ['success'=>true,'data'=>$config];
    }

    public function saveconfig($data){
        if ( !isset($data['code']) ) $data['code'] = '';

            switch ($data['code']) {
                case "Step_back" :
                    $obj = $this->di->getObjectManager()->get('\App\Facebookhome\Models\SourceModel');
                    $response = $obj->stepbackuser($data['user_id'], $data['value']);
                    if($response){
                        return ['success'=>true,'message'=>'User '.$data['user_id'].' moved to '.$data['value']];
                    }

                    return $response;
                case "catalog_sync_only" :
                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $collection = $mongo->getCollectionForTable('user_details');
                    $query = ['user_id' => $data['user_id']];
                    $filter = [
                        '$set' => [
                            'shops.0.form_details.catalog_sync_only' => (int)$data['value']
                        ]
                    ];
                    $collection->updateOne($query, $filter);
                    return ['success' => true, 'message' => 'updated!!'];
                default :
                    $start_time = microtime(true);

                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

                    $end_time = microtime(true);
                    $time = $end_time - $start_time;
                    echo PHP_EOL . "CASE 1" . PHP_EOL;
                    print_r($time);
                    echo PHP_EOL;

                    $collection = $mongo->getCollectionForTable('webhook_bulk');

                    $end_time = microtime(true);
                    $time = $end_time - $start_time;
                    echo PHP_EOL . "CASE 2" . PHP_EOL;
                    print_r($time);
                    echo PHP_EOL;

                    print_r($collection->findOne());

                    $end_time = microtime(true);
                    $time = $end_time - $start_time;
                    echo PHP_EOL . "CASE 3" . PHP_EOL;
                    print_r($time);
                    echo PHP_EOL;

                    print_r("WRONG CASE");
                    print_r($data);
                    die();
                    return ['success' => false, 'message' => 'WRONG CASE SLEETEC'];

            }

        return true;
    }
}