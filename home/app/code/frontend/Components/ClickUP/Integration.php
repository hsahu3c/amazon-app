<?php

namespace App\Frontend\Components\ClickUP;

use App\Core\Components\Base;
use MongoDB\BSON\UTCDateTime;
use App\Frontend\Components\AdminpanelHelper;
use App\Core\Models\BaseMongo;
use App\Core\Models\User;
use Exception;
class Integration extends Base
{
    // const list_id = 49207275; //Amazon-Admin-Panel-Intergeration-demo
    // const list_id = 49279856; //New Client List
    public const list_id=43332168; //Amazon _Client Management


    public function getDropDownFormatted($name, $options)
    {
        $formatted=[];
        foreach ($options as $value) {
            if ($name=='Plan_Availed_MultichannelA') {
                $Valuename=trim(explode("-", (string) $value['name'])[0]);
            } else {
                $Valuename=trim((string) $value['name']);
                if ($Valuename=='READY TO UNINSTALL') {
                    $Valuename='ready_to_uninstall';
                }
            }

            $formatted=$formatted+[
                $Valuename=>$value['id']
            ];
        }

        return $formatted;
    }

    public function getFormattedFields($dropDowns)
    {
        // $dropDowns=["CONNECTED_ON_ST","Shopify_Plan_Updated","Config_Status_Shopify","Install_Status_Shopify","Plan_Availed_MultichannelA","Number_of_Connected_Accounts_Amazon"];
        $crude = $this->di->getObjectManager()->get(CrudOP::class);
        $fields=$crude->getFields(self::list_id);
        $customFileds=[];
        $customDropDown=[];
        foreach ($fields['fields'] as $value) {
            if ($value['type']!="location") {
                $customFileds=$customFileds+[
                $value['name']=>$value['id']
            ];
                $getDropDownFormatted=[];
                if (in_array($value['name'], $dropDowns)) {
                    $getDropDownFormatted=$this->getDropDownFormatted($value['name'], $value['type_config']['options']);
                    $customDropDown=$customDropDown+[
                    $value['name']=>$getDropDownFormatted
                ];
                }
            }
        }

        return ['customFileds'=>$customFileds ,'customDropDown'=>$customDropDown];
    }

    public function appendTodayInstalledFilter($params)
    {
        $yesterdayDate=date('Y-m-d',strtotime("-1 days"));
        $todayDate=date('Y-m-d');

//        $yesterdayDate = '2023-05-01';

        $filter=[
            'install_at'=>[
                '7'=>[
                    'from'=>$yesterdayDate,
                    'to'=>$todayDate
                ]
            ]
            /*'$exists' => [
                '8' => 'click_up_task_id'
            ]*/
        ];
        $params['filter']=$filter;
        return $params;
    }

    public function appendUserIdFilter($params,$singleUser)
    {
        $filter=[
            'user_id'=>[
                '1'=>$singleUser

            ]
        ];
        $params['single_user_task'] = true;
        $params['filter'] = $filter;
        return $params;
    }

    public function appendTodayPlanFilter($params)
    {
        $yesterdayDate=date('Y-m-d',strtotime("-1 days"));
        $todayDate=date('Y-m-d');
        $filter=[
            'Plan_Availed_At_Amazon'=>[
                '7'=>[
                    'from'=>$todayDate,
                    'to'=>$todayDate
                ]
            ]
        ];
        $params['filter']=$filter;
        return $params;
    }

    public function appendTodayUninstall($params)
    {
        // $yesterdayDate=date('Y-m-d',strtotime("-1 days"));
        $yesterdayDate='2021-10-10';
        $todayDate=date('Y-m-d');
        $filter=[
            'Uninstall_At_Multichannel'=>[
                '7'=>[
                    'from'=>'2023-02-01',
                    'to'=>'2023-02-02'
                ]
            ]
        ];
        $params['filter']=$filter;
        return $params;
    }

