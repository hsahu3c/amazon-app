<?php

namespace App\Shopifyhome\Components\Analysis\Webhook;

use App\Core\Components\Base;
use App\Shopifyhome\Models\SourceModel;
use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Components\Product\Import;
class WebhookAnalyse extends Base
{
    public function registerWebhooks($mid): void
    {
        $this->di->getObjectManager()->get("\App\Core\Components\Helper")->setUserToken($mid);
        $user_details = $this->di->getObjectManager()->get(SourceModel::class)->registerWebhooks();
        print_r($user_details);
    }

    public function unregisterWebhook($mid): void{

        $remoteShopId =  $this->di->getObjectManager()->get("\App\Core\Models\User\Details")->getDataByUserID($mid, 'shopify')->remote_shop_id;
        $responseWbhook = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify', 'true')
            ->call('/webhook/unregister', [], ['shop_id' => $remoteShopId], 'DELETE');
        print_r($responseWbhook);
    }

    public function displayregisteredWebhooks($mid)
    {
        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();

        $remoteShopId =  $this->di->getObjectManager()->get("\App\Core\Models\User\Details")->getDataByUserID($mid, $target)->remote_shop_id;

        $responseWbhook = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify', 'true')
            ->call('/webhook', [], ['shop_id' => $remoteShopId], 'GET');

        return $responseWbhook;

    }


    public function Register(): void{
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("user_details")->getCollection();
        $userarray = [];
        $userids = ["1373", "1336",'467'];
        $result = $collection->find(["user_id"=>['$in' => $userids]])->toArray();
        foreach ($result as $key => $user) {
            $userdata = [];
            array_push($userdata, $user['user_id']);
            array_push($userdata, $user['shops'][0]['remote_shop_id']);
            array_push($userarray, $userdata);
        }

        $awsConfig = include_once(BP . DS . 'app' . DS . 'etc' . DS . 'aws.php');
        $webhookInfo = [
            'topic' => 'inventory_levels/update',
            'queue_unique_id' => 'sqs-shopify-products-update',
            'queue_name' => 'facebook_webhook_product_update',
            'action' => 'inventory_update'
        ];
        for ($i = 0; $i < count($userarray); $i++){
            $webHook = [
                'type' => 'sqs',
                'queue_config' => [
                    'region' => $awsConfig['region'],
                    'key' => $awsConfig['credentials']['key'],
                    'secret' => $awsConfig['credentials']['secret']
                ],
                'topic' => $webhookInfo['topic'],
                'queue_unique_id' => $webhookInfo['queue_unique_id'],
                'format' => 'json',
                'queue_name' => $webhookInfo['queue_name'],
                'queue_data' => [
                    'type' => 'full_class',
                    'handle_added' => 1,
                    'class_name' => '\App\Connector\Models\SourceModel',
                    'method' => 'triggerWebhooks',
                    'user_id' => $userarray[$i][0],
                    'code' => 'Shopifyhome',
                    'action' => $webhookInfo['action'],
                    'queue_name' => $webhookInfo['queue_name']
                ]
            ];

            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init('shopify', 'true')
                ->call('/webhook/register',[],['shop_id'=> $userarray[$i][1], 'data' => $webHook], 'POST');
            print_r($remoteResponse);
        }
    }

    public function Unregister(): void
    {
        $topic="inventory_levels/update";
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("user_details")->getCollection();
        $userarray = [];
        $userids = ["1373", "1336"];
        $result = $collection->find(["user_id"=>['$in' => $userids]])->toArray();

        foreach ($result as $user) {
            $userdata = [];
            array_push($userdata, $user['user_id']);
            array_push($userdata, $user['shops'][0]['remote_shop_id']);
            array_push($userarray, $userdata);
        }

        for ($i = 0; $i < count($userarray); $i++) {

            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init('shopify', 'true')
                ->call('/webhook', [], ['shop_id' => $userarray[$i][1]], 'GET');


            if ($remoteResponse['success'] && isset($remoteResponse['data'])) {

                $webhooks = $remoteResponse['data'];
                foreach ($webhooks as $value) {
                    if ($value['topic'] == $topic) {
                        $webhookid = $value['id'];
                        $topicval = $value['topic'];
                        $responseWbhook = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                            ->init('shopify', 'true')
                            ->call("/webhook/unregister", [], ['shop_id' => $userarray[$i][1], 'id' => $webhookid, 'topic' => $topicval], 'DELETE');

                        if ($responseWbhook['success']) {
                            print_r("Webhook Deleted successfully for topic" . $topicval);
                            print_r($responseWbhook);
                        }
                    }
                }
            }
        }
    }


    public function manuallylocationImport(): void{
        $handledata=[
            "shop"=>"athletin.myshopify.com",
            "type"=>"full_class",
            "handle_added"=>"1",
            "class_name"=>"\App\Connector\Models\SourceModel",
            "method"=>"triggerWebhooks",
            "user_id"=>'2285',
            "code"=>"Shopifyhome",
            "action"=>"locations_create",
            "queue_name"=>"facebook_webhook_locations_create",
            "data"=>[
                'id'=> 56159797400,
                'name'=> 'Oberlo',
                'address1' => null,
                'address2' =>null,
                'city' => null,
                'zip'=> null,
                'province' => null,
                'country' => 'US',
                'phone' => null,
                'created_at'=> '2020-10-06T06:22:21-04:00',
                'updated_at'=> '2020-10-06T06:22:21-04:00',
                'country_code' => 'US',
                'country_name' => 'United States',
                'province_code' => null,
                'legacy' =>1,
                'active'=> 1,
                'admin_graphql_api_id'=> 'gid://shopify/Location/56159797400'
            ]
        ];

        $this->di->getObjectManager()->get(Import::class)->pushToQueue($handledata);
        die("Location Imported");
    }
}