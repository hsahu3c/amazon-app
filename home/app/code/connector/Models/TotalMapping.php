<?php

namespace App\Connector\Models;

use App\Core\Models\Base;

class TotalMapping extends Base
{
    protected $table = 'total_mapping';

    public function initialize(): void
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDefaultDb());
    }
}
