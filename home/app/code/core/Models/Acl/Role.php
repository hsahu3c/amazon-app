<?php

namespace App\Core\Models\Acl;

use App\Core\Models\Resource;
use App\Core\Models\User;
use App\Core\Models\User\SubUser;

class Role extends \App\Core\Models\BaseMongo
{
    protected $table = 'acl_role';
    protected $isGlobal = true;
    

    public function createRole($data)
    {
        $response = [];
        $response['success'] = false;
        if (
            $data && isset($data['group_code']) &&
            isset($data['title']) &&
            isset($data['resources'])
        ) {
            $isAllAllowed = false;
            $requestedResource = [];
            $availableResource = [];

            if (is_array($data['resources'])) {
                $requestedResource = $data['resources'];
                $resources = Resource::find();
                foreach ($resources as $res) {
                    $availableResource[] = (string)$res->_id;
                }
            } else {
                if ($data['resources'] == 'all') {
                    $isAllAllowed = true;
                } else {
                    $response['message'] = "Some data is missing";
                    return $response;
                }
            }
            try {
                $this->setData([
                    'code' => $data['group_code'],
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'resources' => $isAllAllowed ? 'all' : ''
                ]);
                $success = $this->save();

                if ($success) {
                    if (!$isAllAllowed) {
                        $allowResources = array_intersect($availableResource, $requestedResource);
                        $count = 1;
                        foreach ($allowResources as $allowResource) {
                            $resource = $this->di->getObjectManager()->create('App\Core\Models\Acl\RoleResource')->getCollection();
                            $resource->insertOne([
                                'role_id' => $this->_id,
                                'resource_id' =>  new \MongoDB\BSON\ObjectId($allowResource)
                            ]);
                            $count++;
                        }
                    }
                    $response['message'] = "Role has been created successfully";
                    $response['success'] = true;
                } else {
                    $response['message'] = "Sorry, something bad happen during the role creation: ";
                    $messages = $this->getMessages();
                    foreach ($messages as $message) {
                        $response['data'][] = $message->getMessage();
                    }
                }
            } catch (\Exception $exception) {
                $response['message'] = $exception->getMessage();
            }
        } else {
            $response['message'] = "Some data is missing";
        }
        return $response;
    }
    public function deleteRole($data)
    {
        $response = [];
        $response['success'] = false;
        if ($data) {
            try {
                $role = false;
                if (isset($data['group_code'])) {
                    $role = Role::findFirst([["code" => $data['group_code']]]);
                } elseif (isset($data['id'])) {
                    $role = Role::findFirst([["_id" => $data['id']]]);
                }
                if ($role && $role->_id) {
                    $user = User::find([["role_id" => $role->_id]]);
                    if (count($user) > 0) {
                        $response['message'] =
                            "This Role is assigned to users. Please change their role. Then delete it.";
                    } else {
                        $role->delete();
                        $response['message'] = "Role has been deleted successfully";
                        $response['success'] = true;
                    }
                } else {
                    $response['message'] = "No Role found with given group_code";
                }
            } catch (\Exception $exception) {
                $response['message'] = $exception->getMessage();
            }
        } else {
            $response['message'] = "Data is missing";
        }
        return $response;
    }

    public function updateRole($data)
    {
        $response = [];
        $response['success'] = false;
        if ($data && isset($data['group_code'])) {
            try {
                $role = Role::findFirst([["code" => $data['group_code']]]);
                if ($role) {
                    $isAllAllowed = false;
                    $requestedResource = [];
                    $availableResource = [];
                    unset($data['group_code']);
                    if (isset($data['resources'])) {
                        if (is_array($data['resources'])) {
                            $requestedResource = $data['resources'];
                            $resources = Resource::find();
                            foreach ($resources as $res) {
                                $availableResource[] = $res->_id;
                            }
                        } else {
                            if ($data['resources'] == 'all') {
                                $isAllAllowed = true;
                            }
                        }

                        $data['resources'] = $isAllAllowed ? 'all' : '';
                        $role->setData($data);
                        $success = $role->save();
                        if ($success) {
                            $roleResourceCollection = $this->di
                                ->getObjectManager()
                                ->create('\App\Core\Models\Acl\RoleResource')
                                ->getCollection();
                            $roleResourceCollection->deleteMany(["role_id" => $role->_id]);

                            if (!$isAllAllowed) {
                                $allowResources = array_intersect($availableResource, $requestedResource);
                                $vales_string = '';
                                $count = 1;
                                foreach ($allowResources as $allowResource) {
                                    $roleResourceCollection->insertOne([
                                        "role_id" => $role->_id,
                                        'resource_id' =>  new \MongoDB\BSON\ObjectId($allowResource)
                                    ]);
                                    $count++;
                                }
                            }
                            $response['message'] = "Role has been created successfully";
                            $response['success'] = true;
                        } else {
                            $response['message'] = "Sorry, something bad happened during the role creation: ";
                            $messages = $role->getMessages();
                            foreach ($messages as $message) {
                                $response['data'][] = $message->getMessage();
                            }
                        }
                    } else {
                        $success = $role->save($data, ['title', 'description']);
                        if ($success) {
                            $response['message'] = "Role has been saved successfully";
                            $response['success'] = true;
                        } else {
                            $response['message'] = "Sorry, something bad happened during the role save: ";
                            $messages = $role->getMessages();
                            foreach ($messages as $message) {
                                $response['data'][] = $message->getMessage();
                            }
                        }
                    }
                } else {
                    $response['message'] = 'No role found with given group_code';
                }
            } catch (\Exception $exception) {
                $response['message'] = $exception->getMessage();
            }
        } else {
            $response['message'] = "Data is missing";
        }
        return $response;
    }

