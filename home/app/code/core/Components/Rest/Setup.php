<?php

namespace App\Core\Components\Rest;

use App\Core\Components\Rest\Base;

/**
 * Calling the respective functions from this class's respective component
 */
class Setup extends Base
{
    public function upgrade()
    {
        return $this->component->upgradeAction(false);
    }
    public function updateResources()
    {
        return $this->component->updateResourcesAction();
    }
    public function buildAcl($buildChildAcl = true)
    {
        return $this->component->buildAclAction($buildChildAcl);
    }
    public function status()
    {
        return $this->component->statusAction();
    }
    public function enable($module)
    {
        return $this->component->enableAction($module);
    }
    public function disable($module)
    {
        return $this->component->disableAction($module);
    }
    public function clearNotifications()
    {
        return $this->component->clearNotificationsAction();
    }
}
