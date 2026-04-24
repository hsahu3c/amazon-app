<?php

namespace App\Connector\Components;

use Phalcon\Events\Event;

class ActivityLogEvent extends \App\Core\Components\Base
{
    /**
     * @param $myComponent
     */
    public function createActivityLog(Event $event, $myComponent, $data)
    {  
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("activity_logs");

        $logData = [
        "app_tag"=>$this->di->getAppCode()->getAppTag()??"",
        "user_id" => $this->di->getUser()->id ?? "",
        "username" => $this->di->getUser()->username ?? "",
        "created_at" => date('c'),
        "role" => $this->di->getUser()->role->code ?? "",
        "action_type" => $data['action_type'] ?? "",
        "action" => $data['action'],
        "payload" => $data['payload'] ?? []
    ];
    $token =  $this->di->getRegistry()->getDecodedToken();
    if(isset($token['bda_user_id']) && isset($token['bda_username'])){
        $logData['bda_user_id']=$token['bda_user_id']??"";
        $logData['bda_username'] = $token['bda_username']??"";
        $logData['role'] = $token['bda_role']??"";
       }

    $collection->insertOne($logData);
    return ['success' => true];
    }
    
}
