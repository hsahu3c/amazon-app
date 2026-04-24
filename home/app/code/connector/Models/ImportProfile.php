<?php

namespace App\Connector\Models;

use App\Core\Models\BaseMongo;



class ImportProfile extends BaseMongo
{
    protected $table = 'import_profile';

    protected $isGlobal = true;
}