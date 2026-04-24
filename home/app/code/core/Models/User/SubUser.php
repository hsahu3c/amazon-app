<?php

namespace App\Core\Models\User;

class SubUser extends \App\Core\Models\BaseMongo
{
    protected $table = 'sub_user';
    protected $isGlobal = true;
    private $_collection;

    const IS_EQUAL_TO = 1;
    const IS_NOT_EQUAL_TO = 2;
    const IS_CONTAINS = 3;
    const IS_NOT_CONTAINS = 4;
    const START_FROM = 5;
    const END_FROM = 6;
    const RANGE = 7;

    public function initialize()
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDefaultDb());
    }

    public function init()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->_collection = $mongo->getCollectionForTable("sub_user");
    }

    public function createUser($data, $registration = false, $autoCreate = false)
    {
        $this->init();
        $errors = false;
        if (isset($data['username']) && isset($data['email']) && isset($data['password'])) {
            if ($this->_collection->findOne(['username' => $data['username']])) {
                $errors[] = 'Username already exist.';
            }
            if (!$errors) {
                $user = $this->di->getUser();
                $data['password'] = $user->hashPassword($data['password']);
                $data['email'] = strToLower($data['email']);

                if ($registration) {
                    $user = \App\Core\Models\User::findFirst([["username" => "admin"]]);
                    $role = \App\Core\Models\Acl\Role::findFirst([["code" => "admin"]]);
                    $data['role_id'] = (string)$role->getId();
                    $data['parent_id'] = (string)$user->getId();
                } else {
                    $data['role_id'] = $user->role_id;
                    $data['parent_id'] = $user->id;
                }
                if (isset($data['resources']) && $data['resources']) {
                    $Role = \App\Core\Models\Acl\Role::findFirst([["_id" => $user->role_id]]);
                    if ($registration) {
                        $data['resources'] = array_values($data['resources']);
                    } elseif ($Role->resources != 'all') {
                        $allResources = \App\Core\Models\Acl\RoleResource::find([["role_id" => $user->role_id]]);
                        $resources = [];
                        foreach ($allResources as $resource) {
                            $resources[] = $resource['resource_id'];
                        }
                        $data['resources'] = array_values(array_intersect($resources, $data['resources']));
                    }
                } else {
                    $data['resources'] = 'all';
                }
                $helper = $this->di->getObjectManager()->get('\App\Core\Components\Helper');
                $data['created_at'] = date('m-d-Y h:i:s a', time());
                if (isset($data['apps'])) {
                    foreach ($data['apps'] as $app_key => $app_val) {
                        $data['apps'][$app_key]['_id'] = $this->getCounter('user_app_id');
                        $keys = $helper->getKeyPair();
                        $data['apps'][$app_key]['public_key'] = $keys['public'];
                        $data['apps'][$app_key]['private_key'] = $keys['private'];
                        $data['apps'][$app_key]['sub_app_status'] = 'active';
                    }
                }
                $keys = $helper->getKeyPair();
                $data['public_key'] = $keys['public'];
                $data['private_key'] = $keys['private'];
                $data['_id'] = $this->getCounter('sub_user_id');
                $data['status'] = 'active';
                if ($autoCreate) {
                    $data['confirmation'] = 0;
                } else {
                    $data['confirmation'] = 1;
                }
                if ($this->_collection->insertOne($data)) {
                    $this->sendWelcomeMail($data);

                    $helper = $this->di->getObjectManager()->get('\App\Core\Components\Helper');
                    $this->di->getLog()->logContent("Before generateChildAcl function call", "sub_user_create.log");
                    $helper->generateChildAcl($data);
                    if ($autoCreate) {
                        $this->sendConfirmationMail($data);
                        $result = [
                            'success' => true,
                            'code' => 'confirmation sent',
                            'message' => 'Check your mail to verify your account.',
                            'data' => $data
                        ];
                    } else {
                        $result = [
                            'success' => true,
                            'code' => 'notification_send',
                            'message' => 'Notification mail has been send to user', 'data' => []
                        ];
                    }
                } else {
                    $messages = $this->getMessages();
                    $errors = [];
                    foreach ($messages as $message) {
                        $errors[] = (string)$message;
                    }
                    $result = ['success' => false, 'code' => 'unknown_error', 'message' => 'Something went wrong', 'data' => $errors];
                }
            } else {
                $result = ['success' => false, 'code' => 'user_already_exist', 'message' => 'Duplicate', 'data' => ['errors' => $errors]];
            }
        } else {
            $result = ['success' => false, 'code' => 'data_missing', 'message' => 'Fill All required fields.', 'data' => []];
        }
        return $result;
    }

    public function sendConfirmationMail($data)
    {
        if (isset($data['confirmation_link'])) {
            $data['confirmation_link'] = "https://apiconnect.sellernext.com/apiconnect/uservalidate/validate";
            $email = $data['email'];
            $date1 = new \DateTime('+24 hour');
            $token = [
                "user_id" => 2,
                "child_id" => $data['_id'],
                "exp" => $date1->getTimestamp()
            ];
            $token = $this
                ->di
                ->getObjectManager()
                ->get('App\Core\Components\Helper')
                ->getJwtToken($token, 'HS256', true, false, false);
            $link = $data['confirmation_link'] . '?token=' . $token;
            $path = 'core' . DS . 'view' . DS . 'email' . DS . 'userconfirm.volt';
            $data['email'] = $email;
            $data['path'] = $path;
            $data['link'] = $link;
            $data['subject'] = 'Account Confirmation Mail';
            $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($data);
        }
    }
    public function updateSubUser($data)
    {
        $this->init();
        $user = $this->di->getUser();
        $subUser = $this->_collection->findOne([
            '$or' => [
                ['_id' => $data['id']],
                ['_id' => (int)$data['id']],
                ['_id' => (string)$data['id']],
            ]
        ]);
        if (count($subUser) > 0) {
            if ($subUser['parent_id'] == $user->id) {
                if (isset($data['password']) && $data['password']) {
                    $data['password'] = $user->hashPassword($data['password']);
                    $this->_collection->updateOne(
                        ['_id' => $subUser['_id']],
                        ['$set' => [
                            'password' => $data['password'],
                        ]]
                    );
                }
                $helper = $this->di->getObjectManager()->get('\App\Core\Components\Helper');
                if (isset($data['resources']) && $data['resources']) {
                    $Role = \App\Core\Models\Acl\Role::findFirst([["_id" => $user->role_id]]);
                    $allResources = \App\Core\Models\Acl\RoleResource::find([["role_id" => $user->role_id]])->toArray();
                    foreach ($allResources as $resource) {
                        $resources[] = $resource['resource_id'];
                    }
                    if ($Role->resources != 'all') {
                        $data['resources'] = array_intersect($resources, $data['resources']);
                    }
                    $this->_collection->updateOne(
                        ['_id' => $subUser['_id']],
                        ['$set' => [
                            'resources' => $data['resources']
                        ]]
                    );
                }
                if (isset($data['apps'])) {
                    $userApps = [];
                    foreach ($subUser['apps'] as $value) {
                        $userApps[$value['app_id']] = $value;
                    }
                    foreach ($data['apps'] as $key1 => $val2) {
                        if (isset($val2['sub_app_status'], $val2['sub_app_id']))
                            $this->di->getCache()
                                ->set("sub_app_status_" . $val2['app_id'] . "_" . $val2['sub_app_id'], $val2['sub_app_status']);
                        if (isset($userApps[$val2['app_id']])) {
                            $value = $userApps[$val2['app_id']];
                            $data['apps'][$key1]['_id'] = $value['_id'];
                            if (!isset($value['public_key'])) {
                                $helper = $this->di->getObjectManager()->get('\App\Core\Components\Helper');
                                $keys = $helper->getKeyPair();
                                $data['apps'][$key1]['public_key'] = $keys['public'];
                                $data['apps'][$key1]['private_key'] = $keys['private'];
                            } else {
                                $data['apps'][$key1]['public_key'] = $value['public_key'];
                                $data['apps'][$key1]['private_key'] = $value['private_key'];
                            }
                        } else {
                            $data['apps'][$key1]['_id'] = $this->getCounter('user_app_id');

                            $keys = $helper->getKeyPair();
                            $data['apps'][$key1]['public_key'] = $keys['public'];
                            $data['apps'][$key1]['private_key'] = $keys['private'];
                        }
                    }
                    $this->_collection->updateOne(
                        ['_id' => $subUser['_id']],
                        ['$set' => [
                            'apps' => $data['apps'],
                        ]]
                    );
                }
                $helper->generateChildAcl($subUser);
                return ['success' => true, 'message' => 'User Details updated successfully.'];
            } else {
                return [
                    'success' => false,
                    'code' => 'not_allowed',
                    'message' => 'You are not allowed to updated the sub user'
                ];
            }
        } else {
            return [
                'success' => false,
                'code' => 'no_customer_found',
                'message' => 'No Customer Found'
            ];
        }
    }

    public function updateSubUserApp($data)
    {
        $this->init();
        $subUser = $this->di->getSubUser();
        if (count($subUser)) {
            $this->_collection->updateOne(
                ['username' => $subUser['username']],
                ['$set' => ['apps' => $data['apps']]]
            );
            return ['success' => true, 'message' => 'Auth Url Successfully Updated'];
        } else {
            return ['success' => false, 'message' => 'Sub User Not Found'];
        }
    }

    public function deleteSubUser($data)
    {
        $this->init();
        $currentUser = $this->di->getUser();
        if (isset($data['id'])) {
            $user = $this->_collection->deleteOne(['parent_id' => $currentUser->id, 'id' => $data['id']]);
        } elseif (isset($data['username'])) {
            $user = $this->_collection->deleteOne(['parent_id' => $currentUser->id, 'username' => $data['username']]);
        } elseif (isset($data['email'])) {
            $user = $this->_collection->deleteOne(['parent_id' => $currentUser->id, 'email' => $data['email']]);
        }
        if ($user->getDeletedCount() > 0) {
            return ['success' => true, 'message' => 'User has been deleted'];
        } else {
            return ['success' => false, 'code' => 'no_customer_found', 'message' => 'User not found'];
        }
    }

    public function login($data)
    {
        $this->init();
        $accountComponent = $this->di->getObjectManager()->get("\App\Core\Components\Account");
        $clientIp = $this->di->getRequest()->getClientAddress();
        $locale = $this->di->getLocale();
        $user = new \App\Core\Models\User;
        if ((isset($data['username']) || isset($data['email']))  && isset($data['password'])) {
            $key = isset($data["username"]) ? "username" : "email";

            if ($key === 'email') $query  =  [$key => new \MongoDB\BSON\Regex('^' . $data[$key] . '$', 'i')];
            else $query = [$key => $data[$key]];

            $subUser = $this->_collection->findOne($query);

            if (!empty($subUser)) {
                if ($subUser->status == 3) {
                    return [
                        'success' => false,
                        'message' => $locale->_("account deactivated"),
                        'code' => 'staus_not_active', 'data' => []
                    ];
                }
                if ($user->checkHash($data['password'], $subUser['password'])) {
                    if ($user->needsRehash($subUser['password'])) {
                        $this->_collection->updateOne(
                            ['_id' => $subUser['_id']],
                            ['$set' => ['password' => $user->hashPassword($data['password'])]]
                        );
                    }
                    // remove all old attempt's data
                    $this->di->getCache()->deleteMultiple([
                        md5($subUser->username . "_sub") . "_temp_block_count",
                        md5($subUser->username . "_sub") . "_lastlogin",
                        md5($subUser->username . "_sub") . "",
                        md5($subUser->email . "_sub") . "_temp_block_count",
                        md5($subUser->email . "_sub") . "_lastlogin",
                        md5($subUser->email . "_sub") . "",
                        md5($clientIp . "_sub") . "_temp_block_count",
                        md5($clientIp . "_sub") . "_lastlogin",
                        md5($clientIp . "_sub") . ""
                    ], "requests");
                    return [
                        'success' => true,
                        'message' => $locale->_("User logged in"),
                        'data' => [
                            'token' => $this->getToken($subUser)
                        ]
                    ];
                }
            }
            return [
                'success' => false,
                'attempts_left' => $accountComponent->handleAttempts($subUser, true),
                'code' => 'invalid_data',
                'message' => $locale->_("invalid creds"),
                'data' => []
            ];
        }
        return [
            'success' => false,
            'attempts_left' => $accountComponent->handleAttempts(new \stdClass(), true),
            'code' => 'data_missing',
            'message' => $locale->_("all required"),
            'data' => []
        ];
    }
    public static function search($filterParams = [], $fullTextSearchColumns = null)
    {
        $conditions = [];
        if (isset($filterParams['filter'])) {
            foreach ($filterParams['filter'] as $key => $value) {
                $key = trim($key);
                if (array_key_exists(SubUser::IS_EQUAL_TO, $value)) {
                    $conditions[] = "" . $key . " = '" . trim(addslashes($value[SubUser::IS_EQUAL_TO])) . "'";
                } elseif (array_key_exists(SubUser::IS_NOT_EQUAL_TO, $value)) {
                    $conditions[] = "" . $key . " != '" . trim(addslashes($value[SubUser::IS_NOT_EQUAL_TO])) . "'";
                } elseif (array_key_exists(SubUser::IS_CONTAINS, $value)) {
                    $conditions[] = "" . $key . " LIKE '%" . trim(addslashes($value[SubUser::IS_CONTAINS])) . "%'";
                } elseif (array_key_exists(SubUser::IS_NOT_CONTAINS, $value)) {
                    $conditions[] = "" . $key . " NOT LIKE '%" . trim(addslashes($value[SubUser::IS_NOT_CONTAINS])) . "%'";
                } elseif (array_key_exists(SubUser::START_FROM, $value)) {
                    $conditions[] = "" . $key . " LIKE '" . trim(addslashes($value[SubUser::START_FROM])) . "%'";
                } elseif (array_key_exists(SubUser::END_FROM, $value)) {
                    $conditions[] = "" . $key . " LIKE '%" . trim(addslashes($value[SubUser::END_FROM])) . "'";
                } elseif (array_key_exists(SubUser::RANGE, $value)) {
                    if (trim($value[SubUser::RANGE]['from']) && !trim($value[SubUser::RANGE]['to'])) {
                        $conditions[] = "" . $key . " >= '" . $value[SubUser::RANGE]['from'] . "'";
                    } elseif (trim($value[SubUser::RANGE]['to']) && !trim($value[SubUser::RANGE]['from'])) {
                        $conditions[] = "" . $key . " >= '" . $value[User::RANGE]['to'] . "'";
                    } else {
                        $conditions[] = "" . $key . " between '" . $value[User::RANGE]['from'] . "' AND '" . $value[SubUser::RANGE]['to'] . "'";
                    }
                }
            }
        }
        if (isset($filterParams['search']) && $fullTextSearchColumns) {
            $conditions[] = " MATCH (" . $fullTextSearchColumns . ") AGAINST ('" . trim(addslashes($filterParams['search'])) . "' IN NATURAL LANGUAGE MODE)";
        }
        $conditionalQuery = "";
        if (is_array($conditions) && count($conditions)) {
            $conditionalQuery = implode(' AND ', $conditions);
        }
        return $conditionalQuery;
    }

    public function getSubUser($data, $limit = 1, $activePage = 1, $filters = [])
    {
        $this->init();
        if ($data && isset($data['id'])) {
            $content = $this->_collection->findOne(['_id' => (string)$data['id']]);
            return ['success' => true, 'message' => '', 'data' => $content];
        } else {
            $user = $this->di->getUser();

            if (!count($filters)) {
                $content = $this->_collection->find(
                    ["parent_id" => $user->id],
                    ['limit' => (int)$limit, 'skip' => (int)(($activePage - 1) * $limit)]
                )->toArray();
                $count = $this->_collection->count(["parent_id" => $user->id]);
                return [
                    'success' => true,
                    'message' => '',
                    'data' => ['rows' => $content, 'count' => $count]
                ];
            }
            else {
                $content = $this->_collection->find(
                    $filters,
                    ['limit' => (int)$limit, 'skip' => (int)(($activePage - 1) * $limit)]
                )->toArray();
                
                $count = $this->_collection->count($filters);
                return [
                    'success' => true,
                    'message' => '',
                    'data' => ['rows' => $content, 'count' => $count]
                ];
            }
        }
    }
    // public function getSubUser($data, $limit = 1, $activePage = 1, $filters = [])
    // {
    //     $this->init();
    //     if ($data && isset($data['id'])) {
    //         $content = $this->_collection->findOne(['_id' => (string)$data['id']]);
    //         return ['success' => true, 'message' => '', 'data' => $content];
    //     } else {
    //         $user = $this->di->getUser();

    //         if (!count($filters)) {
    //             $content = $this->_collection->find(
    //                 ["parent_id" => $user->id],
    //                 ['limit' => (int)$limit, 'skip' => (int)(($activePage - 1) * $limit)]
    //             )->toArray();
    //             $count = $this->_collection->count(["parent_id" => $user->id]);
    //             return [
    //                 'success' => true,
    //                 'message' => '',
    //                 'data' => ['rows' => $content, 'count' => $count]
    //             ];
    //         }
    //     }
    // }

    public function getToken($subUser)
    {
        $date = new \DateTime('+4 hour');
        $token = [
            "user_id" => $subUser['parent_id'],
            "child_id" => $subUser['_id'],
            "role" => 'admin',
            "exp" => $date->getTimestamp()
        ];
        return $this->di->getObjectManager()->get('App\Core\Components\Helper')->getJwtToken($token);
    }

    public function getRole()
    {
        $user = $this->di->getUser();
        return \App\Core\Models\Acl\Role::findFirst([["_id" => $user->role_id]]);
    }

    public function sendWelcomeMail($data)
    {
        if (isset($data['confirmation_link'])) {
            $path = 'core' . DS . 'view' . DS . 'email' . DS . 'subuserwelcome.volt';
            $data['path'] = $path;
            $data['subject'] = 'New User Welcome Mail';
            $token = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($data);
        }
    }

    public function setResources($resources)
    {
        if ($resources == 'all') {
            $this->resources = 'all';
        } else {
            if (!is_array($resources)) {
                throw new \InvalidArgumentException(
                    'Resources should be array of resource ids'
                );
            }
            $this->resources = $resources;
        }
    }

    public function getResources()
    {
        if ($this->resources == 'all') {
            return 'all';
        } else {
            if (is_array($this->resources)) {
                return $this->resources;
            }
            return json_decode($this->resources);
        }
    }

    /**
     * Change subuser status to active/block
     *
     * @param array $data
     * @return void
     */
    public function changeSubUserStatus($data)
    {
        $this->init();
        $currentUser = $this->di->getUser();
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        if (isset($data['userId'], $data['status'])) {
            $parentId  = (string)$currentUser->id;
            $user = $this->_collection->updateOne(
                [
                    'parent_id' => $parentId,
                    '_id' => $data['userId'],
                ],
                [
                    '$set' => [
                        'status' => $data['status'],
                        'apps.$[app].sub_app_status' => $data['status'],
                    ]
                ],
                ['arrayFilters' => [['app.app_id' => ['$exists' => 1]]]]
            );
            $aggregate = [
                ['$match' => ['_id' => $data['userId']]],
                ['$unwind' => '$apps'],
                ['$project' => ['_id' => 0, 'apps.app_id' => 1, 'apps.sub_app_id' => 1]]
            ];
            $subAppIds = $this->_collection->aggregate($aggregate, $options)->toArray();
            foreach ($subAppIds as $subApp) {
                if (isset($subApp['apps']['app_id'], $subApp['apps']['sub_app_id']))
                    $this->di->getCache()->set(
                        "sub_app_status_" . $subApp['apps']['app_id'] . "_" . $subApp['apps']['sub_app_id'],
                        $data['status']
                    );
            }
        }

        if ($user->getModifiedCount() > 0) {
            if ($data['status'] == "active") {
                $this->di->getCache()->set($data['userId'] . "_sub_user_status", $data['status']);
                return ['success' => true, 'message' => 'Sub-user unblocked successfully'];
            } else {
                $this->di->getCache()->set($data['userId'] . "_sub_user_status", $data['status']);
                return ['success' => true, 'message' => 'Sub-user blocked successfully'];
            }
        } else {
            return [
                'success' => false,
                'message' => $this->di->getLocale()->_('changing_status_to', [
                    'status' => $data['status']
                ])
            ];
        }
    }
}
