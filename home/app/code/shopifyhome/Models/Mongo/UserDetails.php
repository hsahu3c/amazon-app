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
 * @category    Cedcommerce Shopify Home
 * @package     Shopifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Models\Mongo;

use App\Core\Models\BaseMongo;
#[\AllowDynamicProperties]
class UserDetails extends BaseMongo
{
    /**
     * @return string
     */
    public function getSource()
    {
        $this->table = 'user_details_'.$this->getDi()->getUser()->id;
        $this->setSource($this->table);
        return $this->table;
    }

    public function checkIfExist($filterQuery, $projection=[])
    {
        $queryResponse = $this->getPhpCollection()->findOne($filterQuery, $projection);
        if(is_null($queryResponse) ) return false;
        return true;
    }

    public function updateOrInsert($data, $flag): void
    {
        if($flag){
            $mongoResponse = $this->getPhpCollection()->updateOne($data);
        } else {
            $mongoResponse = $this->getPhpCollection()->insertOne($data);
        }
    }
}