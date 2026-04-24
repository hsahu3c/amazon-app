<?php

namespace App\Core\Components\Staff;

use \MongoDB\BSON\ObjectId;
use App\Core\Models\Staff\StaffRole as StaffRoleModel;
use App\Core\Models\Resource;

class StaffRole
{
    public function createStaffRole($data)
    {
        $response = ['success' => false, 'message' => ''];

        if (empty($data['code']) || empty($data['title']) || empty($data['description'])) {
            $response['message'] = 'Required fields: code, title, and description are missing.';
            return $response;
        }

        try {
            $role = new StaffRoleModel();

            $role->setData([
                '_id' => new ObjectId(),
                'code' => $data['code'],
                'title' => $data['title'],
                'description' => $data['description'],
                'resources' => $data['resources'] ?? [],
                'seller_id' => $data['seller_id'] ?? null
            ]);

            if ($role->save()) {
                $response['success'] = true;
                $response['message'] = 'Seller staff role created successfully.';
            } else {
                $messages = $role->getMessages();
                $response['message'] = 'Failed to create seller staff role: ' . implode(', ', $messages);
            }
        } catch (\Exception $e) {
            $response['message'] = 'Error creating seller staff role: ' . $e->getMessage();
        }

        return $response;
    }

    public function generateStaffAcl()
    {
        $roles = StaffRoleModel::find();
        $acl = new \Phalcon\Acl\Adapter\Memory();
        $acl->setDefaultAction(\Phalcon\Acl\Enum::DENY);
        $resources = Resource::find()->toArray();
        $components = [];
        $ids = [];
        foreach ($resources as $resource) {
            $ids[] = $resource['_id'];
            $components[$resource['module'] . '_' . $resource['controller']][] = $resource['action'];
        }
        foreach ($components as $componentCode => $componentResources) {
            $acl->addComponent($componentCode, $componentResources);
        }
        foreach ($roles as $role) {
            $acl->addRole($role->code);
            if ($role->resources == 'all') {
                foreach ($components as $componentCode => $componentResources) {
                    $acl->allow($role->code, $componentCode, '*');
                }
            } else {
                foreach ($role->resources as $roleResource) {
                    $temp = array_search($roleResource, $ids);
                    if ($temp !== false) {
                        $acl->allow(
                            $role->code,
                            $resources[$temp]['module'] . '_' . $resources[$temp]['controller'],
                            $resources[$temp]['action']
                        );
                    }
                }
            }
        }
        $this->di->getCache()->set('staff_acl', $acl, 'setup');
    }
}
