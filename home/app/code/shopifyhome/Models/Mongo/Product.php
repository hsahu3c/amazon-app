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
 * @package     Ced_Shopifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Models\Mongo;

use App\Core\Models\BaseMongo;
#[\AllowDynamicProperties]
class Product extends BaseMongo
{
    /**
     * @return string
     */
    public function getSource()
    {
        $this->table = 'product_container_'.$this->getDi()->getUser()->id;
        $this->setSource($this->table);
        return $this->table;
    }

    public function filterByKey($filterData = [])
    {
        return $this->getCollection()->find($filterData)->toArray();
    }

}


