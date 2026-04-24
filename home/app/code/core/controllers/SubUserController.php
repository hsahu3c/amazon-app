<?php

namespace App\Core\Controllers;

class SubUserController extends BaseController
{

    public function createAction()
    {
        $requestData = $this->getRequestData();
        $user = new \App\Core\Models\User\SubUser;
        return $this->prepareResponse($user->createUser($requestData));
    }

    public function loginAction()
    {
        if (!$this->request->isPost())
            return $this->prepareResponse([
                "success" => false,
                "code" => "incorrect_method",
                "message" =>  $this->di->get('isDev') ? 'Send data at post request :-(' : 'Fill All Required Fields..'
            ]);
        $user = new \App\Core\Models\User\SubUser;
        $rawBody = $this->getRequestData();
        // bypass account bans if isdev flag on
        if (!$this->di->get('isDev')) {
            $resp = $this->di->getObjectManager()
                ->get("\App\Core\Components\Account")
                ->handleIfBlocked($rawBody, true);
            if (isset($resp["success"]) && !$resp["success"])
                return $this->prepareResponse($resp);
        }
        return $this->prepareResponse($user->login($rawBody));
    }

    public function deleteAction()
    {
        $user = new \App\Core\Models\User\SubUser;
        $requestData = $this->getRequestData();
        return $this->prepareResponse($user->deleteSubUser($requestData));
    }

    public function getSubUsersAction()
    {
        $user = new \App\Core\Models\User\SubUser;
        $pageSettings = $this->getRequestData();
        if (isset($pageSettings['count']) && isset($pageSettings['activePage'])) {
            if (isset($pageSettings['filter']) || isset($pageSettings['search'])) {
                return $this->prepareResponse($user->getSubUser(
                    $pageSettings,
                    $pageSettings['count'],
                    $pageSettings['activePage'],
                    $pageSettings['filter'] ?? $pageSettings['search']
                ));
            } else {
                return $this->prepareResponse($user->getSubUser(
                    $pageSettings,
                    $pageSettings['count'],
                    $pageSettings['activePage']
                ));
            }
        } else {
            return $this->prepareResponse($user->getSubUser($pageSettings));
        }
    }

    /*
    For Customer Api
    Update the customer accept the array in key value form
    */
    public function updateAction()
    {
        $requestData = $this->getRequestData();
        $user = new \App\Core\Models\User\SubUser;
        $response = $user->updateSubUser($requestData);
        if ($response['success']) {
            $this->di->getObjectManager()
                ->get('App\Core\Components\Setup')
                ->buildChildAclById($requestData["id"]);
            $this->di->getCache()->flushByType('setup');
        }
        return $this->prepareResponse($response);
    }

    public function updateAppAction()
    {
        $requestData = $this->getRequestData();
        $user = new \App\Core\Models\User\SubUser;
        return $this->prepareResponse($user->updateSubUserApp($requestData));
    }
}
