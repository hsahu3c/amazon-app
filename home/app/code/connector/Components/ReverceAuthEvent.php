<?php

namespace App\Connector\Components;

use Phalcon\Events\Event;
use Phalcon\Http\Request;
use App\Core\Models\BaseMongo;
use Exception;

/**
 * Class ReverseAuthEvent
 *
 * Handles events after reverse authentication.
 */


class ReverceAuthEvent extends \App\Core\Components\Base
{
       /**
     * Handle actions after reverse authentication.
     *
     * @param Event $event       The event object.
     * @param mixed $myComponent $this of the component.
     */
    public function afterreverceAuth(Event $event, $myComponent): void{
        $date = date('d-m-Y');
        $logFile = "amazon/afterReverseAuth/{$date}.log";
        try {
            // Fetch user ID from DI container
            $userId = $this->di->getUser()->id;
            $this->di->getLog()->logContent('Installed userID from reverse auth = ' . $userId, 'info', $logFile);
            $mongo = $this->di->getObjectManager()->get(BaseMongo::class);
            $userDetailCollection = $mongo->getCollection("user_details");
            // Update installation source in user details collection
            $userDetailCollection->updateOne(['user_id' => $userId], ['$set' => ['installation_source' => 'amazon']]);

        } catch (Exception $e) {
            $this->di->getLog()->logContent('After account connection, exception message = ' . $e->getMessage() . ', trace = ' . json_encode($e->getTrace()), 'info', $logFile);
        }
    }

}
