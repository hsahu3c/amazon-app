<?php

namespace App\Connector\Models;

use App\Core\Models\Base;

class CustomerValueMapping extends Base
{
    protected $table = 'customer_value_mapping';

    public function initialize(): void
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDefaultDb());
    }
}
