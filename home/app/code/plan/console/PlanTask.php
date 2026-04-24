<?php

use App\Plan\Models\Plan as PlanModel;
use Phalcon\Cli\Task;

class PlanTask extends Task
{
    protected $_args = false;

    public function renewPlansAction(): bool
    {
        $planResponse = $this->di->getObjectManager()
            ->get(PlanModel::class)
            ->renewPlanByCron();
        echo 'renew plans'.PHP_EOL;
        print_r($planResponse);
        return true;
    }
}
