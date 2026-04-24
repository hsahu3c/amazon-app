<?php

namespace App\Connector\Models\Order;

use App\Core\Models\Base;
use Phalcon\Mvc\Model\Message;

class Item extends Base
{
    protected $table = 'order_item';
}
