<?php

namespace App\Connector\Components;

use Phalcon\Events\Event;

class TargetDisconnectEvent extends \App\Core\Components\Base
{
    /**
     * @param $myComponent
     */
    public function beforeLastTargetDisconnect(Event $event, $myComponent, $data): void
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable('product_container');
        $productCollection->deleteMany(
            ['user_id' => $data['userId'], 'shop_id' => $data['sourceShopId'], "app_codes" => ['$size' => 1]]
        );
    }
}
