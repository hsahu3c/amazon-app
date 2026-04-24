<?php

namespace App\Core\Controllers;

use App\Core\Models\Acl\Role;

class AclGroupController extends BaseController
{

    public function createAction()
    {
        $role = new Role();
        $requestData = $this->getRequestData();
        $response = $role->createRole($requestData);
        return $this->prepareResponse($response);
    }

    public function updateAction()
    {
        $role = new Role();
        $requestData = $this->getRequestData();
        $response = $role->updateRole($requestData);
        if ($response['success']) {
            $this->di->getObjectManager()->get('App\Core\Components\Setup')->buildAclAction(false);
            $this->di->getCache()->flushByType('setup');
        }
        return $this->prepareResponse($response);
    }

    public function deleteAction()
    {
        $role = new Role();
        $requestData = $this->getRequestData();
        $response = $role->deleteRole($requestData);
        return $this->prepareResponse($response);
    }

    public function getAction()
    {
        $role = new Role();
        $requestData = $this->getRequestData();
        $response = $role->getRole($requestData);
        return $this->prepareResponse($response);
    }

    public function getRoleResourceAction()
    {
        $role = new Role();
        $requestData = $this->getRequestData();
        $response = $role->getRoleResources($requestData);
        return $this->prepareResponse($response);
    }


    public function getAllAction()
    {
        $role = new Role();
        $pageSettings = $this->getRequestData();
        $response = $role
            ->getAllRoles(
                $pageSettings['count'],
                ($pageSettings['count'] * $pageSettings['activePage']) - $pageSettings['count']
            );
        return $this->prepareResponse($response);
    }
}
