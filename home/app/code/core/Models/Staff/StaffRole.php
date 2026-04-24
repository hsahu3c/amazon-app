<?php

namespace App\Core\Models\Staff;

use App\Core\Models\BaseMongo;

class StaffRole extends BaseMongo
{
    public $_id;
    public $code;
    public $title;
    public $description;
    public $resources = [];
    public $seller_id;

    protected $table = 'staff_roles';

    public function initialize()
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDefaultDb());
    }
}
