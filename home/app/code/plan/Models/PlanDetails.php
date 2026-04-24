<?php

namespace App\Plan\Models;

use App\Plan\Components\Common;
use App\Plan\Models\Plan;
use App\Plan\Models\BaseMongo as BaseMongo;
use Exception;

/**
 * Class Plan
 * @package App\Plan\Models
 */
#[\AllowDynamicProperties]
class PlanDetails extends BaseMongo
{
    public function getPlanInfo($userId = "")
    {
        if ($this->di->getUser()->id !== $userId) {
            throw new Exception('User di not set');
        }
        $planDetails = $this->di->getObjectManager()->get(Plan::class)->getPlanInfo($userId);
        if (!empty($planDetails)) {
            $this->setData($planDetails);
            return $this;
        }
        return null;
    }

    public function setData($data)
    {
        if (count($data)) {
            foreach ($data as $key => $value) {
                $this->$key = $value;
            }
        }
        if (isset($data['_id'])) {
            $this->id = $data['_id'];
        }
        return $this;
    }
}