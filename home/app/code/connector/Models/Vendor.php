<?php

namespace App\Connector\Models;

use App\Core\Models\Base;

class Vendor extends Base
{
    protected $table = 'vendor';

    public function initialize(): void
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDefaultDb());
    }

    public function getvendors($data)
    {
        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);

            if ($connectorHelper) {
                return $connectorHelper->getsellers($data);
            }
        }
    }
}
