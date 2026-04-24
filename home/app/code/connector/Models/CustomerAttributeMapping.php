<?php

namespace App\Connector\Models;

use App\Core\Models\Base;

class CustomerAttributeMapping extends Base
{
    protected $table = 'customer_mapping';

    public function initialize(): void
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDefaultDb());
    }
}
