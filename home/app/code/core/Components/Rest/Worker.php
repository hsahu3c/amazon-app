<?php

namespace App\Core\Components\Rest;

/**
 * Calling the respective functions from this class's respective component
 */
class Worker extends Base
{
    public function add($queueName)
    {
        $this->di->getObjectManager()->get('App\Core\Components\Worker')->add($queueName);
        return ['success' => true];
    }
    public function addSqs($queueName)
    {
        return $this->di->getObjectManager()->get('\App\Core\Components\Message\Handler\Sqs')->consume($queueName, true);
    }
}
