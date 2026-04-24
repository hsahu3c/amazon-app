<?php

namespace App\Core\Controllers;

use App\Core\Components\Staff\Staff;
use App\Core\Components\Staff\StaffRole;

class StaffController extends BaseController
{

    public function createAction()
    {
        $rawBody = $this->getRequestData();
        $staffObj = $this->di->getObjectManager()->get(Staff::class);
        return $this->prepareResponse($staffObj->createStaff($rawBody));
    }
    public function approveAction()
    {
        $rawBody = $this->getRequestData();
        $staffObj = $this->di->getObjectManager()->get(Staff::class);
        return $this->prepareResponse($staffObj->approveStaff($rawBody));
    }
    public function rejectAction()
    {
        $rawBody = $this->getRequestData();
        $staffObj = $this->di->getObjectManager()->get(Staff::class);
        return $this->prepareResponse($staffObj->rejectStaff($rawBody));
    }
    public function deleteAction()
    {
        $rawBody = $this->getRequestData();
        $staffObj = $this->di->getObjectManager()->get(Staff::class);
        return $this->prepareResponse($staffObj->deleteStaff($rawBody));
    }

    public function createRoleAction()
    {
        $rawBody = $this->getRequestData();
        $staffRoleObj = $this->di->getObjectManager()->get(StaffRole::class);
        return $this->prepareResponse($staffRoleObj->createStaffRole($rawBody));
    }

    public function getStaffsAction()
    {
        $rawBody = $this->getRequestData();
        $staffObj = $this->di->getObjectManager()->get(Staff::class);
        return $this->prepareResponse($staffObj->getStaffs($rawBody));
    }

    public function getUserStaffAccountsAction()
    {
        $rawBody = $this->getRequestData();
        $staffObj = $this->di->getObjectManager()->get(Staff::class);
        return $this->prepareResponse($staffObj->getUserStaffAccounts($rawBody));
    }

}