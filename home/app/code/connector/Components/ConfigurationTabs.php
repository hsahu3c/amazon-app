<?php

namespace App\Connector\Components;

class ConfigurationTabs extends \App\Core\Components\Base
{
    /**
    * title index : define tab title
    * code index : use for dynamic function name
    * action index : use for ajax request url
    * child : use for subtab
    */
    public function getTabs($userId = false)
    {
        $connectors = $this->getDi()->getObjectManager()->get('App\Connector\Components\Connectors')->getConnectorsWithFilter(['installed' => 1], $userId);
        $data = [];
        if (isset($this->di->getConfig()->tabs)) {
            if ($this->di->getConfig()->tabs->get('global')) {
                $data['global'] = $this->di->getConfig()->tabs->get('global')->toArray();
            }

            foreach ($connectors as $connector) {
                if ($this->di->getConfig()->tabs->get($connector['code'])) {
                    $data[$connector['code']] = $this->di->getConfig()->tabs->get($connector['code'])->toArray();
                }
            }
        }

        return $data;
    }
}
