<?php
/**
 * CedCommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_Ebay
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Amazon\Components\Product;

use App\Amazon\Components\Common\Helper;
use App\Core\Components\Base as Base;

#[\AllowDynamicProperties]
class Db extends Base
{
    public function init($data = []) {
        if(isset($data['user_id'])){
            $this->_user_id = $data['user_id'];
        }else{
            $this->_user_id = $this->di->getUser()->id;
        }

        $this->_queue_data = $data;

        if ( isset($data['data']['chunk']) ) $this->_chunk = $data['data']['chunk'];

        if ( isset($data['data']['profile_id']) ) {
            $this->_profile = $this->getProfile($data['data']['profile_id']);
        } else $this->_profile = [];

        if ( isset($data['data']['feed_only']) ) {
            $this->_bulk_call = true;
        }

        $this->_baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $this->_collection = $this->_baseMongo->getCollectionForTable(Helper::AMAZON_PRODUCT_CONTAINER);
        return $this;
    }

    public function updateMany($filter, $data, $options)
    {
        return $this->_collection->updateMany($filter,  $data,  $options);
    }
}