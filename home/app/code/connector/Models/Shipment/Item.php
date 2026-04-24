<?php

namespace App\Connector\Models;

use App\Core\Models\Base;
use Phalcon\Mvc\Model\Message;
use Phalcon\Validation\Validator\Uniqueness;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\InclusionIn;
use Phalcon\Validation\Validator\InvalidValue;

class Item extends Base
{
    protected $table = 'shipment_item';

    public $sqlConfig;

    public function initialize(): void
    {
        $this->sqlConfig = $this->di->getObjectManager()->get('\App\Connector\Components\Data');
        $this->di->getRegistry()->getDecodedToken();
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDb());
    }
}
