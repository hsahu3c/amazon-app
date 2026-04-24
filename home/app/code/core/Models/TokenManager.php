<?php

namespace App\Core\Models;

class TokenManager extends BaseMongo
{
    protected $table = 'token_manager';
    protected $isGlobal = true;

    public function initialize()
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDefaultDb());
    }
}
