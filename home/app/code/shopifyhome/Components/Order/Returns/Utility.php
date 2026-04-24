<?php
namespace App\Shopifyhome\Components\Order\Returns;
use Exception;
use App\Core\Components\Base;
use App\Core\Models\User\Details;
use App\Connector\Components\ApiClient;

class Utility extends Base {
    public const MARKETPLACE = 'shopify';

    public function getRemoteShopId($shopId)
    {
        $userShops = $this->di->getUser()->getShops();
        if ($userShops) {
            foreach ($userShops as $shop) {
                if ($shop['_id'] == $shopId) {
                    return $shop['remote_shop_id'];
                }
            }
        }

        $remoteShopId = $this->di->getObjectManager()->get(Details::class)->getShop($shopId)['remote_shop_id'];
        if (!$remoteShopId) {
            throw new Exception('Remote shop id not found');
        }
    }

    public function getLineItemId($lineItemId)
    {
        $lineItemId = explode('/', (string) $lineItemId);
        return array_pop($lineItemId);
    }

    public static function matchItems($items, $returnableItems)
    {
        $newReturnableItems = [];
        $marketplaceItemIds = array_column($items, 'marketplace_item_id');
        foreach ($returnableItems as $returnableItem) {
            if (in_array($returnableItem['marketplace_item_id'], $marketplaceItemIds)) {
                $newReturnableItems[] = $returnableItem;
            }
        }

        return $newReturnableItems;
    }

    public function getClient()
    {
        $appCode = $this->di->getAppCode()->get()[self::MARKETPLACE];
        return $this->di->getObjectManager()->get(ApiClient::class)
            ->init(self::MARKETPLACE, true, $appCode);
    }
}