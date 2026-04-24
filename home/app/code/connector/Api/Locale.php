<?php

namespace App\Connector\Api;

use App\Connector\Components\LocaleManager;

class Locale extends \Phalcon\Di\Injectable
{

    public function updateLocale($data)
    {
        return $this->di->getObjectManager()->get(LocaleManager::class)->updateLocale($data);
    }
}
