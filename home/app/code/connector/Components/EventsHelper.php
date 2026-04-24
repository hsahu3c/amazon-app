<?php

namespace App\Connector\Components;

class EventsHelper 
{
    public function createActivityLog($actionType,$action,$payload): void{
    $eventManager = $this->di->getEventsManager();
    $eventManager->fire('application:createActivityLog', $this, ["action_type"=>$actionType,"action"=>$action,"payload"=>json_encode($payload)]);
    }
}