    public function getResourcesWithId($resources)
    {
        if (is_object($resources)) {
            $resources = $resources->toArray();
        }
        foreach ($resources as $key => $resource) {
            if (is_object($resource)) {
                $resource = $resource->toArray();
                $resources[$key] = $resource;
            }
            if (isset($resource['_id']) && is_object($resource['_id'])) {
                $resources[$key]['_id'] = (string) $resource['_id'];
                $resources[$key]['role_id'] = (string) $resource['role_id'];
                $resources[$key]['resource_id'] = (string) $resource['resource_id'];
                $resources[$key]['id'] = $resources[$key]['_id'];
            }
        }

        return $resources;
    }
    /*
    get all resources of a role. It return only resource id
    */
    public function getRole($data)
    {
        $response = [];
        $response['success'] = false;
        if ($data) {
            $role = false;
            try {
                if (isset($data['group_code'])) {
                    $role = Role::findFirst([["code" => $data['group_code']]]);
                } elseif (isset($data['id'])) {
                    $role = Role::findFirst([["_id" => $data['id']]]);
                }


                if ($role && $role->_id) {
                    $resources = RoleResource::find([["role_id" => $role->_id]]);


                    if (count($resources) > 0) {
                        $data = $role->getData();
                        $data['resources'] = $this->getResourcesWithId($resources);

                        $response['data'] = $data;
                    } else {
                        $response['data'] = $role->getData();
                    }
                    $response['message'] = "";
                    $response['success'] = true;
                } else {
                    $response['message'] = "No Role found with given group_code";
                }
            } catch (\Exception $exception) {
                $response['message'] = $exception->getMessage();
            }
        } else {
            $response['message'] = "No Group_code found";
        }
        return $response;
    }

    /*
    get all resources of all roles. It return only resource id
    */
    public function getAllRoles($limit = 1, $activePage = 1)
    {
        $response = [];
        $response['success'] = false;
        $response['data'] = [];
        $response['data']['rows'] = [];
        try {
            $roles = Role::find(['limit' => $limit, 'skip' => $activePage]);
            $count = Role::count();

            foreach ($roles as $role) {
                $resources = RoleResource::find([["role_id" => (string)$role->id]]);
                if (count($resources) > 0) {
                    $data = $role->getData();
                    $data['resources'] = $resources->toArray();
                    $data['_id'] = (string)$data['_id'];
                    $data['id'] = (string)$data['_id'];
                    $response['data']['rows'][] = $data;
                } else {
                    $data = $role->getData();
                    $data['_id'] = (string)$data['_id'];
                    $data['id'] = (string)$data['_id'];
                    $response['data']['rows'][] = $data;
                }
            }


            $response['data']['count'] = $count;
            $response['success'] = true;
        } catch (\Exception $exception) {
            $response['message'] = $exception->getMessage();
        }
        return $response;
    }

    public function getAllResources()
    {
        if ($this->_id) {
            return RoleResource::find([["role_id" => $this->_id]]);
        } else {
            return false;
        }
    }

    /*
    get all resources of a role. It return all details of resource like ACTION, MODULE, CONTOLLER
    */
    public function getRoleResources($customData)
    {
        $response = [];
        $response['success'] = false;
        if ($customData) {
            try {
                $role = false;
                if (isset($customData['group_code'])) {
                    $role = Role::findFirst([["code" => $customData['group_code']]]);
                } elseif (isset($customData['id'])) {
                    $role = Role::findFirst([["_id" => $customData['id']]]);
                }
                if ($role && $role->_id) {
                    $decodedToken = $this->di->getRegistry()->getDecodedToken();
                    if ($decodedToken && isset($decodedToken['child_id'])) {
                        $subUser = SubUser::FindFirst([["_id" => $decodedToken['child_id']]])->getResources();
                        $resources = RoleResource::find([["role_id" => $role->id]])->toArray();
                        $data = $role->getData();
                        //$ids = implode(',', $subUser);
                        $resources = Resource::find([["_id" => ['$in' => $subUser]]]);
                        $data['resources'] = $resources->toArray();
                    } else {
                        $resources = RoleResource::find([["role_id" => $role->_id]]);
                        $data = $role->getData();
                        if (count($resources) > 0) {
                            foreach ($resources as $resource) {
                                $resource = $resource->toArray();
                                $res_ids[] = $resource['resource_id'];
                            }
                            $resources = Resource::find([["_id" => ['$in' => $res_ids]]]);
                            $data['resources'] = $resources;
                        } else {
                            if ($role->resources == 'all') {
                                $resources = Resource::find();
                                $data['resources'] = $resources;
                            }
                        }
                    }
                    if (!is_array($data['resources'])) {
                        $data['resources'] = $data['resources']->toArray();
                    }
                    foreach ($data['resources'] as $key => $resource) {
                        $resourceData = $resource;
                        $data['resources'][$key] = $resourceData;
                        if (isset($resourceData['_id']) && is_object($resourceData['_id'])) {

                            $data['resources'][$key]['_id'] = (string)$resourceData['_id'];
                            $data['resources'][$key]['id'] = (string)$resourceData['_id'];
                        }
                    }
                    $response['data'] = $data;
                    $response['message'] = "";
                    $response['success'] = true;
                } else {
                    $response['message'] = "No Role found with given group_code";
                }
            } catch (\Exception $exception) {
                $response['message'] = $exception->getMessage() . $exception->getTraceAsString();
            }
        } else {
            $response['message'] = "No Group Code found";
        }
        return $response;
    }
}
