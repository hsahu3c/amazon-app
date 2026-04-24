<?php
namespace App\Frontend\Controllers;

use App\Core\Controllers\BaseController;
use App\Frontend\Components\ClickUP\Integration;
use App\Frontend\Components\AdminpanelHelper;
class ClickupController extends BaseController
{
    public function CreateTaskOnClickUPAction(){
        // die('kgh');
        // die($this->di->getConfig()->getClikupAUTH);
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(Integration::class);
        $adminHelper=$this->di->getObjectManager()->get(AdminpanelHelper::class);
        return $this->prepareResponse($helper->CreateTaskOnClickUP($rawBody));
        // return $this->prepareResponse($adminHelper->getReasonUninstall($rawBody));
    }
    
    public function createUserTaskManuallyAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $user_id = $rawBody['user_id'];
        // die(json_encode(['shivam testing']));
        $helper = $this->di->getObjectManager()->get(Integration::class);
        return $this->prepareResponse($helper->CreateTaskOnClickUP([], $user_id));
    }
}