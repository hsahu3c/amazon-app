<?php

namespace App\Core\Models;

class UserDb extends Base
{
    protected $table = 'user_db';

    public function initialize()
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDefaultDb());
    }
}
