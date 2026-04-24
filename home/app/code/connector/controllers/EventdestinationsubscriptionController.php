<?php

namespace App\Connector\Controllers;

use Symfony\Component\Console\Helper\FormatterHelper;

class EventdestinationsubscriptionController extends \App\Core\Controllers\BaseController
{
    
    public function createappdestinationAction(): void
    {
        if ($this->request->isPost()) {
            $data = $this->request->getPost();
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('cedcommerce', false)
                ->call('event/destination', [], ['data' => $data, 'app_code' => "cedcommerce"], 'POST');
            echo '<pre>';
            print_r($remoteResponse);
            die();
        }
    }


    public function createappsubscriptionAction(): void
    {
        if ($this->request->isPost()) {
            $data = $this->request->getPost();
            $data['type'] = 'app';
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('cedcommerce', false)
            ->call('event/subscription', [], ['data' => $data, 'app_code' => "cedcommerce"], 'POST');
            echo '<pre>';
            print_r($remoteResponse);
            die();
        }
    }

}


