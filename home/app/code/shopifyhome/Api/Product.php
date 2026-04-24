<?php

namespace App\Shopifyhome\Api;

use App\Shopifyhome\Models\SourceModel;
/**
 * Main class file to manage Products.
 *
 * @since 1.0.0
 */
class Product extends Base {

    /**
     * Set component name will be used to map the Component in the etc/rest/webapi.php endpoints callback.
     *
     * @var string
     * @since 1.0.0
     */
    protected $_component = 'Product';

    /**
     * Will get Attributes Options
     *
     * @param array $params containing parameters from frontend
     * @return void
     */
    public function getAttributesOptions( $params = [] ) {
        return [
            'success' => true,
            'code'    => '',
            'message' => '',
            'data'    => $this->di->getObjectManager()->get(SourceModel::class)->getAttributesOptions( 'shopify' )
        ];
    }

    /**
     * Will get Title Rule data
     *
     * @param array $params containing parameters from frontend
     * @return void
     */
    public function getTitleRule( $params = [] ) {
        return [
            'success' => true,
            'data'    => $this->di->getObjectManager()->get(SourceModel::class)->getTitleRuleGroup()
        ];
    }
}