<?php

namespace App\Connector\Models;

use App\Core\Models\Base;

class ProfileProduct extends Base
{
    protected $table = 'profile_product';

    public function initialize(): void
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDb());

        $this->belongsTo(
            'profile_id',
            'App\Connector\Models\Profile',
            'id',
            [
                'alias' => 'profile'
            ]
        );
    }
}
