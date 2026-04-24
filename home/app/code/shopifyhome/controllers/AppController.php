<?php

namespace App\Shopifyhome\Controllers;

use App\Core\Controllers\BaseController;
use App\Shopifyhome\Components\App;

class AppController extends BaseController
{
    public function getAction()
    {
        $response = $this->di->getObjectManager()->get(App::class)->get();
        return $this->prepareResponse($response);
    }
}
