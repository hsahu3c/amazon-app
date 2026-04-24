<?php


namespace App\Amazon\Components\Notification;

use App\Core\Components\Base;

class Helper extends Base
{

    public function createSubscription($data)
    {
        return $this->di->getObjectManager()->get(Notification::class)->init($data)
            ->createSubscription();
    }

}