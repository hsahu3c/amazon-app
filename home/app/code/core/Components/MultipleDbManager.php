<?php

namespace App\Core\Components;

use App\Core\Models\UserDb;

class MultipleDbManager extends Base
{
    public function getDb()
    {
        if ($this->di->getAppCode()->getCurrentApp() != null) {
            return $this->di->getAppCode()->getCurrentApp();
        } else {
            return 'db';
        }
    }

    public function getDefaultDb()
    {
        return $this->di->getConfig()->default_db;
    }

    public function getCurrentDb()
    {
        return $this->di->getConfig()->current_db;
    }
}
