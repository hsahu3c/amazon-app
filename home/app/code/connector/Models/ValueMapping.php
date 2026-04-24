<?php

namespace App\Connector\Models;

use App\Core\Models\Base;

class ValueMapping extends Base
{
    protected $table = 'value_mapping';

    public function initialize(): void
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDefaultDb());
    }
}
