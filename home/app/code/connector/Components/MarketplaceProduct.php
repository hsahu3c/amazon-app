<?php

namespace App\Connector\Components;


class MarketplaceProduct extends \App\Core\Components\Base
{
    /**
     * Create marketplace specific product table
     * @param $userId
     * @param $marketplace
     * @param array $product
     * @return bool
     */
    public function createMarketplaceProduct($userId, $marketplace, $product = [])
    {
        $this->di->getObjectManager()->get('\App\Connector\Models\MarketplaceProduct')
            ->createMarketplaceTables($userId, $marketplace, $product);
        return true;
    }
}
