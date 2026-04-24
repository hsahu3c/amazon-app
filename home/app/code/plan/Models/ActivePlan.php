<?php
namespace App\Plan\Models;

use App\Core\Models\BaseMongo;

#[\AllowDynamicProperties]
class ActivePlan extends BaseMongo
{
    protected $table = 'active_plan';

    public function onConstruct(): void
    {
        $this->di = $this->getDi();
        $this->setSource($this->table);
        $this->initializeDb($this->getMultipleDbManager()->getDb());
    }

    public function getCurrentPlan()
    {
        $userId = $this->di->getUser()->id;
        $activePlan = ActivePlan::find(
            [
                "user_id='{$userId}'",
                'order' => 'created_at DESC'
            ]
        );
        if (count($activePlan)) {
            $currentPlan = $activePlan[0]->toArray();
            return ['success' => true, 'message' => 'Your current plan', 'data' => $currentPlan];
        }

        return ['success' => false, 'message' => 'Something went wrong', 'code' => 'something_went_wrong'];
    }

    public function getActivePlans(){
        $userId = $this->di->getUser()->id;
        $activePlan = ActivePlan::find(
            [
                "user_id='{$userId}'",
                'order' => 'created_at DESC'
            ]
        );
        if (count($activePlan)) {
            $currentPlan = $activePlan->toArray();
            return ['success' => true, 'message' => 'Your current plans', 'data' => $currentPlan];
        }

        return ['success' => false, 'message' => 'Something went wrong', 'code' => 'something_went_wrong'];
    }
}