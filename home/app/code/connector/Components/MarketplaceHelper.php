<?php

namespace App\Connector\Components;

class MarketplaceHelper extends \App\Core\Components\Base
{

    public function getAttributes($userId = false, $marketplace = '', $shop = '')
    {
        $marketPlace = ucfirst($marketplace);
        $attributes = $this->di->getObjectManager()->get('App\\' . $marketPlace . '\Components\Helper')->getAllAttributes($userId, $shop);
        $baseAttributes = $this->di->getObjectManager()->get('App\Connector\Models\Product')->getProductColumns()['data'];
        $baseAttributes = array_merge($baseAttributes['container_columns'], $baseAttributes['variant_columns']);

        $baseAttr = $this->di->getObjectManager()->get('App\\' . $marketPlace . '\Components\Helper')->getAllBaseAttributes($userId, $baseAttributes);
        $suggestions = $this->di->getObjectManager()->get('App\Connector\Components\Suggestor')->getUploadMappingSuggestions($userId, $attributes, $baseAttr, $marketplace);
        $toReturnAttrs = [
            'base_attr' => $baseAttr,
            'target_attr' => $attributes,
            'suggestions' => $suggestions
        ];
        return $toReturnAttrs;
    }

    public function getProductsForUpload($userId = false, $target = '', $products = [], $mapping = [])
    {
        $marketPlace = ucfirst($target);
        $product = $this->di->getObjectManager()->get('App\\' . $marketPlace . '\Components\Helper')->getProductsForUpload($products, $mapping);
        return $product;
    }

    public function uploadProductstoMarketplace($userId = false, $target = '', $products = [], $shop = '')
    {
        $marketPlace = ucfirst($target);
        $productUploadStatus = $this->di->getObjectManager()->get('App\\' . $marketPlace . '\Components\Helper')->uploadProducts($userId, $products, $shop);
        return $productUploadStatus;
    }
}