    public function appendTodayConnectedFilter($params)
    {
        // $yesterdayDate="2021-10-10";
        $yesterdayDate=date('Y-m-d',strtotime("-1 days"));
        $todayDate=date('Y-m-d');

        $filter=[
            'shops.1.created_at'=>[
                '7'=>[
                    'from'=> new UTCDateTime(strtotime($yesterdayDate) * 1000),
                    'to'=> new UTCDateTime(strtotime($todayDate) * 1000)
                ]
            ]
        ];
        $params['filter']=$filter;
        return $params; 
    }

    public function getUserRows($params)
    {
        $helper = $this->di->getObjectManager()->get(AdminpanelHelper::class);
        $data = $helper->getAllUsersForClickUpSync($params);
        return $data['data']['rows'];
    }

    public function getUserDetailsMongoCollection()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
//        $mongo->setSource("user_details");
        return $mongo->getCollection("user_details");
    }

    public function findLocation(){
        $params = [
            'activePage' => 1,
            'count' => 500,
            'app_code' => 'amazon_sales_channel',
            'marketplace' => 'amazon',
            'productOnly' => true
        ];
        $rows=$this->getUserRows($params);
        $allLocation=[];
        // while(count($rows)!=0){
            foreach($rows as $value){
                foreach($value as $keynam=>$valueit){
                    if($keynam=='Seller_Country_Amazon'){
                        if(!(in_array($valueit,$allLocation))){
                            $allLocation[]=$valueit;
                        }
                    }
                }
            }

        // }
        $params['activePage']=$params['activePage']+1;
        // $rows=$this->getUserRows($params);
        return $rows;
    }

    public function updateFieldsAgain($sqsData)
    {
        $params = $sqsData['data']['params'];
        $paramsSqs = [
            'activePage' => $params['activePage'],
            'count' => 1,
            'app_code' => 'amazon_sales_channel',
            'marketplace' => 'amazon',
            'productOnly' => true
        ];
        // $params=$this->appendTodayConnectedFilter($paramsSqs);
        // $params = $sqsData['data']['params'];
        $rows = $this->getUserRows($this->appendTodayConnectedFilter($paramsSqs));
        // print_r($sqsData);
        echo 'cpunt='.count($rows);
        if (count($rows) == 0) {
            $message = "Completed";
            echo "completedagain";
            $this->di->getObjectManager()->get('\App\Connector\Components\Helper')
                ->addNotification($sqsData['user_id'], $message, 'success');
            $this->di->getObjectManager()->get('App\Connector\Components\Helper')
                ->updateFeedProgress($sqsData['data']['feed_id'], 100, $message);
            return true;
        }
        $crude = $this->di->getObjectManager()->get(CrudOP::class);
        foreach ($rows as $value) {
            $details = $value;
            $toUpdateField=$sqsData['field_to_update'];
            $customFileds=$sqsData['custom_fields'];
            $dropDowns=$sqsData['drop_downs'];
            $customDropDown=$sqsData['custom_drop_down'];
            $fieldID= $customFileds[$toUpdateField];
            if (in_array($toUpdateField, $dropDowns)) {
                $fieldValue=['value'=>$customDropDown[$toUpdateField][$details[$toUpdateField]]];
            } elseif ($toUpdateField=="install_at_Shopify" || $toUpdateField =="Plan_Availed_At_Amazon" || $toUpdateField=='Uninstall_At_Multichannel') {
                $fieldValue=['value'=>strtotime((string) $details[$toUpdateField]).'000'];
                if($fieldValue['value']=='000'){
                    $fieldValue['value']=null;
                }
            } else {
                $fieldValue=['value'=>$details[$toUpdateField]];
            }

            // $fieldValue=['value'=>'hg'];
            if ($details['shop_url']) {
                if (isset($details['click_up_task_id'])) {
                    echo $details['click_up_task_id'];
                    if($fieldValue['value']){
                    $response= $crude->updateField($details['click_up_task_id'], $fieldID, $fieldValue, self::list_id);
                    }
                } else {
                    echo "no task id found";
                }
            }
        }

        $sqsData['limit_remaining']=$response['headers']['X-RateLimit-Remaining'][0];
        echo $params['activePage'];
        $params['activePage'] = $params['activePage'] + 1;
        $sqsData['data']['params'] = $params;
        $message = "Task Creation in progress";
        $this->di->getObjectManager()->get('App\Connector\Components\Helper')
            ->updateFeedProgress($sqsData['data']['feed_id'], 0, $message);
        $this->pushToQueue($sqsData);
    }

    public function updateFields($sqsData)
    {
        // return true;
        $params = $sqsData['data']['params'];
        // $params = $sqsData['data']['params'];
        $rows = $this->getUserRows($params);
        // print_r($sqsData);
        echo 'cpunt='.count($rows);
        if (count($rows) == 0) {
            $message = "Completed";
            echo "completedagain";
            $this->di->getObjectManager()->get('\App\Connector\Components\Helper')
                ->addNotification($sqsData['user_id'], $message, 'success');
            $this->di->getObjectManager()->get('App\Connector\Components\Helper')
                ->updateFeedProgress($sqsData['data']['feed_id'], 100, $message);
            return true;
        }
        $crude = $this->di->getObjectManager()->get(CrudOP::class);
        foreach ($rows as $value) {
            $details = $value;
            $toUpdateField=$sqsData['field_to_update'];
            $customFileds=$sqsData['custom_fields'];
            $dropDowns=$sqsData['drop_downs'];
            $customDropDown=$sqsData['custom_drop_down'];
            $fieldID= $customFileds[$toUpdateField];
            if (in_array($toUpdateField, $dropDowns)) {
                $fieldValue=['value'=>$customDropDown[$toUpdateField][$details[$toUpdateField]]];
            } elseif ($toUpdateField=="install_at_Shopify" || $toUpdateField =="Plan_Availed_At_Amazon" || $toUpdateField=='Uninstall_At_Multichannel') {
                $fieldValue=['value'=>strtotime((string) $details[$toUpdateField]).'000'];
                if($fieldValue['value']=='000'){
                    $fieldValue['value']=null;
                }
            } else {
                $fieldValue=['value'=>$details[$toUpdateField]];
            }

            // $fieldValue=['value'=>'hg'];
            if ($details['shop_url']) {
                if (isset($details['click_up_task_id'])) {
                    echo $details['click_up_task_id'];
                    if($fieldValue['value']){
                    $response= $crude->updateField($details['click_up_task_id'], $fieldID, $fieldValue, self::list_id);
                    }
                } else {
                    echo "no task id found";
                }
            }
        }

        $sqsData['limit_remaining']=$response['headers']['X-RateLimit-Remaining'][0];
        echo $params['activePage'];
        $params['activePage'] = $params['activePage'] + 1;
        $sqsData['data']['params'] = $params;
        $message = "Task Creation in progress";
        $this->di->getObjectManager()->get('App\Connector\Components\Helper')
            ->updateFeedProgress($sqsData['data']['feed_id'], 0, $message);
        $this->pushToQueue($sqsData);
    }

    public function CreateWorker($data)
    {
        $dropDowns=["CONNECTED_ON_ST","Shopify_Plan_Updated","Config_Status_Shopify","Install_Status_Shopify","Plan_Availed_MultichannelA","Number_of_Connected_Accounts_Amazon","amazon_location_dropdown"];
        $custom_field_formatted=$this->getFormattedFields($dropDowns);


        $customFileds=$custom_field_formatted['customFileds'];
        $customDropDown=$custom_field_formatted['customDropDown'];
        $userId = $this->di->getUser()->id;
        $params=$data['params'];

        /*$queueData = [
            'userId' => '6362407be6999614ac39a5d9',
            'message' => 'Task Creation in Progress',
            'process_code' => 'clickup_sync',
            'marketplace' => 'amazon',
            'tag' => 'click_up_task',
            'shop_id' => '41495'
        ];
        $queuedTask = new \App\Connector\Models\QueuedTasks;
        $queuedTaskId = $queuedTask->setQueuedTask('41495', $queueData);*/


        $handlerData = [
            'type' => 'full_class',
            'class_name' => \App\Frontend\Components\ClickUP\Integration::class,
            'method' => $data['methodName'],
            'queue_name' => $data['queueName'] ?? 'admin_panel_clickup_integration',
//            'queue_name' => 'new_webhook_temp_check',
            'user_id' => $userId,
            'app_code' => $params['app_code'],
            'drop_downs'=>$dropDowns,
            'custom_fields'=>$customFileds,
            'custom_drop_down'=>$customDropDown,
            'field_to_update'=>$data['fieldUpdateName'] ?? '',
            'data' => [
                //'feed_id' => $queuedTaskId,
                'cursor' => 1,
                'skip' => 0,
                'limit' => 70,
                'params' => $params,
                'target_marketplace' => $params['marketplace'],
            ]
        ];

//        $rmqHelper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
//        $rmqHelper->createQueue($handlerData['queue_name'], $handlerData);

        $createQueue = $this->di->getMessageManager()->pushMessage($handlerData);

        return 'start';
    }

    public function CreateTaskOnClickUP($data=[],$singleUser = '')
    {
        //$singleUser = "636b989974d887e1d10029f2";
        $data['methodName'] ='CreateTask1by1';
        $data['workerName'] ='admin_panel_clickup_integration';
        $data['queueName'] = 'admin_panel_clickup_integration';
        $params = [
            'activePage' => 1,
            'count' => 1,
            'app_code' => 'amazon_sales_channel',
            'marketplace' => 'amazon',
            'productOnly' => true
        ];

        if($singleUser != '')
        {
            //this condition will work for the creation of task from admin panel, based on single user id
            $data['params']=$this->appendUserIdFilter($params,$singleUser);
        }
        else{
            $data['params']=$this->appendTodayInstalledFilter($params);
        }

        // $data['params']=$params;
        $this->CreateWorker($data);
        return ['success'=>'true','message'=>"process initiated"];
    }

    public function CreateTask1by1_bkp($sqsData)
    {
        // $data=
        $params = $sqsData['data']['params'];
        $rows = $this->getUserRows($params);

        $mongoCollection = $this->getUserDetailsMongoCollection();

        if (count($rows) == 0) {
            $message = "Completed";
            echo "completed";
            $this->di->getObjectManager()->get('\App\Connector\Components\Helper')
                ->addNotification($sqsData['user_id'], $message, 'success');
            $this->di->getObjectManager()->get('App\Connector\Components\Helper')
                ->updateFeedProgress($sqsData['data']['feed_id'], 100, $message);
            return true;
        }
        $crude = $this->di->getObjectManager()->get(CrudOP::class);
        $bulkOpArray=[];
        foreach ($rows as $value) {
            $details = $value;
            $fields = [
                'name' => $details['shop_url']
            ];
            $customFileds=$sqsData['custom_fields'];
            $fields['check_required_custom_fields']=true;

            // $comMediumId=$sqsData['com_medium_ids'];
            $dropDowns=$sqsData['drop_downs'];
            $customDropDown=$sqsData['custom_drop_down'];

            $customFiledsToAppend=[];
            foreach ($customFileds as $name=>$id) {
                if (isset($details[$name])) {
                    if (in_array($name, $dropDowns)) {
                        $customFiledsToAppend[]=[
                        'id'=>$id,
                        'value'=>$customDropDown[$name][$details[$name]]
                        ];
                    } elseif ($name=="install_at_Shopify" || $name =="Plan_Availed_At_Amazon" || $name=='Uninstall_At_Multichannel') {
                        $customFiledsToAppend[]=[
                        'id'=>$id,
                        'value'=>strtotime((string) $details[$name]).'000'
                        ];
                    } else {
                        $customFiledsToAppend[]=[
                        'id'=>$id,
                        'value'=>strval($details[$name])
                        ];
                    }
                }
            }

            $fields['custom_fields']=$customFiledsToAppend;
            if ($fields['name']) {
                if (isset($details['click_up_task_id'])) {
                    $response= $crude->updateTask($details['click_up_task_id'], $fields, self::list_id);
                } else {
                    $response = $crude->createTask(self::list_id, $fields);
                }

                if (isset($response['new_task_created'])) {
                    if (isset($response['id'])) {
                        $bulkOpArray[] = [
                        'updateOne' => [
                            ['user_id'=>$details['user_id']],
                            [
                                '$set' => ['click_up_task_id'=>$response['id']]
                            ],
                        ],
                    ];
                    }
                }
            }
        }

        echo $params['activePage'];
        if ($bulkOpArray[0]!=null) {
            $bulkObj = $mongoCollection->BulkWrite($bulkOpArray, ['w' => 1]);
        }

        $sqsData['limit_remaining']=$response['headers']['X-RateLimit-Remaining'][0];
        $params['activePage'] = $params['activePage'] + 1;
        $sqsData['data']['params'] = $params;
        $message = "Task Creation in progress";
        /*$this->di->getObjectManager()->get('App\Connector\Components\Helper')
          ->updateFeedProgress($sqsData['data']['feed_id'], 0, $message);*/
        $this->pushToQueue($sqsData);
    }

    public function CreateTask1by1($sqsData)
    {
        $params = $sqsData['data']['params'];
        $params['limit'] = $sqsData['data']['limit'] ?? 10;
        $rows = $this->getUserRows($params);
        $mongoCollection = $this->getUserDetailsMongoCollection();
        $task_id_present = [];
        $task_id_absent = [];
        $response_check = [];

        if (count($rows) == 0) {
            echo "completed";

            return ['status' => false, 'requeue' => false, 'message' => 'no more users found for creating task'];
        }
        $crude = $this->di->getObjectManager()->get(CrudOP::class);
        $bulkOpArray=[];
        foreach ($rows as $value)
        {
            $this->di->getLog()->logContent(print_r('=================================================================================================', true), 'info', 'click_up_cron'.DS.date('d-m-Y').DS.'log.log');

            $details = $value;

            $user_id = $value['user_id'];

            print_r($user_id);
            print_r('=========');

            if(!isset($details['click_up_task_id']))
            {
                print_r('click_up');
                print_r('=========');

                $this->di->getLog()->logContent(print_r(['in loop $user_id => '.$user_id],true), 'info', 'click_up_cron'.DS.date('d-m-Y').DS.'log.log');

                $fields['name'] = $details['shop_url'] ?? '';

                if ($fields['name'] != '')
                {
                    $customFileds = $sqsData['custom_fields'];
                    $dropDowns = $sqsData['drop_downs'];
                    $customDropDown = $sqsData['custom_drop_down'];
                    $customFiledsToAppend = [];

                    foreach ($customFileds as $name=>$id)
                    {
                        if (isset($details[$name]))
                        {

                            if ($name=="install_at_Shopify" || $name =="Plan_Availed_At_Amazon" || $name=='Uninstall_At_Multichannel')
                            {
                                $customFiledsToAppend[]=[
                                    'id'=>$id,
                                    'value'=>strtotime((string) $details[$name]).'000',
                                    'name' => $name

                                ];
                            }
                            else
                            {
                                if (in_array($name, $dropDowns) )
                                {
                                    if(isset($customDropDown[$name][$details[$name]]) )
                                    {
                                        $customFiledsToAppend[]=
                                            [
                                                'id'=>$id,
                                                'value'=>$customDropDown[$name][$details[$name]],
                                                'name' => $name
                                            ];
                                    }

                                }
                                else
                                {
                                    $customFiledsToAppend[]=[
                                        'id'=>$id,
                                        'value'=>strval($details[$name]),
                                        'name' => $name

                                    ];
                                }
                            }
                        }
                    }

                    $fields['custom_fields'] = $customFiledsToAppend;
                    /*if (isset($details['click_up_task_id']))
                    {
                        $this->di->getLog()->logContent(print_r(['existing => '.$details['user_id']],true), 'info', 'click_up_cron'.DS.date('d-m-Y').DS.'log.log');

                    }
                    else
                    {
                        $task_id_absent[] = $fields['name'];
                        $this->di->getLog()->logContent(print_r(['request on click up => '.$details['user_id']],true), 'info', 'click_up_cron'.DS.date('d-m-Y').DS.'log.log');

                        $response = $crude->createTask(self::list_id, $fields);
                    }*/

                    $task_id_absent[] = $fields['name'];
                    $this->di->getLog()->logContent(print_r(['request on click up => '.$details['user_id']],true), 'info', 'click_up_cron'.DS.date('d-m-Y').DS.'log.log');

                    $response = $crude->createTask(self::list_id, $fields);

                    if (isset($response['new_task_created']))
                    {
                        $this->di->getLog()->logContent(print_r(['created on click up => '.$details['user_id']],true), 'info', 'click_up_cron'.DS.date('d-m-Y').DS.'log.log');

                        if (isset($response['id']))
                        {
                            $bulkOpArray[] = [
                                'updateOne' => [
                                    ['user_id'=>$details['user_id']],
                                    [
                                        '$set' => ['click_up_task_id'=>$response['id']]
                                    ],
                                ],
                            ];
                        }
                    }

                    $rate_limit_remaining = $response['headers']['X-RateLimit-Remaining'][0];

                    if($rate_limit_remaining <= 0)
                    {
                        break;
                    }
                }
                else{
                    $this->di->getLog()->logContent(json_encode($details), 'info', 'click_up_cron'.DS.date('d-m-Y').DS.'shop_url_not_found.log');

                }
            }
            else
            {
                $this->di->getLog()->logContent(print_r(['existing => '.$details['user_id']],true), 'info', 'click_up_cron'.DS.date('d-m-Y').DS.'log.log');

            }
        }
        if (!empty($bulkOpArray))
        {
            $bulkObj = $mongoCollection->BulkWrite($bulkOpArray, ['w' => 1]);

            $this->di->getLog()->logContent(print_r(['bulkobj' => $bulkObj],true), 'info', 'click_up_cron'.DS.date('d-m-Y').DS.'log.log');

        }
        if(!isset($params['single_user_task']))
        {
            //requeue is needed when in bulk click up task are being created via cron, this code is not required when task is created mannualy
            $sqsData['limit_remaining'] = $response['headers']['X-RateLimit-Remaining'][0] ?? '50';
            $params['activePage'] = $params['activePage'] + 1;
            $sqsData['data']['params'] = $params;

            print_r('requeeue loop');

            $this->pushToQueue($sqsData);

        }
        else{
            return ['status' => false, 'requeue' => false, 'message' => 'processed data for single user task creation'];

        }
    }

    public function searchForTaskIdInSingleAccount($username)
    {
        $response = $this->setDiForUser('63e73c4cfc6f98c09b04219c');
        if (!$response['success']) {
            return $response;
        }


        $db = $this->di->get('singleHome');
        $userCollections = $db->selectCollection('user_details_sc_copy_copy');
        $userInfo = $userCollections->findOne(['username' => $username, 'click_up_task_id' => ['$exists' => true]], ["typeMap" => ['root' => 'array', 'document' => 'array'], "projection" => ["click_up_task_id" => 1, "username" => 1]]);

        if ($userInfo)
        {
            $mongo = $this->di->getObjectManager()->create(BaseMongo::class);
            $mongoCollection = $mongo->getCollectionForTable('user_details');
            //$newDbUser = $mongoCollection->find(['click_up_task_id' => ['$exists' => false]], ["typeMap" => ['root' => 'array', 'document' => 'array'], "projection" => ["_id" => 1, "username" => 1]])->toArray();

            $update_id = $mongoCollection->updateOne([
                'username' => $username
            ], [
                    '$set' => [
                        'click_up_task_id' => $userInfo['click_up_task_id'],
                    ]
                ]
            );
            return ['status' => true, 'data' => $userInfo];
        }
        return ['status' => false];


    }

    private function setDiForUser($user_id): array
    {
        try {
            $getUser = User::findFirst([['_id' => $user_id]]);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        if (empty($getUser)) {
            return [
                'success' => false,
                'message' => 'User not found.'
            ];
        }

        $getUser->id = (string)$getUser->_id;
        $this->di->setUser($getUser);
        if ($this->di->getUser()->getConfig()['username'] == 'admin') {
            return [
                'success' => false,
                'message' => 'user not found in DB. Fetched di of admin.'
            ];
        }

        return [
            'success' => true,
            'message' => 'user set in di successfully'
        ];
    }


    public function updateAccountConnected($data): void
    {
        $data['methodName']='updateFieldsAgain';
        $data['workerName']='admin_clickup_update_connected_acc';
        $data['fieldUpdateName']='Config_Status_Shopify';
        $params = [
            'activePage' => 1,
            'count' => 1,
            'app_code' => 'amazon_sales_channel',
            'marketplace' => 'amazon',
            'productOnly' => true
        ];
        $data['params']=$this->appendTodayConnectedFilter($params);
        // $data['params']=$params;
        $this->CreateWorker($data);
        // $rows= $this->getUserRows($data['params']);
        // return $data['params'];
    }

    public function updatePlan($data): void
    {
        $data['methodName']='updateFields';
        $data['workerName']='admin_clickup_update_plan';
        $data['fieldUpdateName']='Plan_Availed_MultichannelA';
        $params = [
            'activePage' => 1,
            'count' => 1,
            'app_code' => 'amazon_sales_channel',
            'marketplace' => 'amazon',
            'productOnly' => true
        ];
        $data['params']=$this->appendTodayPlanFilter($params);
        // $data['params']=$params;
        $this->CreateWorker($data);
    }

    public function updateUninstallDate($data): void
    {
        $data['methodName']='updateFields';
        $data['workerName']='admin_clickup_update_uninstall_date';
        $data['fieldUpdateName']='Uninstall_At_Multichannel';
        $params = [
            'activePage' => 1,
            'count' => 1,
            'app_code' => 'amazon_sales_channel',
            'marketplace' => 'amazon',
            'productOnly' => true
        ];
        $data['params']=$this->appendTodayUninstall($params);
        // $data['params']=$params;
        $this->CreateWorker($data);
    }

    public function updateNoAmzazonAccConn($data): void
    {
        $data['methodName']='updateFields';
        $data['workerName']='admin_clickup_update_amazon_acc_con';
        $data['fieldUpdateName']='Number_of_Connected_Accounts_Amazon';
        $params = [
            'activePage' => 1,
            'count' => 1,
            'app_code' => 'amazon_sales_channel',
            'marketplace' => 'amazon',
            'productOnly' => true
        ];
        $data['params']=$params;
        $this->CreateWorker($data);
    }

    public function updateAmzonLocationDrop($data): void
    {
        $data['methodName']='updateFields';
        $data['workerName']='admin_clickup_update_amazon_loc_drop';
        $data['fieldUpdateName']='amazon_location_dropdown';
        $params = [
            'activePage' => 1,
            'count' => 1,
            'app_code' => 'amazon_sales_channel',
            'marketplace' => 'amazon',
            'productOnly' => true
        ];
        $data['params']=$params;
        $this->CreateWorker($data);
    }

    public function pushToQueue($sqsData)
    {
        $limitRemaining=$sqsData['limit_remaining'];
        if (isset($sqsData['limit_remaining'])) {
            $time=0;
            if ($limitRemaining<15) {
                $time=60;
            } else {
                $time=0;
            }

            $sqsData['DelaySeconds'] =$time;
            $sqsData['run_after'] =$time;
            $sqsData['delay'] =$time;
        }

        //$rmqHelper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');

        $this->di->getLog()->logContent(print_r(['handler_data' => $sqsData],true), 'info', 'click_up_cron'.DS.date('d-m-Y').DS.'requeue_log.log');
//        print_r('$limitRemaining  ====> '.$limitRemaining);

        return $this->di->getMessageManager()->pushMessage($sqsData);

//        return $rmqHelper->createQueue($sqsData['queue_name'], $sqsData);
    }
}
