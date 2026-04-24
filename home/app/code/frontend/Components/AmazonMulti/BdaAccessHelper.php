<?php

namespace App\Frontend\Components\AmazonMulti;

use App\Core\Components\Base;
use App\Core\Models\User;
class BdaAccessHelper extends Base
{
    public function getBDAs()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        // get acl role resource ids
        $roleCollection = $mongo->getCollectionForTable("acl_role");
        $roleIDs = $roleCollection->find(['code' => ['$in' => ['bda', 'bda_tl']]])->toArray();

        $allBda = [];
        if (count($roleIDs) > 0) {
            foreach ($roleIDs as $val) {
                $allBda[$val->code] = $val->id;
            }
        }

        //user details operations
        $usersCollection = $mongo->getCollectionForTable("user_details");
        $all_bda_data = $usersCollection->aggregate([
            [
                '$match' => [
                    'role_id' => ['$in' => array_values($allBda)]
                ]
            ],
            [
                '$group' => [
                    '_id' => '$role_id',
                    'documents' => ['$push' => '$$ROOT']
                ]
            ],
            ['$project' => [
                'documents' => [
                    '$map' => [
                        'input' => '$documents',
                        'as' => 'doc',
                        'in' => [
                            // Define the fields you want to keep in each document within 'documents'
                            'username' => '$$doc.username',
                            'user_id' => '$$doc.user_id',
                            'status' => '$$doc.status',
                            'email' => '$$doc.email',
                        ]
                    ]
                ]
            ]]
        ])->toArray();

        $response = [];
        if (count($all_bda_data) > 0) {
            foreach ($all_bda_data as $items) {
                if ((string)$items->_id === (string)$allBda['bda']) {
                    $response["bda"] = $items->documents;
                } else if ((string)$items->_id === (string)$allBda['bda_tl']) {
                    $response["bda_tl"] = $items->documents;
                }
            }
        }

        return ['success' => true, "data" => $response];
    }

    public function createBDA($data)
    {
        if (isset($data['username']) && isset($data['email']) && isset($data['password'])) {
            $userData = ['username' => $data['username'], 'email' => $data['email'], "password" => $data['password']];
            $user = new User;
            $payload = ["username" => $data['username'], "email" => $data['email']];
            $this->di->getObjectManager()->get(Helper::class)->createActivityLog(['action_type' => "bda_add", "message" => "added new BDA user (username:" . $data['username'] . ")", "payload" => $payload]);
            return ($user->createUser($userData, 'bda'));
        }

        return ['success' => false, "message" => "username or email or password missing"];
    }

    public function updateStatusBDA($data)
    {
        if (isset($data['user_id']) && (isset($data['status']) || isset($data['role']))) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable("user_details");
            if (isset($data['role'])) {
                $roleCollection = $mongo->getCollectionForTable("acl_role");
                $roleID = $roleCollection->findOne(['code' => $data['role']]);
                if (count($roleID)) {
                    $collection->updateOne(
                        ["user_id" => $data['user_id']],
                        ['$set' => ['role_id' => $roleID->id]]
                    );
                    $this->di->getObjectManager()->get(Helper::class)->createActivityLog([
                        'action_type' => "bda_update",
                        "message" => "updated BDA role to " . $roleID['title'] . "  (username:" . $data['username'] . ")",
                        "payload" => $data
                    ]);
                } else {
                    return ['success' => false, 'message' => 'Role Not Found'];
                }
            } else {
                $status = (int)$data['status'];
                $confirmation = $data['status'] == 2 ? 1 : 0;
                $collection->updateOne(["user_id" => $data['user_id']], ['$set' => ['status' => $status, "confirmation" => $confirmation]]);
                $this->di->getObjectManager()->get(Helper::class)->createActivityLog([
                    'action_type' => "bda_update",
                    "message" => "updated BDA status to " . ($data['status'] === '2' ? "active" : "inactive") . "  (username:" . $data['username'] . ")",
                    "payload" => $data
                ]);
            }

            return ['success' => true, 'message' => 'Document updated successfully'];
        }

        return ['success' => false, "message" => "user_id or required params is missing"];
    }

    public function getActivityLogs($data)
    {
        // print_r($data);
        $helper = $this->di->getObjectManager()->get(Helper::class);
        $count = 10;
        $activePage = 1;
        if (isset($data['count'])) {
            $count = $data['count'];
        }

        if (isset($data['activePage'])) {
            $activePage = $data['activePage'] - 1;
        }

        $skip = (int)$count * $activePage;


        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("activity_logs");
        $filters = isset($data['filter']) ? $data : [];
        $aggregate[] = ['$sort' => ["_id" => -1]];
        if (count($filters)) {
            $conditionalQuery['$and'][] = $helper->searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $aggregate[] = ['$skip' => $skip];
        $aggregate[] = ['$limit' => (int)$count];

        $logs = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'data' => $logs, 'count' => $collection->count($helper->searchMongo($filters))];
    }


    public function getUserActivityLogs($data)
    {
        $helper = $this->di->getObjectManager()->get(Helper::class);
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("activity_logs");
        $filters = isset($data['filter']) ? $data : [];
        $aggregate[] = ['$sort' => ["_id" => -1]];
        $aggregate[] = ['$match' => ['user_id' => $data['user_id']]];
        if (count($filters)) {
            $conditionalQuery['$and'][] = $helper->searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $logs = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'data' => $logs, 'count' => $collection->count($helper->searchMongo($filters))];
    }

    public function getActionTypes($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('activity_logs');
        $res = $collection->aggregate(
            [['$group' => ['_id' => '$action_type', 'total' => ['$sum' => 1]]], ['$group' => ['_id' => null, 'action_type' => ['$push' => ['label' => '$_id', 'value' => '$_id']]]]]
        )->toArray();
        return ['success' => true, 'data' => $res[0]['action_type']];
    }

    public function getBdaAcl($data)
    {
        if (isset($data['user_id'])) {
            $user_id = $data['user_id'];
        } else {
            $user_id = $this->di->getUser()->id;
        }

        $configUserId =  $this->di->getConfig()->get('adminPanel_bda_acl')->toArray();
        if (in_array($user_id, $configUserId)) {
            return ['success' => true, 'message' => 'Permission Granted to ' . $user_id];
        }
        return ['success' => false, 'message' => 'Permission Denied to ' . $user_id];
    }
}
