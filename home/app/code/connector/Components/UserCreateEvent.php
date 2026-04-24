<?php

namespace App\Connector\Components;

use Phalcon\Events\Event;

class UserCreateEvent extends \App\Core\Components\Base
{

    /**
     * @param $myComponent
     */
    public function createAfter(Event $event, $myComponent)
    {
    }
}
