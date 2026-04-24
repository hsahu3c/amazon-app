<?php

namespace App\Core\Controllers;

/**
 * Controller implementation of SetupTask in cli
 * @see ./console/SetupTask 
 */
class SetupController extends BaseController
{
    private $setup;
    public function initialize()
    {
        $this->setup = $this->di->getObjectManager()->get("App\Core\Components\Setup");
    }
    /**
     * Upgrade action of setupTask
     *
     * @param string $params
     * @return void
     */
    public function upgradeAction()
    {
        $error = false;
        try {
            $this->setup->upgradeSchema();
            $this->setup->updateResourcesAction();
            $this->setup->upgradeAcl();
            $result = $this->setup->buildAclAction();
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
        $success = true;
        if ($error) {
            $success = false;
        }
        $response = [
            "success" => $success,
            "error" => $error
        ];
        return $this->prepareResponse($response);
    }
    /**
     * Update Resources
     *
     * @return void
     */
    public function updateResourcesAction()
    {
        $error = false;
        $success = true;
        try {
            $this->setup->updateResourcesAction();
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
        if ($error) {
            $success = false;
        }
        $response = [
            "success" => $success,
            "error" => $error
        ];
        return $this->prepareResponse($response);
    }
    /**
     * Build ACL
     *
     * @return void
     */
    public function buildAclAction()
    {
        $error = false;
        $success = true;
        try {
            $this->setup->buildAclAction();
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
        if ($error) {
            $success = false;
        }
        $response = [
            "success" => $success,
            "error" => $error
        ];
        return $this->prepareResponse($response);
    }
    /**
     * status of modules 
     *
     * @return void
     */
    public function statusAction()
    {
        $error = false;
        $success = true;
        try {
            $this->setup->statusAction();
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
        if ($error) {
            $success = false;
        }
        $response = [
            "success" => $success,
            "error" => $error
        ];
        return $this->prepareResponse($response);
    }
    /**
     * Enable module
     *
     * @param [type] $module
     * @return void
     */
    public function enableAction($module)
    {
        $error = false;
        $success = true;
        try {
            $this->setup->enableAction($module);
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
        if ($error) {
            $success = false;
        }
        $response = [
            "success" => $success,
            "error" => $error
        ];
        return $this->prepareResponse($response);
    }
    /**
     * Disable module
     *
     * @param [type] $module
     * @return void
     */
    public function disableAction($module)
    {
        $error = false;
        $success = true;
        try {
            $this->setup->disableAction($module);
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
        if ($error) {
            $success = false;
        }
        $response = [
            "success" => $success,
            "error" => $error
        ];
        return $this->prepareResponse($response);
    }
    /**
     * Clear notification
     *
     * @return void
     */
    public function clearNotificationsAction()
    {
        $error = false;
        $success = true;
        try {
            $this->setup->clearNotificationsAction();
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
        if ($error) {
            $success = false;
        }
        $response = [
            "success" => $success,
            "error" => $error
        ];
        return $this->prepareResponse($response);
    }
}
