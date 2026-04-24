<?php

namespace App\Core\Models;

class UserProxy extends Base
{
    protected $table = 'user';

    public function initialize()
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getCurrentDb());
    }
}
